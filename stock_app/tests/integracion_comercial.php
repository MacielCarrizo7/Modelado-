<?php
declare(strict_types=1);
require __DIR__ . "/../conexion.php";

$baseUrl = rtrim($argv[1] ?? "http://127.0.0.1:8101", "/");
$db = Conexion::obtenerInstancia();
$marca = "COMTEST" . bin2hex(random_bytes(4));
$usuarios = $productos = $ventas = [];
$reglasOriginales = [];
$margenOriginal = "100.00";

function ok(bool $condicion, string $mensaje): void { if (!$condicion) throw new RuntimeException("FALLÓ: {$mensaje}"); echo "OK: {$mensaje}\n"; }
function http(string $base, string $ruta, string $sid, array $post = [], array $headers = []): array {
    $c = curl_init($base . "/" . ltrim($ruta, "/"));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => "PHPSESSID={$sid}", CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($post !== []) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = curl_exec($c); if ($r === false) throw new RuntimeException(curl_error($c));
    $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    return ["status" => curl_getinfo($c, CURLINFO_RESPONSE_CODE), "body" => substr($r, $hs), "headers" => substr($r, 0, $hs)];
}
function sesion(array $usuario, string $csrf): string {
    $sid = bin2hex(random_bytes(16)); session_id($sid); session_start();
    $_SESSION = ["usuario_id" => (int) $usuario["id"], "usuario_dni" => $usuario["dni"], "usuario_nombre" => $usuario["nombre"], "usuario_apellido" => $usuario["apellido"], "usuario_rol" => $usuario["rol"], "mfa_verified" => true, "csrf_token" => $csrf];
    session_write_close(); return $sid;
}
function json(array $respuesta): array { return json_decode($respuesta["body"], true, 512, JSON_THROW_ON_ERROR); }
function eanTest(int $numero): string { $base=sprintf("200000000%03d",$numero);$s=0;for($i=0;$i<12;$i++)$s+=(int)$base[$i]*($i%2===0?1:3);return $base.((10-($s%10))%10); }

