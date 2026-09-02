<?php
require_once "seguridad.php";
require_once "conexion.php";
requerirUsuarioJson(["admin", "vendedor"]);
header("Content-Type: application/json; charset=UTF-8");

$pdo = Conexion::obtenerInstancia();
try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, apellido, dni FROM usuarios WHERE rol = 'cliente' AND activo = 1 ORDER BY apellido, nombre"
    );
    $stmt->execute();
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Error en obtener_clientes: " . $e->getMessage());
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
?>
