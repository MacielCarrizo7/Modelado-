<?php
require_once "seguridad.php";
require_once "conexion.php";
requerirUsuarioJson(["admin", "vendedor", "cliente"]);
header("Content-Type: application/json; charset=UTF-8");

$pdo = Conexion::obtenerInstancia();
$ventaId = filter_input(INPUT_GET, "venta_id", FILTER_VALIDATE_INT) ?: 0;
if ($ventaId <= 0) {
    responderJson(["error" => "Venta inválida."], 400);
}

try {
    if ($_SESSION["usuario_rol"] === "cliente") {
        $permiso = $pdo->prepare("SELECT id FROM ventas WHERE id = ? AND cliente_id = ?");
        $permiso->execute([$ventaId, (int) $_SESSION["usuario_id"]]);
        if (!$permiso->fetch()) {
            responderJson(["error" => "No tenés permiso para consultar esta compra."], 403);
        }
    }

    $stmt = $pdo->prepare(
        "SELECT h.id, h.tipo, h.cantidad_anterior, h.cantidad_nueva,
                h.total_anterior, h.total_nuevo, h.estado_anterior, h.estado_nuevo,
                h.motivo, h.fecha, CONCAT_WS(' ', u.nombre, u.apellido) AS modificado_por
         FROM venta_historial h
         LEFT JOIN usuarios u ON h.usuario_id = u.id
         WHERE h.venta_id = ? ORDER BY h.fecha DESC, h.id DESC"
    );
    $stmt->execute([$ventaId]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Error en obtener_historial_venta: " . $e->getMessage());
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
?>
