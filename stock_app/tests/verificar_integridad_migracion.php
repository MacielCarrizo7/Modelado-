<?php

declare(strict_types=1);

require __DIR__ . "/../conexion.php";

$rutaRespaldo = $argv[1] ?? (__DIR__ . "/../backups/antes_ean_lotes_20260901_220224.json");
$contenido = file_get_contents($rutaRespaldo);
if ($contenido === false) {
    throw new RuntimeException("No se pudo leer el respaldo: {$rutaRespaldo}");
}

$respaldo = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
$tablas = $respaldo["tablas"] ?? null;
if (!is_array($tablas)) {
    throw new RuntimeException("El respaldo no contiene la sección de tablas esperada.");
}

$claves = [
    "usuarios" => ["id"],
    "unidades_venta" => ["codigo"],
    "categorias" => ["id"],
    "productos" => ["id"],
    "configuracion_comercial" => ["id"],
    "bonificaciones_cantidad" => ["id"],
    "ventas" => ["id"],
    "venta_historial" => ["id"],
    "stock_movimientos" => ["id"],
];

function valorComparable(mixed $valor): ?string {
    return $valor === null ? null : (string) $valor;
}

function claveFila(array $fila, array $columnas): string {
    return implode("|", array_map(
        static fn(string $columna): string => (string) ($fila[$columna] ?? ""),
        $columnas
    ));
}

$conexion = Conexion::obtenerInstancia();
$errores = [];

foreach ($tablas as $tabla => $filasPrevias) {
    if (!isset($claves[$tabla]) || !is_array($filasPrevias)) {
        $errores[] = "Tabla inesperada en el respaldo: {$tabla}";
        continue;
    }

    $resultado = $conexion->query("SELECT * FROM `{$tabla}`");
    $filasActuales = [];
    while ($fila = $resultado->fetch_assoc()) {
        $filasActuales[claveFila($fila, $claves[$tabla])] = $fila;
    }

    foreach ($filasPrevias as $filaPrevia) {
        $clave = claveFila($filaPrevia, $claves[$tabla]);
        if (!isset($filasActuales[$clave])) {
            $errores[] = "Falta {$tabla}[{$clave}] después de migrar";
            continue;
        }

        foreach ($filaPrevia as $columna => $valorPrevio) {
            $valorActual = $filasActuales[$clave][$columna] ?? null;
            if (valorComparable($valorActual) !== valorComparable($valorPrevio)) {
                $errores[] = "Cambió {$tabla}[{$clave}].{$columna}";
            }
        }
    }

    echo "OK: {$tabla} conserva sus " . count($filasPrevias) . " filas y valores originales.\n";
}

if ($errores !== []) {
    throw new RuntimeException("FALLÓ la integridad:\n- " . implode("\n- ", $errores));
}

$conteos = $conexion->query(
    "SELECT
        (SELECT COUNT(*) FROM usuarios) AS usuarios,
        (SELECT COUNT(*) FROM productos) AS productos,
        (SELECT COUNT(*) FROM ventas) AS ventas,
        (SELECT COUNT(*) FROM venta_historial) AS historial,
        (SELECT COUNT(*) FROM stock_movimientos) AS movimientos"
)->fetch_assoc();

echo "Integridad completa: {$conteos['usuarios']} usuarios, {$conteos['productos']} productos, "
    . "{$conteos['ventas']} ventas, {$conteos['historial']} eventos de venta y "
    . "{$conteos['movimientos']} movimientos originales.\n";
