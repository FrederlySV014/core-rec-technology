# Documento de Arquitectura y Diseño Inicial (Corregido y Consolidado)

**Sistema SaaS de Gestión Comercial Multiempresa (ERP E-commerce)**  
**Tecnología:** Laravel 13 & PostgreSQL 16  
**Fase:** Fase 1 (Cerrada) y Fase 2 (Implementada)

---

## 1. Resumen de Decisiones y Correcciones de Arquitectura

1. **Monolito Modular con Aislamiento de Base de Datos**:
    - Contexto central (`central`): Administra el SaaS, empresas, miembros, licencias, catálogo de módulos y credenciales protegidas de conexión.
    - Contexto comercial (`tenant`): Código y esquema compartidos entre empresas, pero cada empresa dispone de su base de datos comercial aislada.
    - Sin claves foráneas físicas entre bases de datos independientes.
2. **Identidad y Acceso (IAM) Separado y Tipado**:
    - Se conserva `App\Models\User` con su clave primaria `id` (bigint auto-incremental) y la configuración de Laravel Fortify (2FA).
    - Se sustituye el booleano administrativo por **Roles y Permisos de Plataforma** (`platform_roles`, `platform_user_roles`, `permissions` con `scope = 'platform'`).
    - No existe bypass global para administradores de plataforma: administrar el SaaS no otorga acceso a los datos comerciales de los clientes.
    - Los roles comerciales (`company_roles`) pertenecen estrictamente a una empresa (`company_id`). Las plantillas (`role_templates`) sirven para clonar configuraciones estándar, pero nunca se asignan directamente a miembros.
    - **Garantía física en PostgreSQL contra asignaciones cruzadas**: La tabla `membership_roles` utiliza claves foráneas compuestas:
        - `FOREIGN KEY (company_id, membership_id) REFERENCES memberships(company_id, id)`
        - `FOREIGN KEY (company_id, company_role_id) REFERENCES company_roles(company_id, id)`
          Esto impide físicamente que un usuario de la Empresa A reciba un rol de la Empresa B.
3. **Licenciamiento y Gracia**:
    - Separación entre **estado administrativo** (`draft`, `active`, `suspended`, `cancelled`) y **vigencia temporal calculada por fechas** (`starts_at`, `expires_at`, `grace_period_days`).
    - Intervalos semiabiertos $[starts\_at, effective\_end)$ donde $effective\_end = expires\_at + grace\_period\_days$ (o $\infty$ si `expires_at` es nulo para licencias perpetuas).
    - Prohibición absoluta de superposiciones de licencias activas/suspendidas para la misma empresa.
    - **Periodo de gracia**: Concede únicamente acceso de lectura comercial autorizado (`isReadOnly = true`), preservando la facultad de renovar.
    - **Renovación durante la gracia**: Se ajusta la cobertura del periodo de gracia de la licencia anterior para que termine exactamente cuando inicia la renovación, conservando la trazabilidad histórica de ambas licencias sin superposición.
    - Deshabilitar un módulo en la licencia suspende el derecho de uso sin destruir datos ni asignaciones de permisos en el ERP.
4. **Protección de Conexiones Empresariales**:
    - Se segregan credenciales:
        - Operación diaria (`app_username`, `app_password`): privilegios mínimos (DML).
        - Mantenimiento y migración (`migration_username`, `migration_password`): privilegios DDL.
        - Aprovisionamiento inicial (`provisioner_username`, `provisioner_password`).
    - Cifrado en reposo mediante `TenantCredentialEncrypter` con clave dedicada fuera del repositorio (`TENANT_DB_ENCRYPTION_KEY`), desacoplada de `APP_KEY`. Las contraseñas quedan ocultas de la serialización del modelo.
5. **Separación de Migraciones**:
    - Migraciones centrales: en `database/migrations/` con conexión explícita `protected $connection = 'central';`.
    - Migraciones comerciales (ERP): en `database/migrations/tenant/` con conexión `tenant`.
    - Las migraciones centrales jamás se ejecutan en las bases de datos empresariales.
6. **Desacoplamiento con Tiendas Web**:
    - Los compradores finales de los e-commerce residen en sus propias tablas/aplicaciones; no forman parte de la tabla central `users`.