try {
    foreach (["admin", "vendedor_a", "vendedor_b", "cliente"] as $indice => $clave) {
        $rol = str_starts_with($clave, "vendedor") ? "vendedor" : $clave;
        $dni = $marca . ($indice + 1); $nombre = ucfirst($clave); $apellido = "Prueba"; $hash = password_hash("Temporal1!", PASSWORD_DEFAULT);
        $s = $db->prepare("INSERT INTO usuarios (dni,nombre,apellido,password,rol) VALUES (?,?,?,?,?)"); $s->execute([$dni,$nombre,$apellido,$hash,$rol]);
        $usuarios[$clave] = ["id" => $db->insert_id, "dni" => $dni, "nombre" => $nombre, "apellido" => $apellido, "rol" => $rol];
    }
    $csrf = bin2hex(random_bytes(32)); foreach ($usuarios as $k => $u) $usuarios[$k]["sid"] = sesion($u, $csrf);
    $h = ["X-CSRF-Token: {$csrf}"];

    $gestion = http($baseUrl, "registro.php", $usuarios["admin"]["sid"]);
    ok($gestion["status"] === 200 && str_contains($gestion["body"], "Personal del sistema") && str_contains($gestion["body"], "Clientes"), "Usuarios separa personal y clientes");
    ok(http($baseUrl, "registro.php", $usuarios["vendedor_a"]["sid"])["status"] === 403, "vendedor no accede a gestión de usuarios");

    $margenOriginal = (string) $db->query("SELECT margen_maximo FROM configuracion_comercial WHERE id=1")->fetch_assoc()["margen_maximo"];
    $reglasOriginales = $db->query("SELECT id,activo FROM bonificaciones_cantidad")->fetch_all(MYSQLI_ASSOC);
    $db->query("UPDATE bonificaciones_cantidad SET activo=0");
    $r1 = http($baseUrl, "guardar_configuracion_comercial.php", $usuarios["admin"]["sid"], ["accion"=>"crear_bonificacion","cantidad_desde"=>"3","cantidad_hasta"=>"9","porcentaje"=>"5"], $h);
    $r2 = http($baseUrl, "guardar_configuracion_comercial.php", $usuarios["admin"]["sid"], ["accion"=>"crear_bonificacion","cantidad_desde"=>"10","cantidad_hasta"=>"","porcentaje"=>"10"], $h);
    ok($r1["status"] === 201 && $r2["status"] === 201, "admin configura bonificaciones 3-9=5% y 10+=10%");

    $categoria=(int)$db->query("SELECT id FROM categorias WHERE activo=1 ORDER BY id LIMIT 1")->fetch_assoc()["id"];
    $indiceProducto=0;
    foreach (["caja"=>["Caja comercial", "10000", "20", "CAJA",24], "bulto"=>["Bulto comercial", "2000", "10", "BULTO",1], "unidad"=>["Unidad comercial", "500", "30", "UNIDAD",1]] as $k=>$p) {
        $indiceProducto++;$ean=eanTest(100+$indiceProducto);
        $r = http($baseUrl, "guardar_producto.php", $usuarios["admin"]["sid"], ["ean13"=>$ean,"nombre"=>$marca.$p[0],"marca"=>"Prueba","descripcion"=>"Producto de integración","precio"=>$p[1],"stock"=>$p[2],"stock_minimo"=>"0","unidad_venta_codigo"=>$p[3],"unidades_por_presentacion"=>(string)$p[4],"unidad_base_codigo"=>"UNIDAD","categoria_id"=>(string)$categoria], $h);
        ok($r["status"] === 201, "creación de producto {$p[3]}"); $productos[$k] = (int) json($r)["producto_id"];
    }
    $sinCsrf = http($baseUrl, "modificar_producto.php", $usuarios["admin"]["sid"], ["id"=>$productos["caja"],"nombre"=>"No","precio"=>"1","stock"=>"1","unidad_venta_codigo"=>"CAJA"]);
    ok($sinCsrf["status"] === 403, "editar producto exige CSRF");
    $sinRol = http($baseUrl, "modificar_producto.php", $usuarios["vendedor_a"]["sid"], ["id"=>$productos["caja"],"nombre"=>"No","precio"=>"1","stock"=>"1","unidad_venta_codigo"=>"CAJA"], $h);
    ok($sinRol["status"] === 403, "solo admin puede editar productos");

    $crearVenta = function(string $vendedor, string $producto, int $cantidad, string $margen) use ($baseUrl,$usuarios,$productos,$csrf,&$ventas): array {
        $r=http($baseUrl,"guardar_venta.php",$usuarios[$vendedor]["sid"],["producto_id"=>(string)$productos[$producto],"cliente_id"=>(string)$usuarios["cliente"]["id"],"cantidad"=>(string)$cantidad,"porcentaje_vendedor"=>$margen],["X-CSRF-Token: {$csrf}"]);
        ok($r["status"]===201,"venta {$producto} registrada por {$vendedor}"); $j=json($r); $ventas[$producto]=(int)$j["venta_id"]; return $j;
    };
    $caja=$crearVenta("vendedor_a","caja",3,"15");
    ok($caja["calculo"]["precio_con_margen"]==="11500.00" && $caja["calculo"]["bonificacion_porcentaje"]==="5.00" && $caja["calculo"]["precio_final_unitario"]==="10925.00" && $caja["calculo"]["total"]==="32775.00", "backend calcula 10000 +15% -5% = 10925; total 32775");
    $crearVenta("vendedor_a","bulto",2,"0"); $crearVenta("vendedor_b","unidad",15,"0");

    $eanCaja=$db->query("SELECT ean13 FROM productos WHERE id=".$productos["caja"])->fetch_assoc()["ean13"];
    $editar=http($baseUrl,"modificar_producto.php",$usuarios["admin"]["sid"],["id"=>(string)$productos["caja"],"ean13"=>$eanCaja,"nombre"=>$marca."Caja actualizada","marca"=>"Prueba","descripcion"=>"Actualizado","precio"=>"12000","stock_minimo"=>"2","unidad_venta_codigo"=>"CAJA","unidades_por_presentacion"=>"24","unidad_base_codigo"=>"UNIDAD","categoria_id"=>(string)$categoria],$h);
    ok($editar["status"]===200,"admin modifica nombre y precio sin alterar stock directamente");
    $s=$db->prepare("SELECT precio_referencial_snapshot,porcentaje_vendedor,bonificacion_porcentaje,precio_final_unitario,total,unidad_venta_snapshot FROM ventas WHERE id=?"); $s->execute([$ventas["caja"]]); $snap=$s->get_result()->fetch_assoc();
    ok($snap["precio_referencial_snapshot"]==="10000.00" && $snap["porcentaje_vendedor"]==="15.00" && $snap["bonificacion_porcentaje"]==="5.00" && $snap["precio_final_unitario"]==="10925.00" && $snap["total"]==="32775.00" && $snap["unidad_venta_snapshot"]==="Caja", "editar producto no altera snapshots históricos");

    $hoy=date("Y-m-d"); $f=json(http($baseUrl,"obtener_ventas.php?desde={$hoy}&hasta={$hoy}&producto_id={$productos['caja']}&vendedor_id={$usuarios['vendedor_a']['id']}",$usuarios["admin"]["sid"]));
    ok(count($f)===1 && (int)$f[0]["usuario_id"]===(int)$usuarios["vendedor_a"]["id"], "filtro combinado por vendedor, producto y fecha");
    $propias=json(http($baseUrl,"obtener_ventas.php",$usuarios["vendedor_a"]["sid"]));
    ok(!array_filter($propias,fn($v)=>(int)$v["usuario_id"]===(int)$usuarios["vendedor_b"]["id"]), "vendedor solo consulta su historial");

    $mod=http($baseUrl,"modificar_venta.php",$usuarios["vendedor_b"]["sid"],["venta_id"=>(string)$ventas["unidad"],"accion"=>"modificar_cantidad","cantidad"=>"3","motivo"=>"Cambio mayorista"],$h);
    $mc=json($mod); ok($mod["status"]===200 && $mc["calculo"]["bonificacion_porcentaje"]==="5.00" && $mc["calculo"]["precio_final_unitario"]==="475.00" && $mc["calculo"]["total"]==="1425.00", "cambiar 15 a 3 recalcula bonificación, precio y total");

    $cancelar=http($baseUrl,"modificar_venta.php",$usuarios["vendedor_a"]["sid"],["venta_id"=>(string)$ventas["bulto"],"accion"=>"cancelar","motivo"=>"Prueba idempotente"],$h);
    $repetir=http($baseUrl,"modificar_venta.php",$usuarios["vendedor_a"]["sid"],["venta_id"=>(string)$ventas["bulto"],"accion"=>"cancelar"],$h);
    ok($cancelar["status"]===200 && (json($repetir)["already_cancelled"]??false)===true,"cancelación devuelve stock una sola vez");
    $tipos=$db->query("SELECT DISTINCT tipo FROM stock_movimientos WHERE producto_nombre_snapshot LIKE '{$marca}%'")->fetch_all(MYSQLI_ASSOC);
    $lista=array_column($tipos,"tipo"); ok(in_array("VENTA",$lista,true)&&in_array("CANCELACION",$lista,true)&&in_array("MODIFICACION_VENTA",$lista,true)&&in_array("ALTA_INICIAL",$lista,true),"historial explica altas, ventas, modificaciones y cancelaciones");
    $aud=$db->query("SELECT COUNT(*) total FROM venta_historial WHERE venta_id IN (".implode(",",$ventas).")")->fetch_assoc(); ok((int)$aud["total"]>=5,"auditoría conserva creación, modificación y cancelación");
} finally {
    if ($ventas) { $ids=implode(",",array_map("intval",$ventas)); $db->query("DELETE FROM stock_movimientos WHERE venta_id IN ({$ids})"); $db->query("DELETE FROM venta_historial WHERE venta_id IN ({$ids})"); $db->query("DELETE FROM venta_lotes WHERE venta_id IN ({$ids})"); $db->query("DELETE FROM ventas WHERE id IN ({$ids})"); }
    if ($productos) { $ids=implode(",",array_map("intval",$productos)); $db->query("DELETE FROM stock_movimientos WHERE producto_id IN ({$ids})"); $db->query("DELETE FROM producto_historial WHERE producto_id IN ({$ids})"); $db->query("DELETE FROM producto_lotes WHERE producto_id IN ({$ids})"); $db->query("DELETE FROM productos WHERE id IN ({$ids})"); }
    $db->query("DELETE FROM bonificaciones_cantidad WHERE fecha_registro >= NOW() - INTERVAL 1 HOUR AND id NOT IN (" . ($reglasOriginales ? implode(",",array_column($reglasOriginales,"id")) : "0") . ")");
    foreach ($reglasOriginales as $r) { $s=$db->prepare("UPDATE bonificaciones_cantidad SET activo=? WHERE id=?"); $s->execute([$r["activo"],$r["id"]]); }
    $s=$db->prepare("UPDATE configuracion_comercial SET margen_maximo=? WHERE id=1"); $s->execute([$margenOriginal]);
    if ($usuarios) { $ids=implode(",",array_map(fn($u)=>(int)$u["id"],$usuarios)); $db->query("DELETE FROM usuarios WHERE id IN ({$ids}) AND dni LIKE 'COMTEST%'"); }
}
echo "Integración comercial completada correctamente.\n";
