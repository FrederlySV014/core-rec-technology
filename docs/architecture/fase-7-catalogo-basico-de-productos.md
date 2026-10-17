# Fase 7: Catálogo Básico de Productos

## 1. Resumen de Arquitectura y Alcance

La **Fase 7** implementa el catálogo básico de productos simples, categorías y unidades de medida dentro del monolito modular multiempresa de **Rec Technology** (Laravel 13, PostgreSQL 16 e Inertia.js v3 con React 19).

El catálogo opera directamente sobre la base de datos comercial independiente de cada empresa cliente (`rec_tenant_{company_id}`):

- **Aislamiento Multiempresa Estricto:** Los datos del catálogo residen exclusivamente en la base comercial de cada empresa, accedidos en solicitudes web mediante el usuario de aplicación con privilegios mínimos DML (`rec_app_{id}`).
- **Alcance Delimitado:** Incluye exclusivamente productos simples, categorías y unidades de medida internas. No incluye variantes, listas de precios, impuestos, almacenes, inventarios, imágenes ni comercio electrónico público.

---

## 2. Ajustes al Diseño Implementados

### 2.1 Permisos Explícitos y Gestión de Roles

- **Sin Permisos Implícitos:** El permiso `inventory.products.manage` **no** confiere autorización para crear, modificar o desactivar categorías ni unidades de medida.
- **Permisos Granulares en Base Central:**
    - `inventory.products.view`: Visualizar productos, categorías y unidades en el catálogo.
    - `inventory.products.manage`: Crear, editar y cambiar estado de productos simples.
    - `inventory.categories.manage`: Crear, editar y cambiar estado de categorías.
    - `inventory.units.manage`: Crear, editar y cambiar estado de unidades de medida.
- **Plantillas vs. Roles Empresariales Existentes:**
    - Los permisos se asignaron a las plantillas estándar (`company_owner`, `company_admin`) en la base central ([CentralRoleTemplateSeeder.php](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/database/seeders/CentralRoleTemplateSeeder.php)).
    - Esta actualización aplica automáticamente a las **nuevas empresas** que se creen a partir de dichas plantillas.
    - En las **empresas preexistentes**, los roles materializados en la tabla central `company_roles` conservan sus permisos previos. La ampliación de capacidades en empresas existentes se realiza mediante asignación explícita en `/app/{company:slug}/roles`, sin ejecutar seeders generales que alteren perfiles o roles personalizados.
    - Los roles personalizados (como `store_auditor` o `limited_admin`) **no fueron ampliados** ni modificados.

### 2.2 Conexión Efectiva de Migraciones y Runner

- **Migraciones Comerciales Dinámicas:** Las migraciones ubicadas en `database/migrations/tenant/` no definen una propiedad `$connection` fija en el código de la clase.
- **Ejecución Homogénea:** Al ejecutarse dentro del comando `php artisan tenant:migrate {company}`:
    - Las operaciones de esquema (`Schema::create`, `Schema::table`) utilizan la conexión temporal configurada con el usuario DDL (`rec_mig_{id}`).
    - La tabla de control `migrations` se crea y actualiza dentro de la base comercial correspondiente con las credenciales de migración.
- **Preservación de Datos:** Se verificó que `tenant:migrate` conserva intactos los datos preexistentes en tablas comerciales tanto en bases recién creadas como en bases ya en producción.

### 2.3 Privilegios Limitados al ERP

- **Concesión Restrictiva:** Se eliminó cualquier concesión masiva (`ON ALL TABLES IN SCHEMA public`).
- **Lista Blanca de Tablas:** En `TenantProvisioningService::grantErpPrivileges()`, los privilegios para `rec_app_{id}` se restringen estrictamente a:
    - Tablas comerciales: `categories`, `units_of_measure`, `products` (SELECT, INSERT, UPDATE, DELETE).
    - Secuencias asociadas a las tablas comerciales: (USAGE, SELECT, UPDATE).
    - Tabla técnica: `tenant_identity` (SELECT únicamente; INSERT, UPDATE y DELETE explícitamente bloqueados).
- **Protección de Tablas Externas:** Si una aplicación de tienda o servicio externo comparte la base de datos comercial (ej. tabla hipotética `store_carts`), la ejecución de `tenant:migrate` o aprovisionamiento no otorga privilegios sobre tablas ajenas al sistema de gestión comercial.
- **Bloqueo DDL en Solicitudes:** El usuario de aplicación no posee privilegios DDL en PostgreSQL; cualquier intento de ejecutar sentencias como `CREATE TABLE` arroja error de permiso denegado a nivel de motor.

