<?php

declare(strict_types=1);

ob_start();

require __DIR__ . "/../conexion.php";
require __DIR__ . "/../producto_servicio.php";

const MARCA_DEMO = "DEMO-DISTRIBUIDORA-V1";

function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) {
        throw new RuntimeException("FALLÓ: {$mensaje}");
    }
    echo "OK: {$mensaje}\n";
}

function filasPorClave(mysqli $db, string $tabla, string $clave): array {
    $resultado = $db->query("SELECT * FROM `{$tabla}` ORDER BY `{$clave}`");
    $filas = [];
    while ($fila = $resultado->fetch_assoc()) {
        $filas[(string) $fila[$clave]] = $fila;
    }
    return $filas;
}

function compararFilasPrevias(mysqli $db, array $respaldo, string $tabla, string $clave): void {
    $actuales = filasPorClave($db, $tabla, $clave);
    foreach ($respaldo["tablas"][$tabla] ?? [] as $previa) {
        $id = (string) $previa[$clave];
        comprobar(isset($actuales[$id]), "se conserva {$tabla}[{$id}]");
        foreach ($previa as $columna => $valor) {
            comprobar(
                ($actuales[$id][$columna] === null ? null : (string) $actuales[$id][$columna])
                    === ($valor === null ? null : (string) $valor),
                "permanece intacto {$tabla}[{$id}].{$columna}"
            );
        }
    }
}

function obtenerJson(string $baseUrl, string $ruta, string $sid): array {
    $curl = curl_init(rtrim($baseUrl, "/") . "/" . ltrim($ruta, "/"));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE => "PHPSESSID={$sid}",
        CURLOPT_TIMEOUT => 10,
    ]);
    $cuerpo = curl_exec($curl);
    if ($cuerpo === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $estado = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    comprobar($estado === 200, "{$ruta} responde HTTP 200");
    return json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
}

$baseUrl = rtrim($argv[1] ?? "", "/");
$rutaRespaldo = $argv[2] ?? (__DIR__ . "/../backups/antes_ean_lotes_20260902_232139.json");
$respaldo = json_decode((string) file_get_contents($rutaRespaldo), true, 512, JSON_THROW_ON_ERROR);
$db = Conexion::obtenerInstancia();

$usuariosAntes = $respaldo["tablas"]["usuarios"] ?? [];
$usuariosAhora = $db->query("SELECT * FROM usuarios ORDER BY id")->fetch_all(MYSQLI_ASSOC);
comprobar(count($usuariosAhora) === count($usuariosAntes), "usuarios antes y después: " . count($usuariosAhora));
compararFilasPrevias($db, $respaldo, "usuarios", "id");
compararFilasPrevias($db, $respaldo, "ventas", "id");
compararFilasPrevias($db, $respaldo, "venta_historial", "id");
compararFilasPrevias($db, $respaldo, "stock_movimientos", "id");

$productosAntes = [];
foreach ($respaldo["tablas"]["productos"] ?? [] as $producto) {
    $productosAntes[(string) $producto["id"]] = (string) $producto["codigo"];
}
$productosAhora = $db->query("SELECT id,codigo FROM productos ORDER BY id")->fetch_all(MYSQLI_ASSOC);
comprobar(count($productosAhora) === count($productosAntes), "no se crearon ni eliminaron productos");
foreach ($productosAhora as $producto) {
    comprobar(($productosAntes[(string) $producto["id"]] ?? null) === $producto["codigo"], "producto {$producto['id']} conserva ID y código");
}

$demo = $db->query(
    "SELECT p.id,p.ean13,p.descripcion FROM productos p
     WHERE p.descripcion LIKE '%CÓDIGO DEMO - NO COMERCIAL%' ORDER BY p.id"
)->fetch_all(MYSQLI_ASSOC);
comprobar(count($demo) === 8, "ocho productos fueron adaptados como demo");
$eanUnicos = [];
foreach ($demo as $producto) {
    comprobar(ean13Valido((string) $producto["ean13"]), "EAN-13 válido para producto {$producto['id']}");
    $eanUnicos[] = $producto["ean13"];
}
comprobar(count(array_unique($eanUnicos)) === 8, "los ocho EAN-13 demo son únicos");

$ventasDemo = $db->query(
    "SELECT DISTINCT v.id,v.estado,v.bonificacion_porcentaje
     FROM ventas v JOIN venta_historial h ON h.venta_id=v.id
     WHERE h.motivo LIKE '" . MARCA_DEMO . ":%' ORDER BY v.id"
)->fetch_all(MYSQLI_ASSOC);
comprobar(count($ventasDemo) === 23, "existen 23 ventas demo sin duplicados");
$porBonificacion = ["0.00" => 0, "5.00" => 0, "10.00" => 0];
$porEstado = ["ACTIVA" => 0, "MODIFICADA" => 0, "CANCELADA" => 0];
foreach ($ventasDemo as $venta) {
    $porBonificacion[$venta["bonificacion_porcentaje"]]++;
    $porEstado[$venta["estado"]]++;
}
comprobar($porBonificacion === ["0.00" => 14, "5.00" => 6, "10.00" => 3], "bonificaciones: 14 sin, 6 al 5% y 3 al 10%");
comprobar($porEstado === ["ACTIVA" => 20, "MODIFICADA" => 1, "CANCELADA" => 2], "estados: 20 activas, 1 modificada y 2 canceladas");

