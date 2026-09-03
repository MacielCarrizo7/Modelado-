<?php
declare(strict_types=1);

require dirname(__DIR__) . "/conexion.php";

if (PHP_SAPI !== "cli" || empty($argv[1])) {
    fwrite(STDERR, "Uso: php restaurar_backup_completo.php archivo.json\n");
    exit(1);
}

$contenido = file_get_contents($argv[1]);
$backup = is_string($contenido) ? json_decode($contenido, true, 512, JSON_THROW_ON_ERROR) : null;
if (!is_array($backup) || ($backup["formato"] ?? "") !== "control-stock-backup-completo-v1") {
    throw new RuntimeException("El archivo no es un respaldo completo compatible.");
}

$orden = [
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
$db = Conexion::obtenerInstancia();
$db->begin_transaction();
try {
    foreach ($orden as $tabla) {
        $filas = $backup["tablas"][$tabla] ?? [];
        foreach ($filas as $fila) {
            $columnas = array_keys($fila);
            $nombres = implode(", ", array_map(static fn(string $c): string => "`{$c}`", $columnas));
            $marcas = implode(", ", array_fill(0, count($columnas), "?"));
            $stmt = $db->prepare("REPLACE INTO `{$tabla}` ({$nombres}) VALUES ({$marcas})");
            $stmt->execute(array_values($fila));
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

echo "Respaldo restaurado.\n";
