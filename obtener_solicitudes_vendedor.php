<?php
require_once "seguridad.php";
require_once "conexion.php";
requerirUsuarioJson(["admin", "vendedor", "cliente"]);
header("Content-Type: application/json; charset=UTF-8");

$pdo = Conexion::obtenerInstancia();
$rol = $_SESSION["usuario_rol"];
$usuarioId = (int) $_SESSION["usuario_id"];

try {
    if ($rol === "cliente") {
        $stmt = $pdo->prepare(
            "SELECT id, mensaje, estado, fecha, fecha_atencion
             FROM solicitudes_vendedor
             WHERE cliente_id = ?
             ORDER BY fecha DESC LIMIT 5"
        );
        $stmt->execute([$usuarioId]);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "SELECT s.id, s.cliente_id, s.mensaje, s.estado, s.fecha, s.fecha_atencion, s.atendido_por,
                   c.dni AS cliente_dni,
                   CONCAT_WS(' ', c.nombre, c.apellido) AS cliente_nombre,
                   CONCAT_WS(' ', a.nombre, a.apellido) AS atendido_por_nombre
            FROM solicitudes_vendedor s
            JOIN usuarios c ON s.cliente_id = c.id
            LEFT JOIN usuarios a ON s.atendido_por = a.id
            ORDER BY CASE WHEN s.estado = 'PENDIENTE' THEN 0 ELSE 1 END, s.fecha DESC
            LIMIT 50";

    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Error en obtener_solicitudes_vendedor: " . $e->getMessage());
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
?>
