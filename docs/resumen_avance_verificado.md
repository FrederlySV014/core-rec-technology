# Resumen de Avance Verificado del Software

**Fecha de Revisión:** 17 de Octubre de 2026 (Zona Horaria Local: UTC-5)  
**Proyecto:** Sistema Web de Gestión Comercial Multiempresa (Rec Technology)  
**Entorno de Comprobación:** Monolito modular con base central y bases comerciales independientes en PostgreSQL 16.

---

## 1. Componentes y Funcionalidades Implementadas

### A. Administración Central e Identidad Global (IAM)

- **Identidad Centralizada:** Modelo `User` único por persona; las participaciones en empresas se modelan mediante membresías independientes (`CompanyMembership`) sin duplicar cuentas.
- **Autenticación (Laravel Fortify):** Inicio y cierre de sesión, confirmación de contraseña, verificación de correo electrónico vía enlaces firmados, revocación remota de sesiones concurrentes y autenticación de dos factores (2FA) mediante TOTP y códigos de recuperación monouso.
- **Autorización Segregada:**
    - Roles de plataforma (`PlatformRole`): Gestión operativa de la administración central (`/platform/*`).
    - Roles empresariales (`CompanyRole`): Aislados estrictamente por `company_id`.
    - Claves foráneas compuestas en PostgreSQL (`membership_roles`) que impiden a nivel de motor asignar roles de una empresa a miembros de otra.
- **Titularidad de Cuenta (`is_owner`):** Transferencia atómica de titularidad de la empresa en la plataforma mediante solicitud monouso con vigencia de 48 horas, reautenticación con contraseña y confirmación 2FA.
- **Licenciamiento:** Control de vigencia temporal, intervalos semiabiertos, suspensión administrativa y periodo de gracia que conmuta el acceso comercial a solo lectura (`isReadOnly = true`), bloqueando mutaciones (HTTP 403).

### B. Aprovisionamiento Automatizado de Bases Comerciales (`TenantProvisioningService`)

- **Aislamiento por Base de Datos:** Creación de una base de datos PostgreSQL física e independiente por cada empresa (`rec_tenant_{company_id}`).
- **Segregación de Credenciales:** Generación de dos usuarios técnicos en PostgreSQL:
    - `rec_mig_{id}`: Privilegios de esquema (DDL) para migraciones.
    - `rec_app_{id}`: Privilegios estrictos de manipulación de datos (DML) para la aplicación.
- **Cifrado en Reposo:** Credenciales protegidas mediante `TenantCredentialEncrypter` y el cast `EncryptedTenantCredential`.
- **Seguridad y Control de Acceso (DAC):** `REVOKE CONNECT ON DATABASE FROM PUBLIC`, inyección de la tabla técnica `tenant_identity` con bloqueo contra borrado y exclusión mutua mediante advisory lock de PostgreSQL.
- **Comando Operativo:** `php artisan tenant:provision {slug} [--retry] [--force]`.

### C. Conexión Dinámica y Contexto por Solicitud (`TenantConnectionManager`)

- **Resolución por Ruta:** Prefijo `/app/{company:slug}` procesado por los middlewares `ResolveCompanyContext` y `EstablishTenantConnection` (`TerminableMiddleware`).
- **Conexión No Privilegiada:** Apertura dinámica de la conexión `'tenant'` utilizando exclusivamente el usuario DML (`rec_app_{id}`).
- **Verificación de Identidad Física:** Comprobación obligatoria de `tenant_identity` en PostgreSQL antes de habilitar operaciones.
- **Limpieza de Conexión:** Desconexión, purga de Laravel y blanqueo de credenciales en memoria tanto en bloques `finally` como en el método `terminate()`.
- **Protección Cruzada de Modelos:** `TenantModel` y `TenantBuilder` rastrean inmutablemente el `$boundCompanyId` y arrojan `TenantContextException` ante cualquier intento de ejecutar consultas o mutaciones fuera del tenant de origen.

### D. Catálogo Básico de Productos (ERP Comercial)

- **Entidades Comerciales:** Categorías (`categories`), unidades de medida internas (`units_of_measure`, código normalizado en mayúsculas, control de decimales) y productos simples (`products`, SKU único por empresa, nombre, descripción y código de barras opcional con índice parcial único).
- **Migraciones Comerciales:** Migraciones dinámicas aplicadas mediante `php artisan tenant:migrate {company}` con usuario DDL `rec_mig_{id}`, preservando registros existentes y asegurando privilegios mínimos DML para la aplicación.
- **Permisos Explícitos:** Separación estricta entre `inventory.products.view`, `inventory.products.manage`, `inventory.categories.manage` e `inventory.units.manage`.
- **Interfaz SPA (Inertia v3 / React 19):** Pantalla en `/app/{company:slug}/catalog` con pestañas dinámicas, modales accesibles de creación/edición, filtros reactivos por texto y estado, advertencia contextual explicativa al conservar categorías inactivas históricas y visualización restringida en modo solo lectura para perfiles de auditoría.

---

## 2. Tecnologías y Versiones Verificadas

