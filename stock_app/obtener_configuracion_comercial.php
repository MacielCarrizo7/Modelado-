<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin", "vendedor"]);

$conexion = Conexion::obtenerInstancia();
$unidades = $conexion->query(
    "SELECT codigo, nombre_singular, nombre_plural FROM unidades_venta WHERE activo = 1 ORDER BY nombre_singular"
)->fetch_all(MYSQLI_ASSOC);
$reglas = $conexion->query(
    "SELECT id, cantidad_desde, cantidad_hasta, porcentaje, activo
     FROM bonificaciones_cantidad WHERE activo = 1 ORDER BY cantidad_desde"
)->fetch_all(MYSQLI_ASSOC);
$configuracion = $conexion->query(
    "SELECT margen_maximo, dias_alerta_vencimiento FROM configuracion_comercial WHERE id = 1"
)->fetch_assoc();
$categorias = $conexion->query(
    "SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre"
)->fetch_all(MYSQLI_ASSOC);

responderJson([
    "margen_maximo" => $configuracion["margen_maximo"] ?? "100.00",
    "dias_alerta_vencimiento" => $configuracion["dias_alerta_vencimiento"] ?? "30",
    "unidades" => $unidades,
    "categorias" => $categorias,
    "bonificaciones" => $reglas,
]);
