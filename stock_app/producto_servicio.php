<?php
declare(strict_types=1);

function normalizarEan13(?string $valor): ?string {
    $ean = trim((string) $valor);
    return $ean === "" ? null : $ean;
}

function ean13Valido(string $ean): bool {
    if (preg_match('/^\d{13}$/D', $ean) !== 1) return false;
    $suma = 0;
    for ($i = 0; $i < 12; $i++) {
        $suma += (int) $ean[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    $verificador = (10 - ($suma % 10)) % 10;
    return $verificador === (int) $ean[12];
}

function fechaIsoOpcionalValida(?string $valor): bool {
    return $valor === null || $valor === "" || fechaIsoValida($valor);
}

function registrarHistorialProducto(
    mysqli $db,
    int $productoId,
    ?int $usuarioId,
    string $tipo,
    ?string $detalle = null
): void {
    $stmt = $db->prepare(
        "INSERT INTO producto_historial (producto_id, usuario_id, tipo, detalle) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$productoId, $usuarioId, $tipo, $detalle]);
}

function lotesVendibles(mysqli $db, int $productoId, bool $bloquear = false): array {
    $sql =
        "SELECT id, stock_actual, fecha_vencimiento, numero_lote
         FROM producto_lotes
         WHERE producto_id = ? AND estado = 'ACTIVO' AND stock_actual > 0
           AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())
         ORDER BY fecha_vencimiento IS NULL, fecha_vencimiento, fecha_ingreso, id";
    if ($bloquear) $sql .= " FOR UPDATE";
    $stmt = $db->prepare($sql);
    $stmt->execute([$productoId]);
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function stockVendible(mysqli $db, int $productoId, bool $bloquear = false): int {
    return array_sum(array_map(static fn(array $l): int => (int) $l["stock_actual"], lotesVendibles($db, $productoId, $bloquear)));
}

function asignarLotesFefo(mysqli $db, int $productoId, int $ventaId, int $cantidad): array {
    $restante = $cantidad;
    $asignaciones = [];
    foreach (lotesVendibles($db, $productoId, true) as $lote) {
        if ($restante === 0) break;
        $usar = min($restante, (int) $lote["stock_actual"]);
        $nuevo = (int) $lote["stock_actual"] - $usar;
        $estado = $nuevo === 0 ? "AGOTADO" : "ACTIVO";
        $actualizar = $db->prepare("UPDATE producto_lotes SET stock_actual = ?, estado = ? WHERE id = ?");
        $actualizar->execute([$nuevo, $estado, $lote["id"]]);
        $vincular = $db->prepare(
            "INSERT INTO venta_lotes (venta_id, lote_id, cantidad) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)"
        );
        $vincular->execute([$ventaId, $lote["id"], $usar]);
        $asignaciones[] = ["lote_id" => (int) $lote["id"], "cantidad" => $usar];
        $restante -= $usar;
    }
    if ($restante > 0) {
        throw new DomainException("No hay stock vigente suficiente para completar la venta.");
    }
    return $asignaciones;
}

function devolverCantidadALotes(
    mysqli $db,
    int $productoId,
    int $ventaId,
    int $cantidad,
    ?int $usuarioId
): void {
    $restante = $cantidad;
    $stmt = $db->prepare(
        "SELECT vl.lote_id, vl.cantidad, l.stock_actual, l.estado
         FROM venta_lotes vl JOIN producto_lotes l ON l.id = vl.lote_id
         WHERE vl.venta_id = ?
         ORDER BY l.fecha_vencimiento IS NULL DESC, l.fecha_vencimiento DESC, l.id DESC
         FOR UPDATE"
    );
    $stmt->execute([$ventaId]);
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $asignacion) {
        if ($restante === 0) break;
        $devolver = min($restante, (int) $asignacion["cantidad"]);
        $nuevoAsignado = (int) $asignacion["cantidad"] - $devolver;
        $nuevoStock = (int) $asignacion["stock_actual"] + $devolver;
        $estado = $asignacion["estado"] === "BAJA" ? "BAJA" : "ACTIVO";
        $lote = $db->prepare("UPDATE producto_lotes SET stock_actual = ?, estado = ? WHERE id = ?");
        $lote->execute([$nuevoStock, $estado, $asignacion["lote_id"]]);
        if ($nuevoAsignado === 0) {
            $vinculo = $db->prepare("DELETE FROM venta_lotes WHERE venta_id = ? AND lote_id = ?");
            $vinculo->execute([$ventaId, $asignacion["lote_id"]]);
        } else {
            $vinculo = $db->prepare("UPDATE venta_lotes SET cantidad = ? WHERE venta_id = ? AND lote_id = ?");
            $vinculo->execute([$nuevoAsignado, $ventaId, $asignacion["lote_id"]]);
        }
        $restante -= $devolver;
    }

    if ($restante > 0) {
        $heredado = $db->prepare(
            "SELECT id, stock_actual, estado FROM producto_lotes
             WHERE producto_id = ? AND numero_lote IS NULL AND fecha_vencimiento IS NULL
             ORDER BY origen = 'HEREDADO' DESC, id LIMIT 1 FOR UPDATE"
        );
        $heredado->execute([$productoId]);
        $lote = $heredado->get_result()->fetch_assoc();
        if ($lote) {
            $actualizar = $db->prepare("UPDATE producto_lotes SET stock_actual = ?, estado = 'ACTIVO' WHERE id = ?");
            $actualizar->execute([(int) $lote["stock_actual"] + $restante, $lote["id"]]);
        } else {
            $crear = $db->prepare(
                "INSERT INTO producto_lotes
                 (producto_id, numero_lote, cantidad_inicial, stock_actual, fecha_ingreso,
                  fecha_vencimiento, estado, origen, usuario_id)
                 VALUES (?, NULL, 0, ?, NOW(), NULL, 'ACTIVO', 'CANCELACION', ?)"
            );
            $crear->execute([$productoId, $restante, $usuarioId]);
        }
    }
}

function detallePresentacion(int $cantidad, string $unidadBasePlural): string {
    return $cantidad . " " . ($cantidad === 1 ? rtrim($unidadBasePlural, "sS") : $unidadBasePlural);
}