- **Lenguaje y Entorno:** PHP 8.5 (Runtime real) / Laravel Framework 13.35.0 (composer.json: `^13.17`).
- **Autenticación:** Laravel Fortify 1.41.0.
- **Adaptadores SPA:** Inertia Laravel 3.5.1 / Inertia React 3.8.0 / Inertia Vite 3.8.0.
- **Ruteo Tipado:** Laravel Wayfinder 0.1.21 (`@laravel/vite-plugin-wayfinder` 0.1.10).
- **Frontend:** React 19.3.0 / React DOM 19.3.0 / TypeScript 5.9.3 / TailwindCSS 4.3.3 / Vite 8.3.3 (Vite Plus 0.3.0).
- **Motor de Base de Datos:** PostgreSQL 16 (puerto 5434 en entorno de desarrollo).
- **Herramientas de Análisis y Calidad:**
    - Pest PHP 5.3.1 (`pestphp/pest-plugin-laravel` 5.0.1).
    - Larastan / PHPStan 3.13.0 (**Nivel 7** configurado en `phpstan.neon`, 0 errores).
    - Laravel Pint 1.32.1 (código 0).
    - Formateador / Linter Frontend: Vite Plus (`vp check`, 80 archivos analizados, 0 errores).

---

## 3. Evidencias Principales

### A. Pruebas Automatizadas Verificadas

1. **Suite de Catálogo y Conexión Multiempresa:**  
   `vendor/bin/pest tests/Feature/Tenant/ProductCatalogTest.php`  
   _Fecha de Ejecución:_ 17/10/2026 ~04:36 UTC / ~23:36 local.  
   _Resultado:_ **18 pruebas aprobadas, 107 aserciones, 0 fallos** (duración: 44.1s).  
   _Acredita:_ Mismo SKU permitido en empresas A y B sin colisión, mismos IDs en bases separadas, secuencia $A \rightarrow B \rightarrow A$ aislada, protección de modelos con `TenantBuilder`, y `tenant:migrate` con mínimo privilegio DML/DDL.
2. **Suite de Integridad Central y Claves Compuestas:**  
   `vendor/bin/pest tests/Feature/Central/CentralSchemaIntegrityTest.php`  
   _Fecha de Ejecución:_ 17/10/2026 10:06 local.  
   _Resultado:_ **3 pruebas aprobadas, 3 aserciones, 0 fallos** (duración: 6.5s).  
   _Acredita:_ Clave foránea compuesta en `membership_roles` y unicidad fiscal en `companies`.
3. **Suite de Seguridad de Cuentas y Sesiones:**  
   `vendor/bin/pest tests/Feature/Central/PersonalAccountSecurityTest.php`  
   _Fecha de Ejecución:_ 17/10/2026 10:07 local.  
   _Resultado:_ **5 pruebas aprobadas, 41 aserciones, 0 fallos** (duración: 6.6s).  
   _Acredita:_ Cambio de contraseña, confirmación de correo y revocación de sesiones concurrentes.
4. **Pruebas Históricas de Conexión (Fase 6):**  
   `tests/Feature/Tenant/TenantConnectionContextTest.php` (**14 pruebas, 76 aserciones, 0 fallos**).
5. **Pruebas Históricas de IAM Central:**  
   Suite inicial de autenticación y roles (**114 pruebas, 477 aserciones, 0 fallos** al 15/10/2026).

### B. Evidencias en Navegador (Grabaciones WebP y Capturas)

- `verify_503_fix_1792247801617.webp`: Acceso de solo lectura y carga limpia de `/app/andina-store/catalog` con código HTTP 200.
- `catalog_e2e_verification_1792210009027.webp`: Creación de categoría, unidad de medida y producto simple con sesión 2FA.
- `catalog_edit_status_readonly_1792211012875.webp`: Edición, desactivación explícita, persistencia con categoría inactiva y advertencia visual.
- `catalog_readonly_verification_1792211302010.webp`: Comprobación de que el rol auditor no visualiza botones de creación ni acciones de mutación.
- Capturas: `catalog_page_success_1792247844812.png`, `catalog_view_auditor_1792211398232.png`, `categories_tab_auditor_1792211421312.png`, `units_tab_auditor_1792211435547.png`.

---

## 4. Limitaciones y Verificaciones Pendientes

1. **Códigos de Recuperación 2FA:** Los códigos de respaldo generados son de un solo uso (_burnable_); una vez empleados en pruebas con navegador quedan consumidos e invalidados en la base central.
2. **Propagación de Permisos en Empresas Existentes:** Las actualizaciones en las plantillas maestras (`role_templates`) aplican automáticamente a nuevas empresas creadas con posterioridad. En empresas existentes, los roles materializados en `company_roles` deben actualizarse de manera explícita desde la interfaz de roles o mediante scripts controlados.
3. **Agrupamiento de Conexiones:** En el entorno actual, cada solicitud al espacio empresarial abre y cierra una conexión física a PostgreSQL. El agrupamiento de conexiones (_connection pooling_ con herramientas como PgBouncer) no ha sido configurado.

---

## 5. Funcionalidades del Alcance General Aún No Desarrolladas

Las siguientes áreas funcionales cuentan con identificadores y taxonomía de permisos registrados en la base central, pero **no poseen modelos, tablas, controladores ni interfaces construidas**:

- **Gestión de Existencias y Almacenes:** Almacenes físicos, stock actual, movimientos de entrada/salida y kardex valorizado.
- **Precios y Variantes:** Listas de precios por tipo de cliente, monedas, variantes de producto (talla, color) e impuestos/IGV.
- **Imágenes de Producto:** Carga, almacenamiento en disco/S3 y optimización de imágenes para el catálogo.
- **Compras y Proveedores:** Órdenes de compra, recepción de mercadería y gestión de cuentas por pagar.
- **Ventas y Facturación Electrónica:** Cotizaciones, pedidos de venta, generación de comprobantes de pago XML (UBL 2.1) y firma digital con SUNAT.
- **Tienda en Línea Pública:** Catálogo web para clientes finales, carrito de compras y pasarelas de pago externas.
