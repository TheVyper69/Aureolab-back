# POS Laboratorio de Lentes (Frontend)

Frontend modular (HTML/CSS/JS + Bootstrap 5.3) listo para consumir APIs REST (JWT).
Incluye modo **mock** para desarrollo sin backend.

## Requisitos
- Cualquier servidor estático (recomendado: VSCode Live Server)

## Ejecutar
1. Abre la carpeta `public/` con un servidor estático (no abrir directo con doble click).
2. Navega a `index.html`.

> Importante: `fetch` del mock requiere servidor. Con Live Server funciona perfecto.

## Login (mock)
- Si el correo contiene la palabra **admin**, entras como **Administrador**
- Si no, entras como **Empleado**

## Modo Mock / Backend
En `public/assets/js/services/api.js`:
- `useMock = true` (por defecto)
- Cambia a `false` y ajusta `baseURL` cuando tengas backend real.

## Módulos
- POS: `#/pos`
- Inventario: `#/inventory` (admin CRUD; empleado solo lectura)
- Ventas/Reportes: `#/sales`

## Paleta
- Lila: #9D7AD6
- Dorado: #D4AF37
- Lila oscuro: #7E57C2
- Lila claro: #B39DDB
- Gris: #F8F9FA


## Ópticas y pedidos
- Admin registra Ópticas en `#/opticas`.
- Las Ópticas (rol `optica`) solo ven stock dentro de `#/orders` y crean pedidos.
- El Admin define si cada óptica puede pagar por **transferencia** y/o **efectivo**.
- Mock login: correo con palabra **optica** para entrar como óptica.
 