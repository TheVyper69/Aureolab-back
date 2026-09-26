# Aureolab Backend

Backend REST API de **Aureolab**, sistema para operación de ópticas y laboratorio óptico. Este repositorio contiene la API construida con Laravel, autenticación por Laravel Sanctum, control de roles, gestión de inventario, pedidos, catálogos, usuarios, ópticas y reportes.

El backend está diseñado para ser consumido por el frontend SPA del repositorio:

```txt
TheVyper69/aureolab_front
```

---

## Estado del proyecto

Versión documentada: **1.10.3**

Este proyecto utiliza Laravel como API REST y expone sus endpoints bajo:

```txt
/api
```

---

## Stack técnico

- **PHP 8.2+**
- **Laravel 12**
- **Laravel Sanctum 4.3**
- **MySQL / MariaDB**
- **Eloquent ORM**
- **Query Builder**
- **Composer**
- **Laravel Tinker**
- **PHPUnit**
- **Laravel Pint**

Dependencias principales declaradas en `composer.json`:

```json
{
  "php": "^8.2",
  "laravel/framework": "^12.0",
  "laravel/sanctum": "^4.3",
  "laravel/tinker": "^2.10.1"
}
```

---

## Características principales

- Autenticación por token con Laravel Sanctum.
- Login y logout.
- Registro y administración de usuarios.
- Roles: `admin`, `employee`, `optica`.
- Middleware de autorización por rol.
- Gestión de productos e inventario.
- Manejo de imágenes de productos y categorías.
- Categorías con precios de compra / venta.
- Generación masiva de micas por rangos ópticos.
- Tipos de lente, materiales, proveedores, boxes y tratamientos.
- Gestión de ópticas con usuario asociado y cliente espejo.
- Creación y seguimiento de pedidos.
- Biselado personalizado desde POS.
- Soporte para esfera, cilindro, eje y adición.
- Reservas de inventario al crear pedidos.
- Salidas de inventario al entregar pedidos.
- Cancelación y reversos de inventario.
- Reportes administrativos.

---

## Estructura relevante

```txt
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── BoxController.php
│   │       ├── CategoriesController.php
│   │       ├── InventoryController.php
│   │       ├── LensTypeController.php
│   │       ├── MaterialController.php
│   │       ├── OpticasController.php
│   │       ├── OrdersController.php
│   │       ├── ProductsController.php
│   │       ├── ReportsController.php
│   │       ├── SalesController.php
│   │       ├── SupplierController.php
│   │       └── TreatmentsController.php
│   └── Middleware/
│       └── RoleMiddleware.php
├── Models/
│   ├── Order.php
│   ├── OrderItem.php
│   ├── Product.php
│   ├── User.php
│   └── ...
routes/
└── api.php
```

---

## Instalación local

### 1. Clonar repositorio

```bash
git clone <url-del-repositorio>
cd Aureolab-back
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Crear archivo `.env`

```bash
cp .env.example .env
```

En Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

### 4. Generar key

```bash
php artisan key:generate
```

### 5. Configurar base de datos

En `.env`, ajusta los datos de conexión:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aureolab
DB_USERNAME=root
DB_PASSWORD=
```

### 6. Ejecutar servidor local

```bash
php artisan serve
```

API local esperada:

```txt
http://127.0.0.1:8000/api
```

---

## Autenticación

El backend usa Laravel Sanctum con token Bearer.

### Login

```http
POST /api/auth/login
```

Payload:

```json
{
  "email": "usuario@correo.com",
  "password": "contraseña"
}
```

Respuesta esperada:

```json
{
  "token": "...",
  "user": {
    "id": 1,
    "name": "Usuario",
    "email": "usuario@correo.com",
    "role_id": 1
  }
}
```

### Uso del token

Todas las rutas protegidas requieren:

```http
Authorization: Bearer <token>
Accept: application/json
```

### Logout

```http
POST /api/auth/logout
```

---

## Roles

| Rol | Descripción |
|---|---|
| `admin` | Administración completa del sistema. |
| `employee` | Operación interna: inventario y seguimiento de pedidos. |
| `optica` | Cliente óptica: catálogo/POS y pedidos propios. |

El middleware `role` controla el acceso a rutas específicas.

---

## Rutas públicas

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/login` | Iniciar sesión. |
| GET | `/api/php-limits` | Revisión local de límites PHP. Solo pruebas locales. |

---

## Rutas protegidas generales

Todas requieren `auth:sanctum`.

### Usuario autenticado

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/logout` | Cerrar sesión. |
| GET | `/api/me` | Consultar usuario autenticado y rol. |