$lotes = (int) $db->query("SELECT COUNT(*) total FROM producto_lotes WHERE numero_lote LIKE 'DEMO-%'")->fetch_assoc()["total"];
$proximos = (int) $db->query("SELECT COUNT(*) total FROM producto_lotes WHERE numero_lote LIKE 'DEMO-%' AND estado='ACTIVO' AND stock_actual>0 AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetch_assoc()["total"];
$intermedios = (int) $db->query("SELECT COUNT(*) total FROM producto_lotes WHERE numero_lote LIKE 'DEMO-%' AND estado='ACTIVO' AND stock_actual>0 AND fecha_vencimiento>DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND fecha_vencimiento<=DATE_ADD(CURDATE(),INTERVAL 90 DAY)")->fetch_assoc()["total"];
$vencidos = (int) $db->query("SELECT COUNT(*) total FROM producto_lotes WHERE numero_lote LIKE 'DEMO-%' AND estado='ACTIVO' AND stock_actual>0 AND fecha_vencimiento<CURDATE()")->fetch_assoc()["total"];
comprobar($lotes === 16, "existen 16 lotes demo");
comprobar($proximos >= 2, "hay al menos dos lotes con stock que vencen dentro de 30 días");
comprobar($intermedios >= 1, "hay lotes con stock que vencen entre 30 y 90 días");
comprobar($vencidos === 1, "existe exactamente un lote demo vencido con stock");

$descuadres = (int) $db->query(
    "SELECT COUNT(*) total FROM productos p
     WHERE p.descripcion LIKE '%CÓDIGO DEMO - NO COMERCIAL%'
       AND p.stock<>(SELECT COALESCE(SUM(l.stock_actual),0) FROM producto_lotes l WHERE l.producto_id=p.id)"
)->fetch_assoc()["total"];
comprobar($descuadres === 0, "el stock físico coincide con la suma de lotes");

$snapshotsIncompletos = (int) $db->query(
    "SELECT COUNT(DISTINCT v.id) total FROM ventas v JOIN venta_historial h ON h.venta_id=v.id
     WHERE h.motivo LIKE '" . MARCA_DEMO . ":%'
       AND (v.precio_referencial_snapshot IS NULL OR v.porcentaje_vendedor IS NULL
            OR v.bonificacion_porcentaje IS NULL OR v.precio_final_unitario IS NULL
            OR v.total IS NULL OR v.unidad_venta_snapshot IS NULL
            OR v.unidades_por_presentacion_snapshot IS NULL OR v.cliente_id IS NULL OR v.usuario_id IS NULL)"
)->fetch_assoc()["total"];
comprobar($snapshotsIncompletos === 0, "todas las ventas demo conservan snapshots comerciales y de unidades");

if ($baseUrl !== "") {
    $admin = $db->query("SELECT * FROM usuarios WHERE rol='admin' ORDER BY id LIMIT 1")->fetch_assoc();
    comprobar((bool) $admin, "existe administrador para probar endpoints");
    $sid = bin2hex(random_bytes(16));
    session_id($sid);
    session_start();
    $_SESSION = [
        "usuario_id" => (int) $admin["id"],
        "usuario_dni" => $admin["dni"],
        "usuario_nombre" => $admin["nombre"],
        "usuario_apellido" => $admin["apellido"],
        "usuario_rol" => "admin",
        "mfa_verified" => true,
    ];
    session_write_close();

    $alertas = obtenerJson($baseUrl, "obtener_alertas_inventario.php", $sid);
    comprobar($alertas["contadores"] === ["sin_stock" => 1, "stock_bajo" => 2, "proximos" => 2, "vencidos" => 1], "alertas devuelven 1 sin stock, 2 bajos, 2 próximos y 1 vencido");

    $sinStock = obtenerJson($baseUrl, "obtener_productos.php?stock_estado=sin_stock", $sid);
    $bajo = obtenerJson($baseUrl, "obtener_productos.php?stock_estado=bajo", $sid);
    $conStock = obtenerJson($baseUrl, "obtener_productos.php?stock_estado=con_stock", $sid);
    comprobar(count($sinStock) === 1 && $sinStock[0]["nombre"] === "Chupetines Frutales", "filtro Sin stock");
    comprobar(count($bajo) === 2, "filtro Stock bajo");
    comprobar(count($conStock) === 11, "filtro Con stock");

    $precios = obtenerJson($baseUrl, "obtener_productos.php?precio_min=10000&precio_max=25000", $sid);
    comprobar($precios !== [] && !array_filter($precios, static fn(array $p): bool => (float) $p["precio"] < 10000 || (float) $p["precio"] > 25000), "filtro combinado de precios 10000-25000");

    $ean = rawurlencode((string) $demo[0]["ean13"]);
    $porEan = obtenerJson($baseUrl, "obtener_productos.php?ean13={$ean}", $sid);
    $scanner = obtenerJson($baseUrl, "buscar_producto_ean.php?ean13={$ean}", $sid);
    comprobar(count($porEan) === 1 && $scanner["id"] === $porEan[0]["id"], "EAN-13 encuentra exactamente un producto en filtro y scanner");

    $ventasFiltradas = obtenerJson($baseUrl, "obtener_ventas.php?vendedor_id=11&desde=2026-07-01&hasta=2026-09-02&producto_id=1&estado=ACTIVA", $sid);
    comprobar(count($ventasFiltradas) === 3, "filtro combinado vendedor + fecha + producto + estado");
    $canceladas = obtenerJson($baseUrl, "obtener_ventas.php?estado=CANCELADA", $sid);
    comprobar(count($canceladas) === 2, "filtro de ventas canceladas");

    $detalle = obtenerJson($baseUrl, "obtener_detalle_producto.php?id=1&desde=2026-07-01&hasta=2026-09-02", $sid);
    comprobar((int) $detalle["metricas"]["operaciones"] === 3 && (int) $detalle["metricas"]["unidades_equivalentes"] === 216, "reporte de producto calcula operaciones y 216 unidades equivalentes");
}

echo "Verificación integral de la carga demo completada correctamente.\n";
