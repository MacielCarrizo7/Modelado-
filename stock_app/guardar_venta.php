<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin", "vendedor"]);
requerirCsrfJson();

$db = Conexion::obtenerInstancia();
$productoId = filter_input(INPUT_POST, "producto_id", FILTER_VALIDATE_INT) ?: 0;
$clienteId = filter_input(INPUT_POST, "cliente_id", FILTER_VALIDATE_INT) ?: 0;
$cantidad = filter_input(INPUT_POST, "cantidad", FILTER_VALIDATE_INT) ?: 0;
$margenTexto = trim($_POST["porcentaje_vendedor"] ?? "0");
if ($productoId<=0 || $clienteId<=0 || $cantidad<=0 || !decimalValido($margenTexto,4)) responderJson(["error"=>"Seleccioná producto, cliente, cantidad y margen válidos."],400);
$margen=decimalNormalizado($margenTexto);
if(decimalAEntero($margen)>decimalAEntero(obtenerMargenMaximo($db))) responderJson(["error"=>"El margen supera el máximo permitido por el administrador."],400);

try{
    $db->begin_transaction();
    $cliente=$db->prepare("SELECT id FROM usuarios WHERE id=? AND rol='cliente' FOR UPDATE"); $cliente->execute([$clienteId]);
    if(!$cliente->get_result()->fetch_assoc()) throw new DomainException("El cliente seleccionado no existe.");
    $pstmt=$db->prepare(
        "SELECT p.nombre,p.precio,p.stock,p.unidades_por_presentacion,
                u.nombre_singular,u.nombre_plural,ub.nombre_singular unidad_base_singular
         FROM productos p JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
         JOIN unidades_venta ub ON ub.codigo=p.unidad_base_codigo
         WHERE p.id=? AND p.activo=1 FOR UPDATE"
    );
    $pstmt->execute([$productoId]); $producto=$pstmt->get_result()->fetch_assoc();
    if(!$producto) throw new DomainException("Producto no encontrado o inactivo.");
    $vendible=stockVendible($db,$productoId,true);
    if($vendible<$cantidad) throw new DomainException("No hay stock vigente suficiente. Disponible para venta: {$vendible}.");
    $bonificacion=obtenerBonificacion($db,$cantidad);
    $calculo=calcularPrecioComercial((string)$producto["precio"],$margen,$bonificacion,$cantidad);
    $usuarioId=(int)$_SESSION["usuario_id"];
    $insert=$db->prepare(
        "INSERT INTO ventas
         (producto_id,producto_nombre,cantidad,precio_referencial_snapshot,porcentaje_vendedor,
          bonificacion_porcentaje,precio_final_unitario,unidad_venta_snapshot,
          unidad_venta_plural_snapshot,unidades_por_presentacion_snapshot,unidad_base_snapshot,
          precio_unitario,total,usuario_id,cliente_id,estado)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVA')"
    );
    $insert->execute([
        $productoId,$producto["nombre"],$cantidad,$calculo["precio_referencial"],$calculo["porcentaje_vendedor"],
        $calculo["bonificacion_porcentaje"],$calculo["precio_final_unitario"],$producto["nombre_singular"],
        $producto["nombre_plural"],$producto["unidades_por_presentacion"],$producto["unidad_base_singular"],
        $calculo["precio_final_unitario"],$calculo["total"],$usuarioId,$clienteId
    ]);
    $ventaId=(int)$db->insert_id;
    asignarLotesFefo($db,$productoId,$ventaId,$cantidad);
    $stockAnterior=(int)$producto["stock"]; $stockNuevo=$stockAnterior-$cantidad;
    $up=$db->prepare("UPDATE productos SET stock=? WHERE id=?"); $up->execute([$stockNuevo,$productoId]);
    $hist=$db->prepare(
        "INSERT INTO venta_historial
         (venta_id,usuario_id,tipo,cantidad_nueva,bonificacion_nueva,precio_final_nuevo,total_nuevo,estado_anterior,estado_nuevo)
         VALUES (?,?,'VENTA_CREADA',?,?,?,?, 'NUEVA','ACTIVA')"
    );
    $hist->execute([$ventaId,$usuarioId,$cantidad,$calculo["bonificacion_porcentaje"],$calculo["precio_final_unitario"],$calculo["total"]]);
    registrarMovimientoStock($db,$productoId,$producto["nombre"],$usuarioId,$ventaId,"VENTA",-$cantidad,$stockAnterior,$stockNuevo,$producto["nombre_singular"],"Venta #{$ventaId} mediante FEFO");
    $db->commit(); responderJson(["success"=>true,"venta_id"=>$ventaId,"calculo"=>$calculo,"stock_vendible_anterior"=>$vendible],201);
}catch(DomainException $e){$db->rollback();responderJson(["error"=>$e->getMessage()],400);}
catch(Throwable $e){$db->rollback();error_log("Error al registrar venta: ".$e->getMessage());responderJson(["error"=>"No se pudo registrar la venta."],500);}
