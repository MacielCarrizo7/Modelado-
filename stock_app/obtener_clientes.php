<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin", "vendedor"]);
header("Content-Type: application/json; charset=UTF-8");

$conexion = Conexion::obtenerInstancia();
$stmt = $conexion->prepare(
    "SELECT id, nombre, apellido, dni FROM usuarios WHERE rol = 'cliente' ORDER BY apellido, nombre"
);
$stmt->execute();
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);