### 2.4 Referencias Inactivas y Advertencia Explicativa

- **Conservación Permitida:** Al editar un producto ([UpdateProductRequest.php](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/app/Modules/Catalog/Http/Requests/UpdateProductRequest.php)), el sistema permite guardar el producto conservando su categoría o unidad de medida actualmente inactiva, siempre y cuando no se modifique dicha referencia.
- **Rechazo de Nueva Asignación Inactiva:** Se rechaza con HTTP 422 cualquier intento de asociar una categoría o unidad inactiva diferente a la que el producto ya posee.
- **Interfaz Explicativa:** En el formulario de edición web ([index.tsx](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/resources/js/pages/company/catalog/index.tsx)), si un producto tiene una categoría inactiva asignada, el selector la presenta acompañada del mensaje contextual:  
  _“La categoría actualmente asignada está inactiva. Se conserva como referencia histórica pero no estará disponible para nuevos productos.”_

### 2.5 Cambios de Estado Explícitos e Idempotentes

- **Operación PATCH Determinista:** Se eliminaron los endpoints de alternancia ciega (_toggles_). Los estados se actualizan mediante peticiones explícitas:
    - `PATCH /app/{company:slug}/catalog/categories/{category}/status` con `{ is_active: bool }`
    - `PATCH /app/{company:slug}/catalog/units/{unit}/status` con `{ is_active: bool }`
    - `PATCH /app/{company:slug}/catalog/products/{product}/status` con `{ is_active: bool }`
- **Idempotencia:** Enviar reiteradamente `{ is_active: false }` conserva exactamente el mismo estado y respuesta sin alternar cíclicamente.
- **Restricción de Licencia:** Las operaciones de mutación evalúan la validez de la licencia. Si la empresa se encuentra en periodo de gracia o con licencia vencida/suspendida, la solicitud es rechazada con HTTP 403.

### 2.6 Unidades de Medida y Códigos Internos