### Pedidos

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/orders` | admin, employee, optica | Listar pedidos. |
| POST | `/api/orders` | admin, employee, optica | Crear pedido. |
| GET | `/api/orders/{id}` | admin, employee, optica | Ver detalle. |
| PATCH | `/api/orders/{id}` | admin, employee | Actualizar estado de pago/proceso. |
| PATCH | `/api/orders/{id}/cancel` | admin, optica | Cancelar pedido bajo reglas de negocio. |

### Inventario y productos

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/products` | admin, employee, optica | Listado de productos. |
| GET | `/api/products/{id}` | admin | Detalle de producto. |
| POST | `/api/products` | admin | Crear producto o generar micas masivamente. |
| PUT | `/api/products/{id}` | admin | Actualizar producto. |
| POST | `/api/products/{id}` | admin | Actualizar producto con FormData. |
| DELETE | `/api/products/{id}` | admin | Eliminar producto. |
| DELETE | `/api/products/bulk-delete` | admin | Eliminación masiva. |
| POST | `/api/products/{id}/stock` | admin | Entrada manual de stock. |
| GET | `/api/products/{id}/image` | admin, employee, optica | Imagen protegida del producto. |
| GET | `/api/products/{id}/thumb` | admin, employee, optica | Miniatura / alias de imagen. |
| GET | `/api/inventory` | admin, employee, optica | Inventario visible. |
| GET | `/api/inventory/low-stock` | admin, employee, optica | Stock bajo. |

### Categorías

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/categories` | admin, employee, optica | Listar categorías. |
| POST | `/api/categories` | admin | Crear categoría. |
| PUT | `/api/categories/{id}` | admin | Actualizar categoría. |
| POST | `/api/categories/{id}` | admin | Actualizar categoría con FormData. |
| DELETE | `/api/categories/{id}` | admin | Eliminar categoría. |
| GET | `/api/categories/{id}/image` | admin, employee, optica | Imagen de categoría. |

### Catálogos auxiliares

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/lens-types` | admin, employee, optica | Tipos de lente. |
| POST | `/api/lens-types` | admin | Crear tipo de lente. |
| PUT | `/api/lens-types/{id}` | admin | Actualizar tipo de lente. |
| DELETE | `/api/lens-types/{id}` | admin | Eliminar tipo de lente. |
| GET | `/api/materials` | admin, employee, optica | Materiales. |
| POST | `/api/materials` | admin | Crear material. |
| PUT | `/api/materials/{id}` | admin | Actualizar material. |
| DELETE | `/api/materials/{id}` | admin | Eliminar material. |
| GET | `/api/suppliers` | admin, employee, optica | Proveedores. |
| POST | `/api/suppliers` | admin | Crear proveedor. |
| PUT | `/api/suppliers/{id}` | admin | Actualizar proveedor. |
| DELETE | `/api/suppliers/{id}` | admin | Eliminar proveedor. |
| GET | `/api/boxes` | admin, employee, optica | Boxes. |
| POST | `/api/boxes` | admin | Crear box. |
| PUT | `/api/boxes/{id}` | admin | Actualizar box. |
| DELETE | `/api/boxes/{id}` | admin | Eliminar box. |
| GET | `/api/treatments` | admin, employee, optica | Tratamientos activos. |
| GET | `/api/products/{id}/treatments` | admin, employee, optica | Tratamientos asociados a producto. |

### Ópticas y usuarios

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/opticas` | admin, employee, optica | Lista de ópticas para autocomplete y relación con clientes. |
| POST | `/api/opticas` | admin | Crear óptica, customer espejo y usuario óptica. |
| POST | `/api/auth/register` | admin | Crear usuario. |
| GET | `/api/users` | admin | Listar usuarios. |
| PUT | `/api/users/{id}` | admin | Actualizar usuario. |
| DELETE | `/api/users/{id}` | admin | Eliminar usuario. |

### Reportes

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/reports/dashboard` | admin, employee, optica | KPIs generales. |
| GET | `/api/reports/orders/by-day` | admin, employee, optica | Pedidos / ingresos por día. |
| GET | `/api/reports/orders/payment-methods` | admin, employee, optica | Distribución por método de pago. |
| GET | `/api/reports/orders/top-products` | admin, employee, optica | Productos más vendidos. |

---

## Productos e inventario

Los productos manejan información comercial y óptica:

- SKU.
- Nombre.
- Descripción.
- Categoría.
- Precio de compra.
- Precio de venta.
- Stock mínimo y máximo.
- Proveedor.
- Box.
- Tipo de lente.
- Material óptico.
- Esfera.
- Cilindro.
- Eje.
- Adición.
- Imagen.
- Tratamientos.

El modelo `Product` contempla `addition` en `$fillable` y `$casts` como decimal de dos posiciones.

---

## Reglas ópticas

El backend normaliza y valida campos ópticos en `ProductsController` y `OrdersController`.

### Esfera

- Rango permitido: `-40` a `40`.
- Incrementos de `0.25`.

### Cilindro

- Para productos no mica debe ser negativo y no puede ser `0`.
- Incrementos de `0.25`.

### Eje

- Rango permitido: `1` a `180`.
- Si hay cilindro, debe existir eje.
- Si hay eje, debe existir cilindro.
- Las micas generadas masivamente no manejan eje.

### Adición

Aplica para tipos de lente detectados por código o nombre:

- Flat Top
- Younger
- Progresivo
- Progressive

Valores permitidos:

```txt
1.00 a 3.50 en incrementos de 0.25
```

Si el tipo de lente requiere adición y no se envía, la API responde error de validación.

