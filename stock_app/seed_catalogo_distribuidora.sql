USE control_stock;
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO unidades_venta (codigo, nombre_singular, nombre_plural, activo)
VALUES ('BOLSA', 'Bolsa', 'Bolsas', 1)
ON DUPLICATE KEY UPDATE nombre_singular = VALUES(nombre_singular), nombre_plural = VALUES(nombre_plural), activo = 1;

INSERT INTO categorias (nombre, activo) VALUES
('Alfajores', 1), ('Golosinas', 1), ('Chocolates', 1),
('Galletitas', 1), ('Snacks', 1), ('Bebidas', 1)
ON DUPLICATE KEY UPDATE activo = 1;

-- Los dos registros demo identificados conservan sus IDs, ventas y auditoría.
UPDATE productos
SET activo = 0
WHERE LOWER(TRIM(nombre)) IN ('plaan 500 mb', 'plan 5gb');

INSERT INTO productos
    (codigo, nombre, categoria_id, precio, stock, unidad_venta_codigo, contenido_presentacion, activo)
VALUES
('ALF-JORG-CHO-24', 'Jorgito Chocolate', (SELECT id FROM categorias WHERE nombre = 'Alfajores'), 18000.00, 20, 'CAJA', '24 unidades', 1),
('ALF-GUAY-TRI-24', 'Guaymallén Triple Chocolate', (SELECT id FROM categorias WHERE nombre = 'Alfajores'), 14400.00, 18, 'CAJA', '24 unidades', 1),
('GOL-PICO-DUL-50', 'Chupetín Pico Dulce', (SELECT id FROM categorias WHERE nombre = 'Golosinas'), 9500.00, 25, 'BOLSA', '50 unidades', 1),
('GOL-MAST-SUR-800', 'Caramelos Masticables Surtidos', (SELECT id FROM categorias WHERE nombre = 'Golosinas'), 7500.00, 30, 'BOLSA', '800 g', 1),
('GOL-DURO-FRU-1K', 'Caramelos Duros Frutales', (SELECT id FROM categorias WHERE nombre = 'Golosinas'), 8200.00, 22, 'BOLSA', '1 kg', 1),
('CHO-LECH-100-20', 'Chocolate con Leche 100 g', (SELECT id FROM categorias WHERE nombre = 'Chocolates'), 32000.00, 15, 'CAJA', '20 unidades', 1),
('GAL-CHOCOLINAS', 'Galletitas Chocolinas', (SELECT id FROM categorias WHERE nombre = 'Galletitas'), 24000.00, 15, 'CAJA', 'Paquetes', 1),
('GAL-OREO', 'Galletitas Oreo', (SELECT id FROM categorias WHERE nombre = 'Galletitas'), 26000.00, 15, 'CAJA', NULL, 1),
('SNA-PAPAS-CLA-20', 'Papas fritas clásicas', (SELECT id FROM categorias WHERE nombre = 'Snacks'), 30000.00, 20, 'CAJA', '20 paquetes', 1),
('SNA-PALITOS', 'Palitos salados', (SELECT id FROM categorias WHERE nombre = 'Snacks'), 25000.00, 15, 'BULTO', NULL, 1),
('BEB-COCA-500-12', 'Coca-Cola 500 ml', (SELECT id FROM categorias WHERE nombre = 'Bebidas'), 18000.00, 20, 'PACK', '12 botellas', 1),
('BEB-AGUA-500-12', 'Agua mineral 500 ml', (SELECT id FROM categorias WHERE nombre = 'Bebidas'), 8500.00, 25, 'PACK', '12 botellas', 1)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    categoria_id = VALUES(categoria_id),
    precio = VALUES(precio),
    unidad_venta_codigo = VALUES(unidad_venta_codigo),
    contenido_presentacion = VALUES(contenido_presentacion),
    activo = 1;

UPDATE productos SET unidades_por_presentacion=24,unidad_base_codigo='UNIDAD',marca=COALESCE(marca,'Jorgito') WHERE codigo='ALF-JORG-CHO-24';
UPDATE productos SET unidades_por_presentacion=24,unidad_base_codigo='UNIDAD',marca=COALESCE(marca,'Guaymallén') WHERE codigo='ALF-GUAY-TRI-24';
UPDATE productos SET unidades_por_presentacion=50,unidad_base_codigo='UNIDAD' WHERE codigo='GOL-PICO-DUL-50';
UPDATE productos SET unidades_por_presentacion=20,unidad_base_codigo='UNIDAD' WHERE codigo IN ('CHO-LECH-100-20','SNA-PAPAS-CLA-20');
UPDATE productos SET unidades_por_presentacion=12,unidad_base_codigo='UNIDAD' WHERE codigo IN ('BEB-COCA-500-12','BEB-AGUA-500-12');
UPDATE productos SET marca=COALESCE(marca,'Coca-Cola') WHERE codigo='BEB-COCA-500-12';

-- En una instalación nueva, cada existencia debe tener un lote para que FEFO
-- pueda considerarla vendible. No se inventan número ni vencimiento.
INSERT INTO producto_lotes
    (producto_id, numero_lote, cantidad_inicial, stock_actual, fecha_ingreso,
     fecha_vencimiento, estado, origen, usuario_id)
SELECT p.id, NULL, p.stock, p.stock, NOW(), NULL, 'ACTIVO', 'ALTA_INICIAL', NULL
FROM productos p
WHERE p.codigo IN (
    'ALF-JORG-CHO-24','ALF-GUAY-TRI-24','GOL-PICO-DUL-50','GOL-MAST-SUR-800',
    'GOL-DURO-FRU-1K','CHO-LECH-100-20','GAL-CHOCOLINAS','GAL-OREO',
    'SNA-PAPAS-CLA-20','SNA-PALITOS','BEB-COCA-500-12','BEB-AGUA-500-12'
)
  AND p.stock > 0
  AND NOT EXISTS (SELECT 1 FROM producto_lotes l WHERE l.producto_id = p.id);

INSERT INTO stock_movimientos
    (producto_id, producto_nombre_snapshot, usuario_id, venta_id, lote_id, tipo,
     cantidad_cambio, stock_anterior, stock_nuevo, unidad_venta_snapshot, motivo)
SELECT p.id, p.nombre, NULL, NULL, l.id, 'ALTA_INICIAL', p.stock, 0, p.stock,
       u.nombre_singular, 'Carga inicial del catálogo de distribuidora'
FROM productos p
JOIN unidades_venta u ON u.codigo = p.unidad_venta_codigo
JOIN producto_lotes l ON l.producto_id = p.id AND l.origen = 'ALTA_INICIAL'
WHERE p.codigo IN (
    'ALF-JORG-CHO-24','ALF-GUAY-TRI-24','GOL-PICO-DUL-50','GOL-MAST-SUR-800',
    'GOL-DURO-FRU-1K','CHO-LECH-100-20','GAL-CHOCOLINAS','GAL-OREO',
    'SNA-PAPAS-CLA-20','SNA-PALITOS','BEB-COCA-500-12','BEB-AGUA-500-12'
)
  AND NOT EXISTS (
      SELECT 1 FROM stock_movimientos sm
      WHERE sm.producto_id = p.id AND sm.tipo = 'ALTA_INICIAL'
        AND sm.motivo = 'Carga inicial del catálogo de distribuidora'
  );

COMMIT;
