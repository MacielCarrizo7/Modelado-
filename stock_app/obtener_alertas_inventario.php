<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin"]);

$db = Conexion::obtenerInstancia();
$dias = (int) ($db->query("SELECT dias_alerta_vencimiento FROM configuracion_comercial WHERE id=1")->fetch_assoc()["dias_alerta_vencimiento"] ?? 30);
$sinStock = $db->query(
    "SELECT id,nombre,stock,stock_minimo,unidad_venta_codigo FROM productos WHERE activo=1 AND stock=0 ORDER BY nombre"
)->fetch_all(MYSQLI_ASSOC);
$stockBajo = $db->query(
    "SELECT id,nombre,stock,stock_minimo,unidad_venta_codigo FROM productos
     WHERE activo=1 AND stock>0 AND stock<=stock_minimo ORDER BY stock, nombre"
)->fetch_all(MYSQLI_ASSOC);
$vencidos = $db->query(
    "SELECT l.id lote_id,l.producto_id,p.nombre,l.numero_lote,l.stock_actual,l.fecha_vencimiento,u.nombre_plural unidad
     FROM producto_lotes l JOIN productos p ON p.id=l.producto_id
     JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
     WHERE p.activo=1 AND l.estado='ACTIVO' AND l.stock_actual>0 AND l.fecha_vencimiento<CURDATE()
     ORDER BY l.fecha_vencimiento,p.nombre"
)->fetch_all(MYSQLI_ASSOC);
$proximosStmt = $db->prepare(
    "SELECT l.id lote_id,l.producto_id,p.nombre,l.numero_lote,l.stock_actual,l.fecha_vencimiento,u.nombre_plural unidad
     FROM producto_lotes l JOIN productos p ON p.id=l.producto_id
     JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
     WHERE p.activo=1 AND l.estado='ACTIVO' AND l.stock_actual>0
       AND l.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
     ORDER BY l.fecha_vencimiento,p.nombre"
);
$proximosStmt->execute([$dias]);
$proximos = $proximosStmt->get_result()->fetch_all(MYSQLI_ASSOC);
responderJson([
    "dias_alerta_vencimiento" => $dias,
    "contadores" => [
        "sin_stock" => count($sinStock), "stock_bajo" => count($stockBajo),
        "proximos" => count($proximos), "vencidos" => count($vencidos),
    ],
    "sin_stock" => $sinStock,
    "stock_bajo" => $stockBajo,
    "proximos" => $proximos,
    "vencidos" => $vencidos,
]);
