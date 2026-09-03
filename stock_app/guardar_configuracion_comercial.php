<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
requerirUsuarioJson(["admin"]);
requerirCsrfJson();

$conexion = Conexion::obtenerInstancia();
$accion = $_POST["accion"] ?? "";

if ($accion === "margen_maximo") {
    $valor = trim($_POST["margen_maximo"] ?? "");
    if (!decimalValido($valor, 4)) {
        responderJson(["error" => "Ingresá un margen máximo válido."], 400);
    }
    $normalizado = decimalNormalizado($valor);
    $stmt = $conexion->prepare("UPDATE configuracion_comercial SET margen_maximo = ? WHERE id = 1");
    $stmt->execute([$normalizado]);
    responderJson(["success" => true]);
}

if ($accion === "dias_alerta_vencimiento") {
    $dias = filter_input(INPUT_POST, "dias_alerta_vencimiento", FILTER_VALIDATE_INT);
    if ($dias === false || $dias < 1 || $dias > 365) {
        responderJson(["error" => "Los días de anticipación deben estar entre 1 y 365."], 400);
    }
    $stmt = $conexion->prepare("UPDATE configuracion_comercial SET dias_alerta_vencimiento = ? WHERE id = 1");
    $stmt->execute([$dias]);
    responderJson(["success" => true]);
}

if ($accion === "crear_bonificacion") {
    $desde = filter_input(INPUT_POST, "cantidad_desde", FILTER_VALIDATE_INT) ?: 0;
    $hastaTexto = trim($_POST["cantidad_hasta"] ?? "");
    $hasta = $hastaTexto === "" ? null : filter_var($hastaTexto, FILTER_VALIDATE_INT);
    $porcentajeTexto = trim($_POST["porcentaje"] ?? "");
    if ($desde <= 0 || ($hasta !== null && ($hasta === false || $hasta < $desde))
        || !decimalValido($porcentajeTexto, 3) || decimalAEntero($porcentajeTexto) > 10000) {
        responderJson(["error" => "La regla de bonificación no es válida."], 400);
    }
    $solapa = $conexion->prepare(
        "SELECT id FROM bonificaciones_cantidad
         WHERE activo = 1
           AND cantidad_desde <= COALESCE(?, 2147483647)
           AND COALESCE(cantidad_hasta, 2147483647) >= ?
         LIMIT 1"
    );
    $solapa->execute([$hasta, $desde]);
    if ($solapa->get_result()->fetch_assoc()) {
        responderJson(["error" => "El rango se superpone con otra bonificación activa."], 409);
    }
    $porcentaje = decimalNormalizado($porcentajeTexto);
    $stmt = $conexion->prepare(
        "INSERT INTO bonificaciones_cantidad (cantidad_desde, cantidad_hasta, porcentaje) VALUES (?, ?, ?)"
    );
    $stmt->execute([$desde, $hasta, $porcentaje]);
    responderJson(["success" => true], 201);
}

if ($accion === "eliminar_bonificacion") {
    $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT) ?: 0;
    if ($id <= 0) responderJson(["error" => "Regla inválida."], 400);
    $stmt = $conexion->prepare("UPDATE bonificaciones_cantidad SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);
    responderJson(["success" => true]);
}

responderJson(["error" => "Acción comercial inválida."], 400);
