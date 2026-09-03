<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin", "vendedor", "cliente"]);
requerirCsrfJson();

$conexion = Conexion::obtenerInstancia();
$ventaId = filter_input(INPUT_POST, "venta_id", FILTER_VALIDATE_INT) ?: 0;
$accion = $_POST["accion"] ?? "";
$motivo = trim($_POST["motivo"] ?? "");
if ($ventaId <= 0 || !in_array($accion, ["cancelar", "modificar_cantidad"], true)) responderJson(["error" => "Solicitud inválida."], 400);
if (mb_strlen($motivo) > 500) responderJson(["error" => "El motivo es demasiado extenso."], 400);

try {
    $conexion->begin_transaction();
    $ventaStmt = $conexion->prepare(
        "SELECT id, producto_id, producto_nombre, cantidad, precio_referencial_snapshot,
                porcentaje_vendedor, bonificacion_porcentaje, precio_final_unitario,
                unidad_venta_snapshot, total, usuario_id, cliente_id, estado
         FROM ventas WHERE id = ? FOR UPDATE"
    );
    $ventaStmt->execute([$ventaId]);
    $venta = $ventaStmt->get_result()->fetch_assoc();
    if (!$venta) { $conexion->rollback(); responderJson(["error" => "Venta no encontrada."], 404); }

    $rol = $_SESSION["usuario_rol"];
    $usuarioId = (int) $_SESSION["usuario_id"];
    if (($rol === "cliente" && (int) $venta["cliente_id"] !== $usuarioId)
        || ($rol === "vendedor" && (int) $venta["usuario_id"] !== $usuarioId)) {
        $conexion->rollback();
        responderJson(["error" => "No tenés permiso para modificar esta venta."], 403);
    }
    if ($venta["estado"] === "CANCELADA") {
        $conexion->commit();
        if ($accion === "cancelar") responderJson(["success" => true, "already_cancelled" => true]);
        responderJson(["error" => "Una venta cancelada no puede modificarse."], 409);
    }

    $productoStmt = $conexion->prepare("SELECT nombre, stock FROM productos WHERE id = ? FOR UPDATE");
    $productoStmt->execute([(int) $venta["producto_id"]]);
    $producto = $productoStmt->get_result()->fetch_assoc();
    if (!$producto) throw new RuntimeException("El producto asociado ya no existe.");

    $estadoAnterior = $venta["estado"];
    $cantidadAnterior = (int) $venta["cantidad"];
    $totalAnterior = (string) $venta["total"];
    $stockAnterior = (int) $producto["stock"];
    $prefijoActor = strtoupper($rol);

    if ($accion === "cancelar") {
        devolverCantidadALotes($conexion, (int) $venta["producto_id"], $ventaId, $cantidadAnterior, $usuarioId);
        $stockNuevo = $stockAnterior + $cantidadAnterior;
        $stock = $conexion->prepare("UPDATE productos SET stock = ? WHERE id = ?");
        $stock->execute([$stockNuevo, (int) $venta["producto_id"]]);
        $actualizar = $conexion->prepare(
            "UPDATE ventas SET estado = 'CANCELADA', fecha_modificacion = NOW(), motivo_cancelacion = ? WHERE id = ?"
        );
        $actualizar->execute([$motivo !== "" ? $motivo : null, $ventaId]);
        $historial = $conexion->prepare(
            "INSERT INTO venta_historial
             (venta_id, usuario_id, tipo, cantidad_anterior, cantidad_nueva,
              bonificacion_anterior, bonificacion_nueva, precio_final_anterior,
              precio_final_nuevo, total_anterior, total_nuevo, estado_anterior, estado_nuevo, motivo)
             VALUES (?, ?, ?, ?, 0, ?, 0, ?, 0, ?, 0, ?, 'CANCELADA', ?)"
        );
        $historial->execute([
            $ventaId, $usuarioId, $prefijoActor . "_CANCELA", $cantidadAnterior,
            $venta["bonificacion_porcentaje"], $venta["precio_final_unitario"],
            $totalAnterior, $estadoAnterior, $motivo !== "" ? $motivo : null,
        ]);
        registrarMovimientoStock(
            $conexion, (int) $venta["producto_id"], $venta["producto_nombre"], $usuarioId, $ventaId,
            "CANCELACION", $cantidadAnterior, $stockAnterior, $stockNuevo,
            $venta["unidad_venta_snapshot"], $motivo !== "" ? $motivo : "Cancelación de venta #{$ventaId}"
        );
        $conexion->commit();
        responderJson(["success" => true, "estado" => "CANCELADA"]);
    }

    $cantidadNueva = filter_input(INPUT_POST, "cantidad", FILTER_VALIDATE_INT) ?: 0;
    if ($cantidadNueva <= 0) { $conexion->rollback(); responderJson(["error" => "La nueva cantidad debe ser mayor que cero."], 400); }
    if ($cantidadNueva === $cantidadAnterior) { $conexion->rollback(); responderJson(["error" => "La cantidad no cambió."], 400); }
    $diferencia = $cantidadNueva - $cantidadAnterior;
    if ($diferencia > 0 && $stockAnterior < $diferencia) {
        $conexion->rollback(); responderJson(["error" => "No hay stock suficiente para aumentar la cantidad."], 400);
    }
    if ($diferencia > 0 && stockVendible($conexion, (int) $venta["producto_id"], true) < $diferencia) {
        $conexion->rollback(); responderJson(["error" => "No hay stock vigente suficiente para aumentar la cantidad."], 400);
    }

    $bonificacionNueva = obtenerBonificacion($conexion, $cantidadNueva);
    $calculo = calcularPrecioComercial(
        (string) $venta["precio_referencial_snapshot"],
        (string) $venta["porcentaje_vendedor"],
        $bonificacionNueva,
        $cantidadNueva
    );
    $stockNuevo = $stockAnterior - $diferencia;
    if ($diferencia > 0) {
        asignarLotesFefo($conexion, (int) $venta["producto_id"], $ventaId, $diferencia);
    } else {
        devolverCantidadALotes($conexion, (int) $venta["producto_id"], $ventaId, -$diferencia, $usuarioId);
    }
    $ajustarStock = $conexion->prepare("UPDATE productos SET stock = ? WHERE id = ?");
    $ajustarStock->execute([$stockNuevo, (int) $venta["producto_id"]]);
    $actualizar = $conexion->prepare(
        "UPDATE ventas SET cantidad = ?, bonificacion_porcentaje = ?, precio_final_unitario = ?,
                precio_unitario = ?, total = ?, estado = 'MODIFICADA',
                fecha_modificacion = NOW(), motivo_cancelacion = NULL WHERE id = ?"
    );
    $actualizar->execute([
        $cantidadNueva, $calculo["bonificacion_porcentaje"], $calculo["precio_final_unitario"],
        $calculo["precio_final_unitario"], $calculo["total"], $ventaId,
    ]);
    $historial = $conexion->prepare(
        "INSERT INTO venta_historial
         (venta_id, usuario_id, tipo, cantidad_anterior, cantidad_nueva,
          bonificacion_anterior, bonificacion_nueva, precio_final_anterior,
          precio_final_nuevo, total_anterior, total_nuevo, estado_anterior, estado_nuevo, motivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MODIFICADA', ?)"
    );
    $historial->execute([
        $ventaId, $usuarioId, $prefijoActor . "_MODIFICA_CANTIDAD", $cantidadAnterior, $cantidadNueva,
        $venta["bonificacion_porcentaje"], $calculo["bonificacion_porcentaje"],
        $venta["precio_final_unitario"], $calculo["precio_final_unitario"],
        $totalAnterior, $calculo["total"], $estadoAnterior, $motivo !== "" ? $motivo : null,
    ]);
    registrarMovimientoStock(
        $conexion, (int) $venta["producto_id"], $venta["producto_nombre"], $usuarioId, $ventaId,
        "MODIFICACION_VENTA", -$diferencia, $stockAnterior, $stockNuevo,
        $venta["unidad_venta_snapshot"], $motivo !== "" ? $motivo : "Cambio de cantidad en venta #{$ventaId}"
    );
    $conexion->commit();
    responderJson(["success" => true, "estado" => "MODIFICADA", "calculo" => $calculo]);
} catch (Throwable $e) {
    try { $conexion->rollback(); } catch (Throwable $ignorado) {}
    error_log("Error al modificar venta: " . $e->getMessage());
    responderJson(["error" => "No se pudo actualizar la venta."], 500);
}
