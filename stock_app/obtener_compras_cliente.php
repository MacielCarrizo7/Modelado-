<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["cliente"]);
header("Content-Type: application/json; charset=UTF-8");

$conexion = Conexion::obtenerInstancia();
$clienteId = (int) $_SESSION["usuario_id"];
$stmt = $conexion->prepare(
    "SELECT id, producto_nombre, cantidad, precio_referencial_snapshot,
            porcentaje_vendedor, bonificacion_porcentaje, precio_final_unitario,
            unidad_venta_snapshot, unidad_venta_plural_snapshot, precio_unitario, total, fecha, estado,
            fecha_modificacion, motivo_cancelacion
     FROM ventas
     WHERE cliente_id = ?
     ORDER BY fecha DESC, id DESC"
);
$stmt->execute([$clienteId]);
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);
