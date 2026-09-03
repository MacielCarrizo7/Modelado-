USE control_stock;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE productos
    ADD COLUMN codigo VARCHAR(50) NULL AFTER id,
    ADD COLUMN categoria_id INT NULL AFTER nombre,
    ADD COLUMN contenido_presentacion VARCHAR(100) NULL AFTER unidad_venta_codigo,
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER contenido_presentacion,
    ADD UNIQUE KEY uq_productos_codigo (codigo),
    ADD KEY idx_productos_activo_categoria (activo, categoria_id),
    ADD CONSTRAINT fk_productos_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id);
