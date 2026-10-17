# Fase 6: Contexto y Conexión Empresarial por Solicitud

## 1. Resumen de Arquitectura y Ciclo de Vida

La **Fase 6** establece la frontera de aislamiento comercial y el ciclo de vida de conexión dinámica por solicitud HTTP y comando en el monolito modular de **Rec Technology** (Laravel 13 y PostgreSQL 16).

El sistema desacopla estrictamente la **resolución de contexto empresarial** de la **apertura de la base de datos comercial**:

- **Resolución Centralizada:** La identificación de una empresa (`/app/{company:slug}`) opera exclusivamente sobre la base central. Las rutas de gestión central de miembros, invitaciones, roles y licencias no abren conexiones a la base de datos comercial.
- **Conexión Dinámica y Segura:** Solo las rutas que ejecutan operaciones comerciales explícitas establecen la conexión con la base comercial mediante el middleware `tenant.connection`.
- **Credenciales Segregadas:** La conexión de aplicación utiliza exclusivamente las credenciales DML (`rec_app_{id}`). Las credenciales de migración (`rec_mig_{id}`) y de administración nunca se emplean durante las solicitudes de los usuarios.
- **Limpieza en Terminación:** Al concluir la solicitud (con éxito o ante excepciones), el middleware `EstablishTenantConnection` (`TerminableMiddleware`) purga la conexión `tenant` y limpia las credenciales de la memoria de configuración.

---

## 2. Componentes Implementados

### 2.1 Módulo `App\Modules\Tenancy`

- **`TenantConnectionManager` (`app/Modules/Tenancy/Services/TenantConnectionManager.php`)**:
    - Servicio singleton encargado de la configuración, apertura, verificación y cierre de la conexión `tenant`.
    - Desencripta las credenciales de aplicación (`app_username`, `app_password`) almacenadas en `company_database_connections` mediante `TenantCredentialEncrypter`.
    - Verifica físicamente en PostgreSQL que la tabla técnica `tenant_identity` exista en la base comercial y que su `company_id` coincida con la empresa del contexto activo.
    - Impide cambios de contexto empresarial en caliente durante una misma operación comercial.
    - Expone `runInTenantContext(Company $company, callable $callback)` para operaciones programadas y comandos CLI.
    - Garantiza en `disconnect()` la ejecución de `DB::disconnect('tenant')`, `DB::purge('tenant')` y el borrado de parámetros sensibles en `config(['database.connections.tenant.*' => ''])`.

- **Modelos y Consultas Comerciales Protegidas**:
    - **`TenantModel` (`app/Modules/Tenancy/Models/TenantModel.php`)**: Clase abstracta base para entidades del espacio empresarial. Define `$connection = 'tenant'`. Registra de forma inmutable el `$boundCompanyId` al momento de instanciar o hidratar registros (`newFromBuilder`).
    - **Global Scope y Validación de Mutación:** Incorpora el global scope `assert_tenant_context` en `booted()`. En `save()` y `delete()`, ejecuta `assertMatchingTenantContext()` para comprobar que el modelo pertenezca a la empresa activa antes de persistir cambios.
    - **`TenantBuilder` (`app/Modules/Tenancy/Models/TenantBuilder.php`)**: Query Builder Eloquent especializado retornado por `newEloquentBuilder($query)` en `TenantModel`. Registra el `$boundCompanyId` al crearse y valida en `get()`, `update()`, `delete()` y `first()` que la consulta no se ejecute fuera de la empresa en la que fue instanciada, arrojando `TenantContextException` ante cualquier discrepancia.
    - **`TenantIdentity` (`app/Modules/Tenancy/Models/TenantIdentity.php`)**: Mapea la tabla técnica `tenant_identity` dentro de cada base comercial (`company_id`, `tax_id`, `schema_version`, `provisioned_at`).

- **Jerarquía de Excepciones Sanitizadas (`app/Modules/Tenancy/Exceptions/`)**:
    - `TenantException`: Excepción base, renderable a JSON o abort HTTP.
    - `TenantNotProvisionedException` (HTTP 503): Empresa con estado técnico no listo (`pending`, `in_progress`, `failed`).
    - `TenantConnectionConfigException` (HTTP 503): Ausencia de configuración o credenciales en la base central.
    - `TenantConnectionFailedException` (HTTP 503): Error de red, credenciales o rechazo de autenticación de PostgreSQL, sanitizado para no filtrar contraseñas ni DSNs en las respuestas.
    - `TenantIdentityMismatchException` (HTTP 500): Inconsistencia crítica entre la empresa del contexto y el registro `tenant_identity` de la base conectada.
    - `TenantContextException` (HTTP 500): Intento de consulta comercial sin contexto activo o intento de reutilización cruzada de modelos entre empresas distintas.

### 2.2 Middleware `EstablishTenantConnection` (`app/Http/Middleware/EstablishTenantConnection.php`)

