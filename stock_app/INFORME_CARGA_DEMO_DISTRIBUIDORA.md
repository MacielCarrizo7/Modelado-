# Informe de carga demo distribuidora

Fecha de ejecución: 2026-09-02  
Sistema definitivo: `C:\xampp\htdocs\1\5\stock_app`  
Marca idempotente: `DEMO-DISTRIBUIDORA-V1`

1. **Usuarios antes:** 4 usuarios: administradores ID 8 y 9, cliente ID 10 y vendedor ID 11.
2. **Usuarios después:** los mismos 4 usuarios, con igualdad exacta en DNI, nombre, apellido, hash de contraseña, rol y todos los campos TOTP. No se creó ningún usuario permanente.
3. **Productos encontrados:** 12 productos existentes.
4. **Productos actualizados:** 8 productos fueron adaptados; conservaron sus IDs y códigos internos. Los otros 4 quedaron sin cambios.
5. **Productos creados:** 0. Se reutilizó el catálogo existente para evitar redundancia.
6. **EAN-13 asignados:** 8 códigos únicos con checksum válido y descripción `CÓDIGO DEMO - NO COMERCIAL`.

   | Producto | EAN-13 demo |
   |---|---:|
   | Alfajor Chocolate Triple | 2990000001016 |
   | Alfajor Blanco Triple | 2990000001023 |
   | Chupetines Frutales | 2990000001030 |
   | Caramelos Masticables | 2990000001047 |
   | Chocolate con Leche 100 g | 2990000001054 |
   | Galletitas de Chocolate | 2990000001061 |
   | Papas Fritas Clásicas | 2990000001078 |
   | Gaseosa Cola 500 ml | 2990000001085 |

7. **Marcas:** Demo Dulce, Candy Demo, Choco Demo, Galleta Demo, Snack Demo y Bebida Demo.
8. **Presentaciones:** Caja, Bolsa y Pack; se agregó la unidad base Botella sin eliminar unidades existentes.
9. **Unidades por presentación:** 24, 24, 50, 100, 20, 18, 20 y 12 respectivamente. Las unidades base son Unidad, Paquete o Botella según el producto.
10. **Lotes:** 16 lotes demo disponibles; 8 lotes iniciales existentes se adaptaron y 8 lotes adicionales se insertaron.
11. **Vencimientos:** hay lotes agotados y activos distribuidos entre septiembre de 2026 y abril de 2027. El lote de prueba vencido es `DEMO-TEST-VENCIDO-001`, vencido el 2026-08-23 y con stock físico 1.
12. **Próximos a vencer:** `DEMO-AFT-001` de Alfajor Chocolate Triple (stock 3, vence 2026-09-17) y `DEMO-GCO-002` de Gaseosa Cola 500 ml (stock 3, vence 2026-09-27).
13. **Stock bajo:** Alfajor Blanco Triple, stock 4/mínimo 4; Gaseosa Cola 500 ml, stock 3/mínimo 5.
14. **Sin stock:** Chupetines Frutales, stock 0/mínimo 5.
15. **Ventas demo:** 23, distribuidas del 2026-07-09 al 2026-08-31.
16. **Ventas sin bonificación:** 14 con 0%.
17. **Ventas con bonificación de 5%:** 6.
18. **Ventas con bonificación de 10%:** 3.
19. **Ventas por vendedor:** vendedor ID 11, Maciel Carrizo, 23 operaciones. Era el único vendedor real disponible.
20. **Ventas por cliente:** cliente ID 10, Gabriel Lucania, 23 operaciones. Era el único cliente real disponible.
21. **Ventas por producto:** Alfajor Blanco 2 operaciones/8 vigentes; Alfajor Chocolate 4/9; Caramelos 3/12; Chocolate 2/3; Chupetines 3/25; Galletitas 2/8; Gaseosa 5/19; Papas 2/12.
22. **Venta modificada:** venta ID 19, Caramelos Masticables; cantidad inicial 5, cantidad final 3, estado `MODIFICADA`, total final $25.740. La diferencia volvió al lote y quedó auditada.
23. **Ventas canceladas:** IDs 21 y 23, estado `CANCELADA`; el stock fue devuelto a sus lotes. La regresión confirmó que repetir una cancelación no duplica la devolución.
24. **Stock antes/después:** se muestra el stock original previo, el stock físico preparado para la demo y el resultado final tras ventas, modificación y cancelaciones.

   | Producto | Original | Inicial demo | Final |
   |---|---:|---:|---:|
   | Alfajor Chocolate Triple | 20 | 22 | 13 |
   | Alfajor Blanco Triple | 18 | 12 | 4 |
   | Chupetines Frutales | 25 | 25 | 0 |
   | Caramelos Masticables | 30 | 30 | 18 |
   | Chocolate con Leche 100 g | 15 | 11 | 8 |
   | Galletitas de Chocolate | 15 | 15 | 7 |
   | Papas Fritas Clásicas | 20 | 20 | 8 |
   | Gaseosa Cola 500 ml | 20 | 22 | 3 |

