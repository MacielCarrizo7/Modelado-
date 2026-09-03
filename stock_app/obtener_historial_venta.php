<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin", "vendedor", "cliente"]);
header("Content-Type: application/json; charset=UTF-8");

$conexion = Conexion::obtenerInstancia();
$ventaId = filter_input(INPUT_GET, "venta_id", FILTER_VALIDATE_INT) ?: 0;
if ($ventaId <= 0) {
    responderJson(["error" => "Venta inválida."], 400);
}

if ($_SESSION["usuario_rol"] === "cliente") {
    $permiso = $conexion->prepare("SELECT id FROM ventas WHERE id = ? AND cliente_id = ?");
    $permiso->execute([$ventaId, (int) $_SESSION["usuario_id"]]);
    if (!$permiso->get_result()->fetch_assoc()) {
        responderJson(["error" => "No tenés permiso para consultar esta compra."], 403);
    }
}
if ($_SESSION["usuario_rol"] === "vendedor") {
    $permiso = $conexion->prepare("SELECT id FROM ventas WHERE id = ? AND usuario_id = ?");
    $permiso->execute([$ventaId, (int) $_SESSION["usuario_id"]]);
    if (!$permiso->get_result()->fetch_assoc()) {
        responderJson(["error" => "No tenés permiso para consultar esta venta."], 403);
    }
}

$stmt = $conexion->prepare(
    "SELECT h.id, h.tipo, h.cantidad_anterior, h.cantidad_nueva,
            h.bonificacion_anterior, h.bonificacion_nueva,
            h.precio_final_anterior, h.precio_final_nuevo,
            h.total_anterior, h.total_nuevo, h.estado_anterior, h.estado_nuevo,
            h.motivo, h.fecha, CONCAT_WS(' ', u.nombre, u.apellido) AS modificado_por
     FROM venta_historial h
     LEFT JOIN usuarios u ON h.usuario_id = u.id
     WHERE h.venta_id = ? ORDER BY h.fecha DESC, h.id DESC"
);
$stmt->execute([$ventaId]);
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);
