<?php
declare(strict_types=1);

require dirname(__DIR__) . "/conexion.php";

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "Este respaldo solo puede ejecutarse por consola.\n");
    exit(1);
}

$conexion = Conexion::obtenerInstancia();
$tablas = [
    "usuarios",
    "unidades_venta",
    "categorias",
    "productos",
    "configuracion_comercial",
    "bonificaciones_cantidad",
    "ventas",
    "venta_historial",
    "stock_movimientos",
];
$datos = [
    "formato" => "control-stock-backup-completo-v1",
    "creado_en" => date(DATE_ATOM),
    "advertencia" => "Respaldo privado: contiene hashes y secretos TOTP cifrados.",
    "tablas" => [],
];

foreach ($tablas as $tabla) {
    $resultado = $conexion->query("SELECT * FROM `{$tabla}` ORDER BY 1");
    $datos["tablas"][$tabla] = $resultado->fetch_all(MYSQLI_ASSOC);
}

$directorio = dirname(__DIR__) . DIRECTORY_SEPARATOR . "backups";
if (!is_dir($directorio) && !mkdir($directorio, 0770, true) && !is_dir($directorio)) {
    throw new RuntimeException("No se pudo crear el directorio de respaldo.");
}
$archivo = $directorio . DIRECTORY_SEPARATOR . "antes_ean_lotes_" . date("Ymd_His") . ".json";
$json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false || file_put_contents($archivo, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("No se pudo escribir el respaldo.");
}

echo $archivo . PHP_EOL;
