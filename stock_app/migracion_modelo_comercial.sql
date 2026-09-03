-- Modelo comercial incremental: no elimina ni recrea datos existentes.
USE control_stock;

CREATE TABLE unidades_venta (
    codigo VARCHAR(20) PRIMARY KEY,
    nombre_singular VARCHAR(40) NOT NULL,
    nombre_plural VARCHAR(40) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO unidades_venta (codigo, nombre_singular, nombre_plural) VALUES
('UNIDAD', 'Unidad', 'Unidades'),
('CAJA', 'Caja', 'Cajas'),
('BULTO', 'Bulto', 'Bultos'),
('PACK', 'Pack', 'Packs'),
('DOCENA', 'Docena', 'Docenas'),
('DISPLAY', 'Display', 'Displays'),
('PAQUETE', 'Paquete', 'Paquetes');

ALTER TABLE productos
    MODIFY COLUMN precio DECIMAL(12,2) NOT NULL,
    ADD COLUMN unidad_venta_codigo VARCHAR(20) NOT NULL DEFAULT 'UNIDAD' AFTER stock,
    ADD INDEX idx_productos_unidad (unidad_venta_codigo),
    ADD CONSTRAINT fk_productos_unidad
        FOREIGN KEY (unidad_venta_codigo) REFERENCES unidades_venta(codigo);

CREATE TABLE configuracion_comercial (
    id TINYINT UNSIGNED PRIMARY KEY,
    margen_maximo DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    fecha_modificacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_configuracion_id CHECK (id = 1),
    CONSTRAINT chk_margen_maximo CHECK (margen_maximo >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO configuracion_comercial (id, margen_maximo) VALUES (1, 100.00);

CREATE TABLE bonificaciones_cantidad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cantidad_desde INT NOT NULL,
    cantidad_hasta INT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_bonificacion_desde CHECK (cantidad_desde > 0),
    CONSTRAINT chk_bonificacion_hasta CHECK (cantidad_hasta IS NULL OR cantidad_hasta >= cantidad_desde),
    CONSTRAINT chk_bonificacion_porcentaje CHECK (porcentaje >= 0 AND porcentaje <= 100),
    INDEX idx_bonificacion_rango (activo, cantidad_desde, cantidad_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE ventas
    MODIFY COLUMN precio_unitario DECIMAL(12,2) NOT NULL,
    MODIFY COLUMN total DECIMAL(14,2) NOT NULL,
    ADD COLUMN precio_referencial_snapshot DECIMAL(12,2) NULL AFTER cantidad,
    ADD COLUMN porcentaje_vendedor DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER precio_referencial_snapshot,
    ADD COLUMN bonificacion_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER porcentaje_vendedor,
    ADD COLUMN precio_final_unitario DECIMAL(12,2) NULL AFTER bonificacion_porcentaje,
    ADD COLUMN unidad_venta_snapshot VARCHAR(40) NULL AFTER precio_final_unitario,
    ADD COLUMN unidad_venta_plural_snapshot VARCHAR(40) NULL AFTER unidad_venta_snapshot,
    ADD INDEX idx_ventas_vendedor_fecha (usuario_id, fecha);

UPDATE ventas
SET precio_referencial_snapshot = precio_unitario,
    precio_final_unitario = precio_unitario,
    unidad_venta_snapshot = 'Unidad',
    unidad_venta_plural_snapshot = 'Unidades'
WHERE precio_referencial_snapshot IS NULL
   OR precio_final_unitario IS NULL
   OR unidad_venta_snapshot IS NULL
   OR unidad_venta_plural_snapshot IS NULL;

ALTER TABLE ventas
    MODIFY COLUMN precio_referencial_snapshot DECIMAL(12,2) NOT NULL,
    MODIFY COLUMN precio_final_unitario DECIMAL(12,2) NOT NULL,
    MODIFY COLUMN unidad_venta_snapshot VARCHAR(40) NOT NULL,
    MODIFY COLUMN unidad_venta_plural_snapshot VARCHAR(40) NOT NULL;

ALTER TABLE venta_historial
    MODIFY COLUMN total_anterior DECIMAL(14,2) NULL,
    MODIFY COLUMN total_nuevo DECIMAL(14,2) NULL,
    ADD COLUMN bonificacion_anterior DECIMAL(5,2) NULL AFTER cantidad_nueva,
    ADD COLUMN bonificacion_nueva DECIMAL(5,2) NULL AFTER bonificacion_anterior,
    ADD COLUMN precio_final_anterior DECIMAL(12,2) NULL AFTER bonificacion_nueva,
    ADD COLUMN precio_final_nuevo DECIMAL(12,2) NULL AFTER precio_final_anterior;

CREATE TABLE stock_movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NULL,
    producto_nombre_snapshot VARCHAR(150) NOT NULL,
    usuario_id INT NULL,
    venta_id INT NULL,
    tipo ENUM('VENTA', 'CANCELACION', 'MODIFICACION_VENTA', 'INGRESO', 'AJUSTE') NOT NULL,
    cantidad_cambio INT NOT NULL,
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,
    unidad_venta_snapshot VARCHAR(40) NOT NULL,
    motivo VARCHAR(500) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_producto_fecha (producto_id, fecha),
    INDEX idx_stock_usuario_fecha (usuario_id, fecha),
    INDEX idx_stock_venta (venta_id),
    CONSTRAINT fk_stock_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_venta FOREIGN KEY (venta_id) REFERENCES ventas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