- Alias registrado: `'tenant.connection'`.
- Implementa `TerminableMiddleware`.
- Ejecuta `connect($context->company)` antes de despachar al controlador.
- Ejecuta `disconnect()` en un bloque `finally` para garantizar la limpieza ante cualquier excepción o fallo en tiempo de ejecución.
- Ejecuta `disconnect()` de forma redundante en `terminate()` tras el envío de la respuesta HTTP al cliente.

---

## 3. Matriz de Rutas y Conexiones

| Ruta / Prefijo                              | Middlewares de Conexión y Autorización                            | Base de Datos Utilizada                                    |
| :------------------------------------------ | :---------------------------------------------------------------- | :--------------------------------------------------------- |
| `/`, `/dashboard`                           | `auth`, `verified`                                                | **Central** (0 conexiones a bases comerciales)             |
| `/platform/*`                               | `privileged.2fa:platform`, `platform.can:*`                       | **Central** (Operaciones de administración central)        |
| `/app/{company:slug}`                       | `company.context`, `privileged.2fa:company`                       | **Central** (Espacio de trabajo corporativo y licencias)   |
| `/app/{company:slug}/members/*`             | `company.context`, `company.can:commercial.members.*`             | **Central** (Gestión de miembros e invitaciones)           |
| `/app/{company:slug}/roles/*`               | `company.context`, `company.can:commercial.roles.*`               | **Central** (Gestión de roles y permisos centrales)        |
| `/app/{company:slug}/ownership-transfers/*` | `company.context`, `company.can:commercial.ownership.*`           | **Central** (Transferencia de titularidad de cuenta)       |
| `/app/{company:slug}/catalog/*`             | `company.context`, `tenant.connection`, `company.can:inventory.*` | **Comercial (Tenant)** (con usuario DML `rec_app_{id}`)    |
| `/app/{company:slug}/diagnostic/*`          | `company.context`, `tenant.connection`, `company.can:*`           | **Comercial (Tenant)** (comprobación de `tenant_identity`) |

---

## 4. Evidencias de Pruebas Automatizadas

### A. Suite de Contexto y Conexión (Fase 6)

- **Archivo de Prueba:** `tests/Feature/Tenant/TenantConnectionContextTest.php`
- **Tipo:** Pruebas de integración y funcionales HTTP.
- **Fecha de Verificación Original:** Octubre de 2026.
- **Resultado:** **14 pruebas pasadas, 76 aserciones, 0 fallos.**
- **Alcance Acreditado:**
    1. Consulta de identidad técnica en Empresa A (`company_id = A`).
    2. Consulta de identidad técnica en Empresa B (`company_id = B`).
    3. Secuencia consecutiva $A \rightarrow B \rightarrow A$ en el mismo proceso PHP sin contaminación de conexiones.
    4. Sesión HTTP independiente libre de variables globales de contexto empresarial.
    5. Rechazo temprano (HTTP 404) de usuarios sin membresía antes de intentar conectar a la base comercial.
    6. Bloqueo de operadores de administración central sin membresía hacia datos comerciales.
    7. Reflejo inmediato de suspensión de membresía en solicitudes subsiguientes.
    8. Bloqueo por vencimiento de licencia o desvinculación de módulo (HTTP 403).
    9. Retorno controlado de HTTP 503 ante bases no aprovisionadas, manteniendo operativas las rutas centrales (`members`, `roles`).
    10. Aborto con HTTP 500 y purga inmediata ante discrepancia de `tenant_identity`.
    11. Recuperación tras fallo de conexión de PostgreSQL sin afectar peticiones posteriores.
    12. Rechazo de consultas sobre `TenantModel` fuera de un contexto empresarial activo.
    13. Operaciones de login y dashboard ejecutadas con 0 conexiones abiertas hacia bases comerciales.
    14. Blanqueo estricto de credenciales en `config('database.connections.tenant')` tras la finalización de la solicitud.

### B. Pruebas Complementarias de Aislamiento Cruzado (Fase 7)

- **Archivo de Prueba:** `tests/Feature/Tenant/ProductCatalogTest.php` (Bloques 7 y 8)
- **Resultado:** Operaciones reales aisladas entre empresas A y B con mismo SKU y mismos IDs; bloqueo de reutilización cruzada de modelos y consultas mediante `TenantBuilder` arrojando `TenantContextException`.

---

## 5. Continuidad Arquitectónica y Estado de Módulos Posteriores

La infraestructura de conexión por solicitud sustenta operativamente el catálogo básico de productos simples (Fase 7). Las entidades comerciales creadas (`Product`, `Category`, `UnitOfMeasure`) extienden `TenantModel` y operan de forma segura bajo la conexión dinámica `'tenant'`.

Queda pendiente para fases futuras la implementación del agrupamiento de conexiones (_connection pooling_) y el desarrollo de los módulos de existencias en almacenes, variantes de artículos, compras y facturación electrónica.
