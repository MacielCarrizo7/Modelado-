<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin"]);
requerirCsrfJson();

$db = Conexion::obtenerInstancia();
$productoId = filter_input(INPUT_POST, "producto_id", FILTER_VALIDATE_INT) ?: 0;
$cantidad = filter_input(INPUT_POST, "cantidad", FILTER_VALIDATE_INT) ?: 0;
$numeroLote = trim($_POST["numero_lote"] ?? "");
$fechaIngreso = trim($_POST["fecha_ingreso"] ?? "");
$fechaVencimiento = trim($_POST["fecha_vencimiento"] ?? "");
$motivo = trim($_POST["motivo"] ?? "Ingreso de mercadería");
if ($productoId <= 0 || $cantidad <= 0 || mb_strlen($numeroLote) > 80 || mb_strlen($motivo) > 500
    || !fechaIsoOpcionalValida($fechaIngreso) || !fechaIsoOpcionalValida($fechaVencimiento)) {
    responderJson(["error" => "Revisá la cantidad y los datos del lote."], 400);
}
$numeroLote = $numeroLote !== "" ? $numeroLote : null;
$fechaVencimiento = $fechaVencimiento !== "" ? $fechaVencimiento : null;
$fechaIngresoSql = $fechaIngreso !== "" ? $fechaIngreso . " 00:00:00" : date("Y-m-d H:i:s");

try {
    $db->begin_transaction();
    $productoStmt = $db->prepare(
        "SELECT p.nombre,p.stock,p.activo,u.nombre_singular
         FROM productos p JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
         WHERE p.id=? FOR UPDATE"
    );
    $productoStmt->execute([$productoId]);
    $producto = $productoStmt->get_result()->fetch_assoc();
    if (!$producto || (int) $producto["activo"] !== 1) throw new DomainException("El producto no existe o está inactivo.");

    $lote = null;
    if ($numeroLote !== null) {
        $loteStmt = $db->prepare("SELECT id,cantidad_inicial,stock_actual,fecha_vencimiento FROM producto_lotes WHERE producto_id=? AND numero_lote=? FOR UPDATE");
        $loteStmt->execute([$productoId, $numeroLote]);
        $lote = $loteStmt->get_result()->fetch_assoc();
    }
    if ($lote) {
        if (($lote["fecha_vencimiento"] ?? null) !== $fechaVencimiento) throw new DomainException("El lote ya existe con otra fecha de vencimiento.");
        $loteId = (int) $lote["id"];
        $actualizarLote = $db->prepare("UPDATE producto_lotes SET cantidad_inicial=?,stock_actual=?,estado='ACTIVO' WHERE id=?");
        $actualizarLote->execute([(int) $lote["cantidad_inicial"] + $cantidad, (int) $lote["stock_actual"] + $cantidad, $loteId]);
    } else {
        $crearLote = $db->prepare(
            "INSERT INTO producto_lotes
             (producto_id,numero_lote,cantidad_inicial,stock_actual,fecha_ingreso,fecha_vencimiento,estado,origen,usuario_id)
             VALUES (?,?,?,?,?,?,'ACTIVO','INGRESO',?)"
        );
        $crearLote->execute([$productoId,$numeroLote,$cantidad,$cantidad,$fechaIngresoSql,$fechaVencimiento,(int) $_SESSION["usuario_id"]]);
        $loteId = (int) $db->insert_id;
    }
    $stockAnterior = (int) $producto["stock"];
    $stockNuevo = $stockAnterior + $cantidad;
    $actualizarProducto = $db->prepare("UPDATE productos SET stock=? WHERE id=?");
    $actualizarProducto->execute([$stockNuevo,$productoId]);
    registrarMovimientoStock(
        $db,$productoId,$producto["nombre"],(int) $_SESSION["usuario_id"],null,"INGRESO",
        $cantidad,$stockAnterior,$stockNuevo,$producto["nombre_singular"],$motivo,$loteId
    );
    registrarHistorialProducto($db,$productoId,(int) $_SESSION["usuario_id"],"INGRESO_STOCK","Lote " . ($numeroLote ?? "sin número") . ": +{$cantidad}.");
    $db->commit();
    responderJson(["success"=>true,"stock_nuevo"=>$stockNuevo,"lote_id"=>$loteId],201);
} catch (DomainException $e) {
    $db->rollback(); responderJson(["error"=>$e->getMessage()],400);
} catch (Throwable $e) {
    $db->rollback(); error_log("Ingreso de stock: ".$e->getMessage()); responderJson(["error"=>"No se pudo ingresar la mercadería."],500);
}