- **Códigos Internos Administrativos:** Los códigos de unidad (ej. `UND`, `KGM`, `NIU`, `LTR`) operan estrictamente como identificadores internos de la plataforma comercial.
- **Normalización:** Se transforman a mayúsculas y sin espacios residuales ([CatalogNormalizer.php](file:///c:/Users/Lenovo/Documents/projects/Rec%20Technology/core-rec-technology/app/Modules/Catalog/Services/CatalogNormalizer.php)).
- **Sin Asunción Fiscal:** No asumen equivalencia ni catálogo de la autoridad tributaria (SUNAT / UBL). Dicha homologación tributaria se define como alcance de una fase posterior.

---

## 3. Esquema de Base de Datos Comercial (Vigente en Migraciones)

```sql
-- Categorías
CREATE TABLE public.categories (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX categories_name_index ON public.categories (name);
CREATE INDEX categories_is_active_index ON public.categories (is_active);

-- Unidades de Medida (Códigos Internos Administrativos)
CREATE TABLE public.units_of_measure (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    allows_decimal BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT units_of_measure_code_unique UNIQUE (code)
);
CREATE INDEX units_of_measure_is_active_index ON public.units_of_measure (is_active);

-- Productos Simples
CREATE TABLE public.products (
    id BIGSERIAL PRIMARY KEY,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    barcode VARCHAR(100) NULL,
    category_id BIGINT NULL REFERENCES public.categories(id) ON DELETE RESTRICT,
    unit_of_measure_id BIGINT NOT NULL REFERENCES public.units_of_measure(id) ON DELETE RESTRICT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT products_sku_unique UNIQUE (sku)
);
CREATE INDEX products_name_index ON public.products (name);
CREATE INDEX products_is_active_index ON public.products (is_active);
CREATE INDEX products_category_id_index ON public.products (category_id);
CREATE INDEX products_unit_of_measure_id_index ON public.products (unit_of_measure_id);

-- Índice único parcial para código de barras (permite múltiples registros nulos sin violar unicidad)
CREATE UNIQUE INDEX products_barcode_unique ON public.products (barcode) WHERE barcode IS NOT NULL;
```

---

## 4. Matriz de Rutas, Middlewares y Permisos

| Verbo   | Ruta                                                       | Middlewares                            | Permiso Requerido             | Modo Licencia |
| :------ | :--------------------------------------------------------- | :------------------------------------- | :---------------------------- | :------------ |
| `GET`   | `/app/{company:slug}/catalog`                              | `company.context`, `tenant.connection` | `inventory.products.view`     | `read_only`   |
| `POST`  | `/app/{company:slug}/catalog/categories`                   | `company.context`, `tenant.connection` | `inventory.categories.manage` | `mutable`     |
| `PUT`   | `/app/{company:slug}/catalog/categories/{category}`        | `company.context`, `tenant.connection` | `inventory.categories.manage` | `mutable`     |
| `PATCH` | `/app/{company:slug}/catalog/categories/{category}/status` | `company.context`, `tenant.connection` | `inventory.categories.manage` | `mutable`     |
| `POST`  | `/app/{company:slug}/catalog/units`                        | `company.context`, `tenant.connection` | `inventory.units.manage`      | `mutable`     |
| `PUT`   | `/app/{company:slug}/catalog/units/{unit}`                 | `company.context`, `tenant.connection` | `inventory.units.manage`      | `mutable`     |
| `PATCH` | `/app/{company:slug}/catalog/units/{unit}/status`          | `company.context`, `tenant.connection` | `inventory.units.manage`      | `mutable`     |
| `POST`  | `/app/{company:slug}/catalog/products`                     | `company.context`, `tenant.connection` | `inventory.products.manage`   | `mutable`     |
| `PUT`   | `/app/{company:slug}/catalog/products/{product}`           | `company.context`, `tenant.connection` | `inventory.products.manage`   | `mutable`     |
| `PATCH` | `/app/{company:slug}/catalog/products/{product}/status`    | `company.context`, `tenant.connection` | `inventory.products.manage`   | `mutable`     |

---

## 5. Pruebas y Evidencias Técnicas Verificables

### A. Pruebas Automatizadas de la Fase 7

- **Archivo de Prueba:** `tests/Feature/Tenant/ProductCatalogTest.php`
- **Tipo:** Pruebas funcionales y de integración multiempresa sobre PostgreSQL.
- **Fecha de Última Ejecución:** 17 de Octubre de 2026 (~04:36 UTC / ~23:36 local).
- **Resultado:** **18 pruebas aprobadas, 107 aserciones, 0 fallos (44.1s).**
- **Detalle de Bloques Comprobados:**
    1. _Permisos Explícitos:_ Bloqueo con HTTP 403 al intentar crear categorías o unidades teniendo únicamente `inventory.products.manage`.
    2. _Conexión Efectiva de Migraciones:_ Migraciones comerciales aplicadas sin contaminar conexiones centrales en bases nuevas y preexistentes.
    3. _Privilegios Limitados al ERP:_ Concesión restrictiva DML a `rec_app_{id}` y comprobación de que tablas ajenas al ERP no reciben privilegios.
    4. _Referencias Inactivas:_ Edición permitida conservando categoría inactiva histórica; rechazo (HTTP 422) ante nuevas asociaciones inactivas.
    5. _Idempotencia de Estado:_ Peticiones PATCH consecutivas conservan el valor booleano sin oscilación. Bloqueo (HTTP 403) bajo licencia en gracia.
    6. _Normalización de Unidades:_ Códigos en mayúsculas, espacios eliminados y unicidad por base comercial.
    7. _Aislamiento entre Empresas A y B:_ Mismo SKU (`SKU-COMMON`) permitido en ambas empresas; identificadores primarios idénticos (`id = 1`) en bases separadas; modificaciones aisladas; y secuencia de peticiones HTTP $A \rightarrow B \rightarrow A$ sin residuos de estado.
    8. _Protección Cruzada de Modelos y Consultas:_ Modelos o consultas construidos en la Empresa A arrojan `TenantContextException` si se intentan mutar o ejecutar tras conmutar a la Empresa B.
    9. _Preservación de Datos en Migraciones:_ `tenant:migrate` conserva todos los registros existentes y PostgreSQL bloquea sentencias DDL ejecutadas por el usuario de aplicación.

### B. Evidencias en Navegador Real (Grabaciones WebP y Capturas)

- `catalog_e2e_verification_1792210009027.webp`: Alta completa de categoría, unidad de medida y producto simple con sesión autenticada con 2FA.
- `catalog_edit_status_readonly_1792211012875.webp`: Edición de producto, desactivación de categoría, comprobación visual del mensaje explicativo de categoría inactiva conservada y persistencia.
- `catalog_readonly_verification_1792211302010.webp`: Acceso como usuario auditor (`auditor-andina@demo.local`) comprobando que no se muestran botones de creación ni columnas de acción.
- `verify_503_fix_1792247801617.webp`: Comprobación de recuperación y carga exitosa (HTTP 200) de `/app/andina-store/catalog`.
- Capturas disponibles en el directorio de artefactos: `catalog_page_success_1792247844812.png`, `catalog_view_auditor_1792211398232.png`, `categories_tab_auditor_1792211421312.png`, `units_tab_auditor_1792211435547.png`.
