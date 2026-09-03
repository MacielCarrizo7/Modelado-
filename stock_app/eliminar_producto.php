<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin"]);
requerirCsrfJson();
$db = Conexion::obtenerInstancia();
$id = filter_input(INPUT_POST,"id",FILTER_VALIDATE_INT) ?: 0;
if ($id<=0) responderJson(["error"=>"Producto inválido."],400);
try {
    $db->begin_transaction();
    $stmt=$db->prepare("SELECT p.nombre,p.stock,p.activo,p.fecha_alta,u.nombre_singular FROM productos p JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo WHERE p.id=? FOR UPDATE");
    $stmt->execute([$id]); $producto=$stmt->get_result()->fetch_assoc();
    if(!$producto || (int)$producto["activo"]!==1) throw new DomainException("Producto no encontrado o ya inactivo.");
    $baja=$db->prepare("UPDATE productos SET activo=0,fecha_baja=NOW() WHERE id=?"); $baja->execute([$id]);
    registrarHistorialProducto($db,$id,(int)$_SESSION["usuario_id"],"PRODUCTO_BAJA","Baja lógica; el stock y el historial se conservan.");
    registrarMovimientoStock($db,$id,$producto["nombre"],(int)$_SESSION["usuario_id"],null,"BAJA",0,(int)$producto["stock"],(int)$producto["stock"],$producto["nombre_singular"],"Baja lógica del producto");
    $db->commit(); responderJson(["success"=>true,"desactivado"=>true]);
} catch(DomainException $e){$db->rollback();responderJson(["error"=>$e->getMessage()],404);}
catch(Throwable $e){$db->rollback();error_log("Baja de producto: ".$e->getMessage());responderJson(["error"=>"No se pudo dar de baja el producto."],500);}
