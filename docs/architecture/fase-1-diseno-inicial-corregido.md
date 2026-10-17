# Documento de Arquitectura y Diseño Inicial (Consolidado)

**Sistema Web de Gestión Comercial Multiempresa**  
**Tecnología Base:** Laravel 13 (v13.35.0) & PostgreSQL 16  
**Estado:** Fundamentos de arquitectura implementados y alineados con las Fases 1 a 7.

---

## 1. Resumen de Decisiones y Realidad de Implementación

1. **Monolito Modular con Aislamiento por Base de Datos Independiente**:
    - **Contexto Central (`central`):** Gestiona la administración central del sistema, empresas registradas, usuarios globales, membresías, licencias, catálogo maestro de módulos y configuración protegida de conexiones.
    - **Contexto Comercial (`tenant`):** Cada empresa cliente dispone de su propia base de datos PostgreSQL física independiente (`rec_tenant_{company_id}`). No se utiliza particionamiento por esquemas (`schemas`) para el aislamiento multiempresa.
    - **Restricción Física:** No existen claves foráneas físicas entre la base de datos central y las bases comerciales independientes.

2. **Identidad y Acceso (IAM) Centralizado**:
    - Se utiliza `App\Models\User` para la identidad global de la persona, con clave primaria `id` (bigint auto-incremental) y Laravel Fortify (v1.41.0).
    - **Roles de Plataforma:** Administran la operación central del sistema (`PlatformRole`, `platform_user_roles`, permisos con `scope = 'platform'`). No otorgan facultades implícitas sobre los datos comerciales de las empresas.
    - **Roles Empresariales:** (`CompanyRole`) Pertenecen estrictamente a una empresa (`company_id`). Las plantillas maestras (`RoleTemplate`) permiten clonar configuraciones estándar a empresas nuevas, pero no se asignan directamente a los miembros ni actualizan retroactivamente los roles de empresas ya creadas.
    - **Garantía Física en PostgreSQL contra Asignaciones Cruzadas:** La tabla `membership_roles` en la base central impone claves foráneas compuestas:
        - `FOREIGN KEY (company_id, membership_id) REFERENCES memberships(company_id, id)`
        - `FOREIGN KEY (company_id, company_role_id) REFERENCES company_roles(company_id, id)`
          Esto impide a nivel de motor que una membresía de la Empresa A reciba un rol de la Empresa B.

3. **Licenciamiento y Régimen de Vigencia**:
    - Separación estricta entre **estado administrativo** (`draft`, `active`, `suspended`, `cancelled`) y **vigencia temporal calculada** (`starts_at`, `expires_at`, `grace_period_days`).
    - Intervalo de vigencia semiabierto $[starts\_at, effective\_end)$, donde $effective\_end = expires\_at + grace\_period\_days$.
    - **Periodo de Gracia:** Ante el vencimiento del plazo ordinario (`expires_at`), la licencia entra en periodo de gracia, permitiendo exclusivamente lectura comercial (`isReadOnly = true`, denegando mutaciones con HTTP 403) y facultando la renovación.
    - **Suspensión Administrativa:** Bloquea de forma inmediata el acceso comercial independientemente de la fecha de caducidad.
    - **Módulos:** La deshabilitación de un módulo en la licencia suspende el derecho de uso en tiempo de ejecución sin alterar datos ni destruir asignaciones de permisos en la empresa.

4. **Protección de Conexiones Empresariales y Credenciales Segregadas**:
    - **Operación Diaria del Espacio Empresarial:** Usuario de aplicación `rec_app_{id}` con privilegios mínimos (exclusivamente DML: `SELECT`, `INSERT`, `UPDATE`, `DELETE`).
    - **Mantenimiento y Migraciones:** Usuario de migración `rec_mig_{id}` con privilegios de esquema (DDL).
    - **Aprovisionamiento Inicial:** Conexión administrativa del clúster PostgreSQL utilizada únicamente durante el aprovisionamiento.
    - **Cifrado en Reposo:** Credenciales almacenadas cifradas en la base central mediante `TenantCredentialEncrypter` ([TenantCredentialEncrypter.php](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/app/Modules/Provisioning/Services/TenantCredentialEncrypter.php)) y el cast `EncryptedTenantCredential`. Las contraseñas se ocultan de la serialización del modelo `CompanyDatabaseConnection`.

5. **Separación de Migraciones y Conexión de Ejecución**:
    - **Migraciones Centrales:** Ubicadas en `database/migrations/` con conexión explícita `protected $connection = 'central';`.
    - **Migraciones Comerciales:** Ubicadas en `database/migrations/tenant/`. No fijan la propiedad `$connection = 'tenant'` en su código de clase; se ejecutan dinámicamente mediante el comando `php artisan tenant:migrate {company}` a través de la conexión temporal del runner (`tenant_migration_runner`), utilizando las credenciales del usuario DDL `rec_mig_{id}`.
    - Las migraciones centrales jamás se aplican sobre bases comerciales.

6. **Protección del Contexto Comercial en Modelos y Consultas**:
    - Todas las entidades comerciales extienden `TenantModel` ([TenantModel.php](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/app/Modules/Tenancy/Models/TenantModel.php)).
    - El modelo y su query builder (`TenantBuilder`) vinculan el identificador empresarial (`$boundCompanyId`) al instanciarse o hidratarse.
    - Cualquier intento de invocar `save()`, `delete()`, `get()`, `update()` o `first()` bajo un contexto activo que no coincida con la empresa de origen del modelo es rechazado de inmediato mediante una excepción `TenantContextException`.

7. **Desacoplamiento con Tiendas Web Externas**:
    - Los compradores de tiendas públicas e-commerce no forman parte de la tabla central `users`. Su autenticación y carritos operan de manera desacoplada.

---

## 2. Tecnologías y Versiones Comprobadas en el Entorno

| Componente             | Declarado en Manifiesto | Versión Real Instalada | Herramienta de Comprobación               |
| :--------------------- | :---------------------- | :--------------------- | :---------------------------------------- |
| **PHP Runtime**        | `^8.3`                  | **PHP 8.5**            | `php -v`                                  |
| **Laravel Framework**  | `^13.17`                | **13.35.0**            | `composer show laravel/framework`         |
| **Laravel Fortify**    | `^1.37.2`               | **1.41.0**             | `composer show laravel/fortify`           |
| **Inertia Laravel**    | `^3.0`                  | **3.5.1**              | `composer show inertiajs/inertia-laravel` |
| **Laravel Wayfinder**  | `^0.1.14`               | **0.1.21**             | `composer show laravel/wayfinder`         |
| **PostgreSQL**         | PostgreSQL 16           | **PostgreSQL 16**      | Conexión PDO puerto local 5434            |
| **React**              | `^19.2.0`               | **19.3.0**             | `pnpm list react`                         |
| **Inertia React**      | `^3.0.0`                | **3.8.0**              | `pnpm list @inertiajs/react`              |
| **TailwindCSS**        | `^4.0.0`                | **4.3.3**              | `pnpm list tailwindcss`                   |
| **TypeScript**         | `^5.7.2`                | **5.9.3**              | `pnpm list typescript`                    |
| **Vite**               | `^8.0.0`                | **8.3.3**              | `pnpm list vite`                          |
| **PHPStan (Larastan)** | `^3.9`                  | **3.13.0** (Nivel 7)   | `phpstan.neon` (`level: 7`)               |
