<?php
declare(strict_types=1);

require dirname(__DIR__) . "/conexion.php";

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "Este respaldo solo puede ejecutarse por consola.\n");
    exit(1);
}

$conexion = Conexion::obtenerInstancia();
$tablas = [
    "productos",
    "ventas",
    "venta_historial",
    "stock_movimientos",
    "unidades_venta",
    "configuracion_comercial",
    "bonificaciones_cantidad",
];

$respaldo = [
    "formato" => "control-stock-backup-logico-v1",
    "creado_en" => date(DATE_ATOM),
    "base" => "control_stock",
    "alcance" => "catalogo, ventas relacionadas y configuracion comercial",
    "tablas" => [],
];

foreach ($tablas as $tabla) {
    $resultado = $conexion->query("SELECT * FROM `{$tabla}` ORDER BY 1");
    $respaldo["tablas"][$tabla] = $resultado->fetch_all(MYSQLI_ASSOC);
}

// No se copian contraseñas ni secretos TOTP al archivo. La huella permite probar
// que la identidad y autenticación de cada usuario permanecieron exactamente iguales.
$usuarios = $conexion->query(
    "SELECT id, dni, nombre, apellido, rol, totp_enabled, totp_confirmed_at,
            SHA2(CONCAT_WS('|', id, dni, nombre, apellido, rol, password,
                COALESCE(totp_secret_encrypted, ''), totp_enabled,
                COALESCE(totp_confirmed_at, '')), 256) AS auth_core_hash,
            totp_last_timeslice
     FROM usuarios ORDER BY id"
)->fetch_all(MYSQLI_ASSOC);
$respaldo["integridad_usuarios"] = $usuarios;

$directorio = dirname(__DIR__) . DIRECTORY_SEPARATOR . "backups";
if (!is_dir($directorio) && !mkdir($directorio, 0770, true) && !is_dir($directorio)) {
    throw new RuntimeException("No se pudo crear el directorio de respaldos.");
}

$archivo = $directorio . DIRECTORY_SEPARATOR . "catalogo_antes_" . date("Ymd_His") . ".json";
$json = json_encode($respaldo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($archivo, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("No se pudo escribir el respaldo.");
}

echo $archivo . PHP_EOL;
