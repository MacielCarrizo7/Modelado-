-- Base de datos para el sistema de Stock y Ventas
CREATE DATABASE IF NOT EXISTS control_stock;
USE control_stock;

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

-- Productos en stock
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    presentacion ENUM('unidad', 'caja', 'bulto') NOT NULL DEFAULT 'unidad',
    unidades_por_bulto INT NOT NULL DEFAULT 1,
    fecha_vencimiento DATE NULL,
    proveedor VARCHAR(150) NULL
);

-- Historial de ventas
CREATE TABLE IF NOT EXISTS ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    producto_nombre VARCHAR(150) NOT NULL,
    tipo_venta ENUM('unidad', 'caja', 'bulto') NOT NULL DEFAULT 'unidad',
    cantidad_empaque INT NOT NULL DEFAULT 1,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NULL,
    descuento_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    descuento_monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
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

-- Auditoría de cambios y cancelaciones de ventas
CREATE TABLE IF NOT EXISTS venta_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(40) NOT NULL,
    cantidad_anterior INT NULL,
    cantidad_nueva INT NULL,
    total_anterior DECIMAL(10,2) NULL,
    total_nuevo DECIMAL(10,2) NULL,
    estado_anterior VARCHAR(20) NOT NULL,
    estado_nuevo VARCHAR(20) NOT NULL,
    motivo VARCHAR(500) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- Kardex de auditoría e historial de ingresos de productos
CREATE TABLE IF NOT EXISTS ingresos_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    producto_nombre VARCHAR(150) NOT NULL,
    cantidad INT NOT NULL,
    presentacion ENUM('unidad', 'caja', 'bulto') NOT NULL DEFAULT 'unidad',
    unidades_por_bulto INT NOT NULL DEFAULT 1,
    total_unidades INT NOT NULL,
    precio_unitario DECIMAL(10,2) NULL,
    proveedor VARCHAR(150) NULL,
    fecha_vencimiento DATE NULL,
    usuario_id INT NULL,
    motivo VARCHAR(255) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ingresos_producto (producto_id, fecha),
    INDEX idx_ingresos_proveedor (proveedor),
    INDEX idx_ingresos_fecha (fecha),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- Solicitudes de atención de clientes a vendedores / admin
CREATE TABLE IF NOT EXISTS solicitudes_vendedor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    mensaje VARCHAR(500) NULL,
    estado ENUM('PENDIENTE', 'ATENDIDA', 'CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_atencion DATETIME NULL,
    atendido_por INT NULL,
    INDEX idx_solicitudes_cliente (cliente_id, fecha),
    INDEX idx_solicitudes_estado (estado),
    FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (atendido_por) REFERENCES usuarios(id) ON DELETE SET NULL
);
