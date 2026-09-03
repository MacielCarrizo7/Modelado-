<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin"]);
$conexion = Conexion::obtenerInstancia();
$resultado = $conexion->query(
    "SELECT id, dni, nombre, apellido FROM usuarios WHERE rol = 'vendedor' ORDER BY apellido, nombre"
);
echo json_encode($resultado->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);