---

## Generación masiva de micas

`POST /api/products` puede generar micas masivamente cuando el request incluye banderas o campos como:

```txt
generate_micas
bulk_mica
sphere_min
sphere_max
cylinder_max
```

El backend genera combinaciones por incrementos de `0.25` para:

- Esfera mínima.
- Esfera máxima.
- Cilindro máximo negativo.

También puede crear stock inicial y movimientos de inventario.

---

## Imágenes

El backend soporta imágenes en productos y categorías.

### Productos

- Imagen propia del producto en `image_path`.
- Compatibilidad con `image_blob` heredado.
- Fallback a imagen de categoría cuando el producto no tiene imagen propia.
- Endpoint protegido: `/api/products/{id}/image`.

### Categorías

- Soporta `image_blob` como principal.
- Soporta `image_path` como fallback heredado.
- Endpoint protegido: `/api/categories/{id}/image`.

---

## Tratamientos

Los tratamientos se manejan en:

```txt
treatments
product_treatments
order_item_treatments
```

Las micas pueden tener tratamientos asociados. En pedidos, los tratamientos seleccionados por item se guardan en `order_item_treatments`.

---

## Pedidos

El controlador principal es:

```txt
app/Http/Controllers/Api/OrdersController.php
```

Al crear un pedido:

1. Se valida el usuario y su rol.
2. Se valida cada item.
3. Si es producto normal, se revisa disponibilidad.
4. Si es biselado personalizado, se crea un producto temporal/custom.
5. Se crea el pedido.
6. Se crean los renglones en `order_items`.
7. Se reservan existencias para productos normales.
8. Se guardan tratamientos por item.
9. Se guarda detalle de biselado personalizado cuando aplica.

---

## Biselado personalizado

El pedido puede incluir items con:

```json
{
  "custom_bisel": true,
  "sphere": 0.25,
  "cylinder": -0.75,
  "axis": 25,
  "lens_type_id": 4,
  "addition": 1.75,
  "frame_height": 12,
  "blank_height": 4,
  "observations": null
}
```

El backend crea un producto temporal con SKU tipo:

```txt
BIS-CUSTOM-...
```

Y guarda el detalle en `order_item_custom_bisel`.

---

## Estados de pedido

Estados principales de proceso:

```txt
recibido
surtido
en_corte
listo_para_entregar
entregado
revision
cancelado
```

Compatibilidad heredada:

```txt
en_proceso      -> recibido
en_preparacion  -> en_corte
```

### Reglas generales

- El empleado puede avanzar el flujo, pero no retrocederlo.
- El empleado no puede cambiar método de pago ni estado de pago.
- El admin puede mandar a revisión pedidos entregados.
- La cancelación se maneja por endpoint específico.
- Al entregar un pedido, se descuenta inventario y se libera reservado.
- Si se revierte una entrega, se ajusta stock y reservado.

---

## Ópticas

Al crear una óptica desde `OpticasController`:

1. Se crea un registro espejo en `customers`.
2. Se crea la óptica en `opticas`.
3. Se crea el usuario con rol `optica`.
4. Se asignan métodos de pago permitidos en `optica_payment_methods`.

Esto permite que el frontend trate a una óptica como usuario y como cliente de pedidos.

---

## Reportes

Los reportes consumidos por el frontend incluyen:

- Dashboard general.
- Pedidos / ingresos por día.
- Distribución por método de pago.
- Top productos.

Los endpoints están agrupados bajo:

```txt
/api/reports
```

---

## Comandos útiles

### Levantar servidor

```bash
php artisan serve
```

### Limpiar caché

```bash
php artisan optimize:clear
```

### Validar sintaxis de archivos PHP

```bash
php -l app/Http/Controllers/Api/ProductsController.php
php -l app/Http/Controllers/Api/OrdersController.php
php -l app/Models/Product.php
```

### Ejecutar pruebas

```bash
php artisan test
```

### Formateo con Pint

```bash
./vendor/bin/pint
```

En Windows:

```powershell
.\vendor\bin\pint
```

---

## Desarrollo seguro

Recomendaciones:

- No subir respaldos `.bak`.
- No subir patches temporales `.patch` o `.patch.txt`.
- No subir ZIPs de pruebas.
- Ejecutar `php -l` después de modificar controladores grandes.
- Ejecutar `php artisan optimize:clear` después de cambios en rutas, config o controladores.
- Mantener sincronizados los cambios de base de datos con `public/assets/data/sql_cambios.sql` del frontend o con el mecanismo de migración que se defina.

---

## Cambios recientes relevantes

### 1.10.3

- Soporte de campo `addition` en productos.
- Soporte de `addition` en biselado personalizado.
- Validación de adición para Flat Top, Younger y Progresivos.
- Adición en respuestas de producto y pedidos.
- Guardado de adición en `order_item_custom_bisel`.
- Optimización del inventario para evitar cargar imágenes binarias completas en listados.

---

## Frontend relacionado

Repositorio frontend:

```txt
TheVyper69/aureolab_front
```

URL esperada por defecto:

```txt
http://127.0.0.1:8000/api
```
