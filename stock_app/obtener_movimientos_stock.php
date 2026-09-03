<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin", "vendedor"]);
$conexion = Conexion::obtenerInstancia();
$condicion = "";
$parametros = [];
if ($_SESSION["usuario_rol"] === "vendedor") {
    $condicion = "WHERE sm.usuario_id = ?";
    $parametros[] = (int) $_SESSION["usuario_id"];
}
$stmt = $conexion->prepare(
    "SELECT sm.id, sm.fecha, sm.tipo, sm.producto_nombre_snapshot AS producto,
            sm.cantidad_cambio, sm.stock_anterior, sm.stock_nuevo,
            sm.unidad_venta_snapshot AS unidad, sm.motivo,
            CONCAT_WS(' ', u.nombre, u.apellido) AS usuario
     FROM stock_movimientos sm
     LEFT JOIN usuarios u ON u.id = sm.usuario_id
     {$condicion}
     ORDER BY sm.fecha DESC, sm.id DESC LIMIT 200"
);
$stmt->execute($parametros);
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);

