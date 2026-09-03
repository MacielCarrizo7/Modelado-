USE control_stock;
SET NAMES utf8mb4;

ALTER TABLE productos
    ADD COLUMN ean13 CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER codigo,
    ADD COLUMN marca VARCHAR(100) NULL AFTER nombre,
    ADD COLUMN descripcion TEXT NULL AFTER marca,
    ADD COLUMN fecha_alta DATETIME NULL DEFAULT NULL AFTER descripcion,
    ADD COLUMN fecha_baja DATETIME NULL AFTER fecha_alta,
    ADD COLUMN stock_minimo INT NOT NULL DEFAULT 0 AFTER stock,
    ADD COLUMN unidades_por_presentacion INT NOT NULL DEFAULT 1 AFTER contenido_presentacion,
    ADD COLUMN unidad_base_codigo VARCHAR(20) NOT NULL DEFAULT 'UNIDAD' AFTER unidades_por_presentacion,
    ADD UNIQUE KEY uq_productos_ean13 (ean13),
    ADD KEY idx_productos_marca (marca),
    ADD KEY idx_productos_stock (stock, stock_minimo),
    ADD CONSTRAINT fk_productos_unidad_base
        FOREIGN KEY (unidad_base_codigo) REFERENCES unidades_venta(codigo);

ALTER TABLE configuracion_comercial
    ADD COLUMN dias_alerta_vencimiento INT NOT NULL DEFAULT 30 AFTER margen_maximo;

CREATE TABLE producto_lotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    numero_lote VARCHAR(80) NULL,
    cantidad_inicial INT NOT NULL,
    stock_actual INT NOT NULL,
    fecha_ingreso DATETIME NULL,
    fecha_vencimiento DATE NULL,
    estado ENUM('ACTIVO', 'AGOTADO', 'BAJA') NOT NULL DEFAULT 'ACTIVO',
    origen ENUM('HEREDADO', 'ALTA_INICIAL', 'INGRESO', 'CANCELACION') NOT NULL,
    usuario_id INT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lote_producto_numero (producto_id, numero_lote),
    KEY idx_lotes_fefo (producto_id, estado, fecha_vencimiento, fecha_ingreso),
    KEY idx_lotes_alertas (estado, fecha_vencimiento, stock_actual),
    CONSTRAINT fk_lote_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_lote_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT chk_lote_cantidades CHECK (cantidad_inicial >= 0 AND stock_actual >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Representa el stock previo sin inventar número, ingreso ni vencimiento.
INSERT INTO producto_lotes
    (producto_id, numero_lote, cantidad_inicial, stock_actual, fecha_ingreso,
     fecha_vencimiento, estado, origen, usuario_id)
SELECT id, NULL, stock, stock, NULL, NULL,
       IF(stock > 0, 'ACTIVO', 'AGOTADO'), 'HEREDADO', NULL
FROM productos
WHERE stock > 0;

CREATE TABLE venta_lotes (
    venta_id INT NOT NULL,
    lote_id BIGINT UNSIGNED NOT NULL,
    cantidad INT NOT NULL,
    PRIMARY KEY (venta_id, lote_id),
    KEY idx_venta_lotes_lote (lote_id),
    CONSTRAINT fk_venta_lote_venta FOREIGN KEY (venta_id) REFERENCES ventas(id),
    CONSTRAINT fk_venta_lote_lote FOREIGN KEY (lote_id) REFERENCES producto_lotes(id),
    CONSTRAINT chk_venta_lote_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE producto_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(40) NOT NULL,
    detalle TEXT NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_producto_historial (producto_id, fecha),
    CONSTRAINT fk_producto_historial_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_producto_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE stock_movimientos
    MODIFY COLUMN tipo ENUM(
        'ALTA_INICIAL', 'INGRESO', 'VENTA', 'MODIFICACION_VENTA',
        'CANCELACION', 'AJUSTE', 'BAJA', 'VENCIMIENTO'
    ) NOT NULL,
    ADD COLUMN lote_id BIGINT UNSIGNED NULL AFTER venta_id,
    ADD KEY idx_stock_lote (lote_id),
    ADD CONSTRAINT fk_stock_lote FOREIGN KEY (lote_id) REFERENCES producto_lotes(id) ON DELETE SET NULL;

ALTER TABLE ventas
    ADD COLUMN unidades_por_presentacion_snapshot INT NOT NULL DEFAULT 1 AFTER unidad_venta_plural_snapshot,
    ADD COLUMN unidad_base_snapshot VARCHAR(40) NOT NULL DEFAULT 'Unidad' AFTER unidades_por_presentacion_snapshot;

-- Solo se estructuran equivalencias explícitas ya conocidas del catálogo demo.
UPDATE productos SET unidades_por_presentacion = 24, unidad_base_codigo = 'UNIDAD' WHERE codigo IN ('ALF-JORG-CHO-24', 'ALF-GUAY-TRI-24');
UPDATE productos SET unidades_por_presentacion = 50, unidad_base_codigo = 'UNIDAD' WHERE codigo = 'GOL-PICO-DUL-50';
UPDATE productos SET unidades_por_presentacion = 20, unidad_base_codigo = 'UNIDAD' WHERE codigo IN ('CHO-LECH-100-20', 'SNA-PAPAS-CLA-20');
UPDATE productos SET unidades_por_presentacion = 12, unidad_base_codigo = 'UNIDAD' WHERE codigo IN ('BEB-COCA-500-12', 'BEB-AGUA-500-12');

UPDATE productos SET marca = 'Jorgito' WHERE codigo = 'ALF-JORG-CHO-24' AND marca IS NULL;
UPDATE productos SET marca = 'Guaymallén' WHERE codigo = 'ALF-GUAY-TRI-24' AND marca IS NULL;
UPDATE productos SET marca = 'Coca-Cola' WHERE codigo = 'BEB-COCA-500-12' AND marca IS NULL;

UPDATE ventas v
JOIN productos p ON p.id = v.producto_id
JOIN unidades_venta ub ON ub.codigo = p.unidad_base_codigo
SET v.unidades_por_presentacion_snapshot = p.unidades_por_presentacion,
    v.unidad_base_snapshot = ub.nombre_singular;
