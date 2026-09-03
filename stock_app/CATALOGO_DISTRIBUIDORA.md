# Catálogo demo para distribuidora

## Objetivo y alcance

Esta actualización reemplaza visualmente los dos planes demo por un catálogo mayorista realista. No elimina productos con ventas, no modifica usuarios y no cambia la autenticación ni TOTP.

Antes de migrar se generó un respaldo lógico JSON en `backups/`. Contiene productos, ventas, auditorías, movimientos, unidades y reglas comerciales. Para los usuarios guarda identidad y huellas criptográficas de integridad, pero no expone contraseñas ni secretos TOTP.

## Actualización de una instalación existente

Ejecutar una sola vez, en este orden:

1. `migracion_catalogo_distribuidora.sql`
2. `seed_catalogo_distribuidora.sql`

La migración agrega:

- `categorias`, con nombre único sin distinguir mayúsculas de minúsculas.
- `productos.codigo`, identificador estable utilizado por el seed.
- `productos.categoria_id`.
- `productos.contenido_presentacion`.
- `productos.activo`, para retirar un producto sin romper ventas históricas.
- La unidad comercial `BOLSA`.

El seed es repetible. Al volver a ejecutarlo actualiza nombre, categoría, precio y presentación de sus productos, pero no duplica filas ni restablece el stock operativo.

## Tratamiento de los datos anteriores

Los productos `plaan 500 mb` (ID 1) y `plan 5gb` (ID 3) tenían cinco ventas y nueve registros de auditoría relacionados. Se conservaron en sus mismas filas con `activo = 0`. Las ventas mantienen sus nombres, precios, unidades y totales históricos mediante snapshots.

Las consultas de catálogo y la creación de ventas aceptan únicamente productos activos. La acción visible como “Desactivar” también conserva la fila y todo su historial.

## Catálogo activo

El seed carga las categorías Alfajores, Golosinas, Chocolates, Galletitas, Snacks y Bebidas, junto con 12 productos en unidades Caja, Bolsa, Bulto y Pack.

El contenido de presentación se guarda separado de la unidad comercial. Por ejemplo, `Caja · 24 unidades` significa que el stock se cuenta en cajas; no se convierte automáticamente a unidades sueltas.

No se inventó contenido cuando el pedido no lo especificó: `Galletitas Oreo` y `Palitos salados` muestran solo su unidad comercial. `Galletitas Chocolinas` conserva el texto general `Paquetes` porque no se indicó una cantidad.

## Verificación

`tests/verificar_catalogo.php` comprueba:

- integridad de los cuatro usuarios, roles, contraseñas y secretos TOTP cifrados;
- seis categorías sin duplicados;
- los 12 productos esperados con precio, stock, unidad y presentación;
- los dos planes inactivos con sus cinco ventas;
- conservación del total de ventas previo.

Las pruebas `tests/integracion_comercial.php` y `tests/integracion_totp.php` cubren además venta, stock, snapshots, auditoría, permisos, CSRF, roles y segundo factor.
