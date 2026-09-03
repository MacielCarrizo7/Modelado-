<?php

function decimalValido(string $valor, int $enteros = 4): bool {
    return preg_match('/^\d{1,' . $enteros . '}(?:[.,]\d{1,2})?$/', trim($valor)) === 1;
}

function decimalNormalizado(string $valor): string {
    $normalizado = str_replace(',', '.', trim($valor));
    [$entero, $decimal] = array_pad(explode('.', $normalizado, 2), 2, '');
    return $entero . '.' . str_pad(substr($decimal, 0, 2), 2, '0');
}

function decimalAEntero(string $valor): int {
    $normalizado = decimalNormalizado($valor);
    [$entero, $decimal] = explode('.', $normalizado, 2);
    return ((int) $entero * 100) + (int) $decimal;
}

function enteroADecimal(int $valor): string {
    return intdiv($valor, 100) . '.' . str_pad((string) abs($valor % 100), 2, '0', STR_PAD_LEFT);
}

function calcularPrecioComercial(string $precioReferencial, string $margen, string $bonificacion, int $cantidad): array {
    $baseCentavos = decimalAEntero($precioReferencial);
    $margenBp = decimalAEntero($margen);
    $bonificacionBp = decimalAEntero($bonificacion);
    $conMargen = $baseCentavos + (int) round($baseCentavos * $margenBp / 10000, 0, PHP_ROUND_HALF_UP);
    $final = $conMargen - (int) round($conMargen * $bonificacionBp / 10000, 0, PHP_ROUND_HALF_UP);
    if ($cantidad <= 0 || $final <= 0 || $final > intdiv(99999999999999, $cantidad)) {
        throw new DomainException("El importe calculado supera el límite admitido.");
    }
    return [
        "precio_referencial" => enteroADecimal($baseCentavos),
        "porcentaje_vendedor" => enteroADecimal($margenBp),
        "bonificacion_porcentaje" => enteroADecimal($bonificacionBp),
        "precio_con_margen" => enteroADecimal($conMargen),
        "precio_final_unitario" => enteroADecimal($final),
        "total" => enteroADecimal($final * $cantidad),
    ];
}

function obtenerMargenMaximo(mysqli $conexion): string {
    $fila = $conexion->query("SELECT margen_maximo FROM configuracion_comercial WHERE id = 1")->fetch_assoc();
    return $fila ? (string) $fila["margen_maximo"] : "100.00";
}

function obtenerBonificacion(mysqli $conexion, int $cantidad): string {
    $stmt = $conexion->prepare(
        "SELECT porcentaje FROM bonificaciones_cantidad
         WHERE activo = 1 AND cantidad_desde <= ? AND (cantidad_hasta IS NULL OR cantidad_hasta >= ?)
         ORDER BY cantidad_desde DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$cantidad, $cantidad]);
    $fila = $stmt->get_result()->fetch_assoc();
    return $fila ? (string) $fila["porcentaje"] : "0.00";
}

function nombreUnidadParaCantidad(array $producto, int $cantidad): string {
    return $cantidad === 1 ? $producto["unidad_singular"] : $producto["unidad_plural"];
}

function registrarMovimientoStock(
    mysqli $conexion,
    int $productoId,
    string $productoNombre,
    ?int $usuarioId,
    ?int $ventaId,
    string $tipo,
    int $cambio,
    int $anterior,
    int $nuevo,
    string $unidad,
    ?string $motivo = null,
    ?int $loteId = null
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO stock_movimientos
         (producto_id, producto_nombre_snapshot, usuario_id, venta_id, lote_id, tipo,
          cantidad_cambio, stock_anterior, stock_nuevo, unidad_venta_snapshot, motivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$productoId, $productoNombre, $usuarioId, $ventaId, $loteId, $tipo, $cambio, $anterior, $nuevo, $unidad, $motivo]);
}
