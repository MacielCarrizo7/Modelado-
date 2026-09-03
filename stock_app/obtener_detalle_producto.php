<?php
require "seguridad.php";
require "conexion.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin"]);

$db=Conexion::obtenerInstancia();
$id=filter_input(INPUT_GET,"id",FILTER_VALIDATE_INT)?:0;
$desde=trim($_GET["desde"]??""); $hasta=trim($_GET["hasta"]??"");
if($id<=0 || !fechaIsoOpcionalValida($desde) || !fechaIsoOpcionalValida($hasta)) responderJson(["error"=>"Parámetros inválidos."],400);
if($desde!=="" && $hasta!=="" && $hasta<$desde) responderJson(["error"=>"La fecha hasta debe ser igual o posterior a desde."],400);

$productoStmt=$db->prepare(
    "SELECT p.*,c.nombre categoria,u.nombre_singular unidad_singular,u.nombre_plural unidad_plural,
            ub.nombre_singular unidad_base_singular,ub.nombre_plural unidad_base_plural,
            COALESCE((SELECT SUM(l.stock_actual) FROM producto_lotes l WHERE l.producto_id=p.id AND l.estado='ACTIVO' AND l.stock_actual>0 AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento>=CURDATE())),0) stock_vendible,
            (SELECT MAX(v.fecha) FROM ventas v WHERE v.producto_id=p.id) ultima_venta,
            (SELECT MAX(sm.fecha) FROM stock_movimientos sm WHERE sm.producto_id=p.id) ultimo_movimiento
     FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id
     JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
     JOIN unidades_venta ub ON ub.codigo=p.unidad_base_codigo WHERE p.id=?"
);
$productoStmt->execute([$id]); $producto=$productoStmt->get_result()->fetch_assoc();
if(!$producto) responderJson(["error"=>"Producto no encontrado."],404);

$lotesStmt=$db->prepare(
    "SELECT l.id,l.numero_lote,l.cantidad_inicial,l.stock_actual,l.fecha_ingreso,l.fecha_vencimiento,l.estado,l.origen,
            CASE WHEN l.fecha_vencimiento<CURDATE() THEN 'VENCIDO'
                 WHEN l.fecha_vencimiento<=DATE_ADD(CURDATE(),INTERVAL (SELECT dias_alerta_vencimiento FROM configuracion_comercial WHERE id=1) DAY) THEN 'VENCE_PRONTO'
                 ELSE 'NORMAL' END estado_vencimiento
     FROM producto_lotes l WHERE l.producto_id=? ORDER BY l.fecha_vencimiento IS NULL,l.fecha_vencimiento,l.id"
);
$lotesStmt->execute([$id]); $lotes=$lotesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cond="v.producto_id=?"; $params=[$id];
if($desde!==""){ $cond.=" AND DATE(v.fecha)>=?"; $params[]=$desde; }
if($hasta!==""){ $cond.=" AND DATE(v.fecha)<=?"; $params[]=$hasta; }
$metStmt=$db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN v.estado<>'CANCELADA' THEN v.cantidad ELSE 0 END),0) presentaciones_vendidas,
            COALESCE(SUM(CASE WHEN v.estado<>'CANCELADA' THEN v.cantidad*v.unidades_por_presentacion_snapshot ELSE 0 END),0) unidades_equivalentes,
            COALESCE(SUM(CASE WHEN v.estado<>'CANCELADA' THEN v.total ELSE 0 END),0) monto_vendido,
            SUM(v.estado<>'CANCELADA') operaciones,
            COALESCE(SUM(CASE WHEN v.estado='CANCELADA' THEN v.cantidad ELSE 0 END),0) cantidad_cancelada,
            MAX(v.fecha) ultima_venta_periodo
     FROM ventas v WHERE {$cond}"
);
$metStmt->execute($params); $metricas=$metStmt->get_result()->fetch_assoc();
$devCond="sm.producto_id=? AND sm.tipo='CANCELACION'"; $devParams=[$id];
if($desde!==""){ $devCond.=" AND DATE(sm.fecha)>=?"; $devParams[]=$desde; }
if($hasta!==""){ $devCond.=" AND DATE(sm.fecha)<=?"; $devParams[]=$hasta; }
$dev=$db->prepare("SELECT COALESCE(SUM(sm.cantidad_cambio),0) devuelto_stock FROM stock_movimientos sm WHERE {$devCond}");
$dev->execute($devParams); $metricas["devuelto_stock"]=$dev->get_result()->fetch_assoc()["devuelto_stock"];

$ventas=$db->prepare("SELECT id,fecha,cantidad,total,estado,unidad_venta_snapshot,unidades_por_presentacion_snapshot,unidad_base_snapshot FROM ventas v WHERE {$cond} ORDER BY fecha DESC,id DESC LIMIT 100");
$ventas->execute($params); $ventasDatos=$ventas->get_result()->fetch_all(MYSQLI_ASSOC);
$mov=$db->prepare("SELECT sm.id,sm.fecha,sm.tipo,sm.cantidad_cambio,sm.stock_anterior,sm.stock_nuevo,sm.motivo,sm.lote_id,u.nombre usuario FROM stock_movimientos sm LEFT JOIN usuarios u ON u.id=sm.usuario_id WHERE sm.producto_id=? ORDER BY sm.fecha DESC,sm.id DESC LIMIT 100");
$mov->execute([$id]); $movimientosDatos=$mov->get_result()->fetch_all(MYSQLI_ASSOC);
$hist=$db->prepare("SELECT ph.id,ph.tipo,ph.detalle,ph.fecha,u.nombre usuario FROM producto_historial ph LEFT JOIN usuarios u ON u.id=ph.usuario_id WHERE ph.producto_id=? ORDER BY ph.fecha DESC,ph.id DESC LIMIT 100");
$hist->execute([$id]); $historialDatos=$hist->get_result()->fetch_all(MYSQLI_ASSOC);

responderJson([
    "producto"=>$producto,"lotes"=>$lotes,"metricas"=>$metricas,
    "ventas"=>$ventasDatos,"movimientos"=>$movimientosDatos,
    "historial_producto"=>$historialDatos,"filtros"=>["desde"=>$desde,"hasta"=>$hasta]
]);