25. **Movimientos generados:** 8 `AJUSTE`, 8 `INGRESO`, 23 `VENTA`, 1 `MODIFICACION_VENTA` y 2 `CANCELACION`: 42 nuevos. Sumados a los 12 anteriores, quedan 54 movimientos. Los 12 originales se compararon columna por columna y permanecen intactos.
26. **Reportes probados:** listado de ventas, detalle por producto, métricas por período, operaciones, presentaciones vendidas, unidades equivalentes, monto, última venta, lotes y movimientos. Para Alfajor Chocolate se verificaron 3 operaciones vigentes y 216 unidades equivalentes.
27. **Filtros probados:** Todos, Con stock (11), Sin stock (1), Stock bajo (2), rango de precios 10.000–25.000 y ventas combinando vendedor + desde/hasta + producto + estado. También se verificó el estado Cancelada (2 resultados).
28. **Búsqueda EAN-13:** `2990000001016` devolvió exactamente el Alfajor Chocolate Triple tanto en el filtro del catálogo como en el endpoint de scanner.
29. **Alertas:** 1 sin stock, 2 con stock bajo, 2 lotes próximos a vencer y 1 lote vencido con stock.
30. **Regresión completa:** pasaron el verificador integral de la demo y las suites de EAN-13/lotes/FEFO/reportes, comercial/roles/CSRF/snapshots y TOTP/autorización. Los datos temporales de prueba se eliminaron; los datos demo se conservaron.

## Ejemplos concretos

**Venta ID 1:** Alfajor Chocolate Triple; vendedor Maciel Carrizo; cliente Gabriel Lucania; 2 cajas; precio $18.000; margen 10%; bonificación 0%; precio final $19.800; total $39.600; fecha 2026-07-09 09:15.

**Venta ID 9:** Alfajor Chocolate Triple; 6 cajas; precio $18.000; margen 10%; bonificación 5%; precio final $18.810; total $112.860; fecha 2026-08-02 09:15.

**Venta ID 8:** Gaseosa Cola 500 ml; 10 packs; precio $18.000; margen 15%; bonificación 10%; precio final $18.630; total $186.300; fecha 2026-07-30 16:15.

## Integridad e idempotencia

- Respaldo previo: `backups\antes_ean_lotes_20260902_232139.json`.
- El cargador utiliza las funciones comerciales, asignación FEFO, devolución a lotes y registro de movimientos del sistema actual.
- La segunda ejecución detectó la marca y no duplicó ningún dato.
- No se ejecutó `DROP DATABASE`, `DROP TABLE`, `TRUNCATE` ni se recreó la base.
- Las ventas, historiales y auditorías previas estaban vacíos; los 12 movimientos de stock anteriores sí existían y quedaron idénticos.

## Limitaciones reales encontradas

- Solo había un vendedor y un cliente reales. Para respetar la prohibición de crear usuarios, todas las ventas demo usan esas dos cuentas; por eso no fue posible distribuirlas entre varias personas.
- `obtener_ventas.php` no implementa un parámetro `cliente_id`; el filtro por cliente no se pudo probar. El resultado sí muestra el cliente y el vendedor.
- El detalle de producto calcula métricas y muestra ventas, lotes y movimientos, pero sus filas de venta no incluyen los nombres de cliente y vendedor. Esa información sí está disponible en el reporte general filtrado por producto.
- No había reglas de bonificación activas. Se agregaron reglas demo automáticas de 5% para 6–9 unidades y 10% desde 10 unidades; la regla previa inactiva se conservó sin modificar.
