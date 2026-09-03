-- Base de datos para el sistema de Stock y Ventas
CREATE DATABASE IF NOT EXISTS control_stock;
USE control_stock;
SET NAMES utf8mb4;

-- Usuarios que pueden loguearse al sistema
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NULL,
    password VARCHAR(255) NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rol ENUM('admin', 'vendedor', 'cliente') NOT NULL DEFAULT 'cliente',
    totp_secret_encrypted TEXT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_confirmed_at DATETIME NULL,
    totp_last_timeslice BIGINT UNSIGNED NULL
);

-- Catálogo configurable de unidades comerciales (sin conversiones implícitas)
CREATE TABLE IF NOT EXISTS unidades_venta (
    codigo VARCHAR(20) PRIMARY KEY,
    nombre_singular VARCHAR(40) NOT NULL,
    nombre_plural VARCHAR(40) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
);
INSERT IGNORE INTO unidades_venta (codigo, nombre_singular, nombre_plural) VALUES
('UNIDAD', 'Unidad', 'Unidades'), ('CAJA', 'Caja', 'Cajas'),
('BULTO', 'Bulto', 'Bultos'), ('PACK', 'Pack', 'Packs'),
('DOCENA', 'Docena', 'Docenas'), ('DISPLAY', 'Display', 'Displays'),
('PAQUETE', 'Paquete', 'Paquetes'), ('BOLSA', 'Bolsa', 'Bolsas');

CREATE TABLE IF NOT EXISTS categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorias_nombre (nombre)
);

-- Productos en stock; stock siempre se expresa en su unidad comercial
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NULL UNIQUE,
    ean13 CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    marca VARCHAR(100) NULL,
    descripcion TEXT NULL,
    fecha_alta DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_baja DATETIME NULL,
    categoria_id INT NULL,
    precio DECIMAL(12,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 0,
    unidad_venta_codigo VARCHAR(20) NOT NULL DEFAULT 'UNIDAD',
    contenido_presentacion VARCHAR(100) NULL,
    unidades_por_presentacion INT NOT NULL DEFAULT 1,
    unidad_base_codigo VARCHAR(20) NOT NULL DEFAULT 'UNIDAD',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (unidad_venta_codigo) REFERENCES unidades_venta(codigo),
    FOREIGN KEY (unidad_base_codigo) REFERENCES unidades_venta(codigo),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
);

CREATE TABLE IF NOT EXISTS producto_lotes (
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
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CHECK (cantidad_inicial >= 0 AND stock_actual >= 0)
);

CREATE TABLE IF NOT EXISTS producto_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(40) NOT NULL,
    detalle TEXT NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS configuracion_comercial (
    id TINYINT UNSIGNED PRIMARY KEY,
    margen_maximo DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    dias_alerta_vencimiento INT NOT NULL DEFAULT 30,
    fecha_modificacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO configuracion_comercial (id, margen_maximo) VALUES (1, 100.00);

CREATE TABLE IF NOT EXISTS bonificaciones_cantidad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cantidad_desde INT NOT NULL,
    cantidad_hasta INT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Historial de ventas
CREATE TABLE IF NOT EXISTS ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    producto_nombre VARCHAR(150) NOT NULL,
    cantidad INT NOT NULL,
    precio_referencial_snapshot DECIMAL(12,2) NOT NULL,
    porcentaje_vendedor DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    bonificacion_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    precio_final_unitario DECIMAL(12,2) NOT NULL,
    unidad_venta_snapshot VARCHAR(40) NOT NULL,
    unidad_venta_plural_snapshot VARCHAR(40) NOT NULL,
    unidades_por_presentacion_snapshot INT NOT NULL DEFAULT 1,
    unidad_base_snapshot VARCHAR(40) NOT NULL DEFAULT 'Unidad',
    precio_unitario DECIMAL(12,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    usuario_id INT,
    cliente_id INT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('ACTIVA', 'MODIFICADA', 'CANCELADA') NOT NULL DEFAULT 'ACTIVA',
    fecha_modificacion DATETIME NULL,
    motivo_cancelacion VARCHAR(500) NULL,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS venta_lotes (
    venta_id INT NOT NULL,
    lote_id BIGINT UNSIGNED NOT NULL,
    cantidad INT NOT NULL,
    PRIMARY KEY (venta_id, lote_id),
    FOREIGN KEY (venta_id) REFERENCES ventas(id),
    FOREIGN KEY (lote_id) REFERENCES producto_lotes(id),
    CHECK (cantidad > 0)
);

-- Auditoría de cambios y cancelaciones de ventas
CREATE TABLE IF NOT EXISTS venta_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(40) NOT NULL,
    cantidad_anterior INT NULL,
    cantidad_nueva INT NULL,
    bonificacion_anterior DECIMAL(5,2) NULL,
    bonificacion_nueva DECIMAL(5,2) NULL,
    precio_final_anterior DECIMAL(12,2) NULL,
    precio_final_nuevo DECIMAL(12,2) NULL,
    total_anterior DECIMAL(14,2) NULL,
    total_nuevo DECIMAL(14,2) NULL,
    estado_anterior VARCHAR(20) NOT NULL,
    estado_nuevo VARCHAR(20) NOT NULL,
    motivo VARCHAR(500) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venta_id) REFERENCES ventas(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS stock_movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NULL,
    producto_nombre_snapshot VARCHAR(150) NOT NULL,
    usuario_id INT NULL,
    venta_id INT NULL,
    lote_id BIGINT UNSIGNED NULL,
    tipo ENUM('ALTA_INICIAL', 'INGRESO', 'VENTA', 'MODIFICACION_VENTA', 'CANCELACION', 'AJUSTE', 'BAJA', 'VENCIMIENTO') NOT NULL,
    cantidad_cambio INT NOT NULL,
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,
    unidad_venta_snapshot VARCHAR(40) NOT NULL,
    motivo VARCHAR(500) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas(id),
    FOREIGN KEY (lote_id) REFERENCES producto_lotes(id) ON DELETE SET NULL
);
