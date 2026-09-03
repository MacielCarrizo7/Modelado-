<?php

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

date_default_timezone_set("America/Argentina/Buenos_Aires");

require dirname(__DIR__) . "/conexion.php";
require dirname(__DIR__) . "/comercial.php";
require dirname(__DIR__) . "/producto_servicio.php";

const DEMO_CLAVE = "DEMO-DISTRIBUIDORA-V1";
const DEMO_AVISO = "CÓDIGO DEMO - NO COMERCIAL";

$aplicar = in_array("--aplicar", $argv, true);
$db = Conexion::obtenerInstancia();
$db->set_charset("utf8mb4");

function fechaDemo(int $dias, string $hora = "10:00:00"): string {
    return (new DateTimeImmutable("today"))->modify(($dias >= 0 ? "+" : "") . $dias . " days")->format("Y-m-d") . " {$hora}";
}

function fechaVencimientoDemo(int $dias): string {
    return (new DateTimeImmutable("today"))->modify(($dias >= 0 ? "+" : "") . $dias . " days")->format("Y-m-d");
}

function eanDemo(int $secuencia): string {
    $base = "299" . str_pad((string) $secuencia, 9, "0", STR_PAD_LEFT);
    $suma = 0;
    for ($i = 0; $i < 12; $i++) {
        $suma += (int) $base[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    $ean = $base . ((10 - ($suma % 10)) % 10);
    if (!ean13Valido($ean)) {
        throw new RuntimeException("No se pudo generar el EAN demo {$secuencia}.");
    }
    return $ean;
}

function filas(mysqli $db, string $sql, array $parametros = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($parametros);
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function huella(array $filas): string {
    return hash("sha256", json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function actualizarFechaMovimiento(mysqli $db, int $movimientoId, string $fecha): void {
    $stmt = $db->prepare("UPDATE stock_movimientos SET fecha = ? WHERE id = ?");
    $stmt->execute([$fecha, $movimientoId]);
}

function categoriaId(mysqli $db, string $nombre): int {
    $stmt = $db->prepare("INSERT INTO categorias (nombre, activo) VALUES (?, 1) ON DUPLICATE KEY UPDATE activo = 1");
    $stmt->execute([$nombre]);
    $buscar = $db->prepare("SELECT id FROM categorias WHERE nombre = ?");
    $buscar->execute([$nombre]);
    return (int) $buscar->get_result()->fetch_assoc()["id"];
}

function adaptarProductoDemo(mysqli $db, array $producto, int $adminId): array {
    $buscar = $db->prepare("SELECT * FROM productos WHERE codigo = ? FOR UPDATE");
    $buscar->execute([$producto["codigo"]]);
    $actual = $buscar->get_result()->fetch_assoc();
    $creado = false;

    if ($actual) {
        $ventasPrevias = filas($db, "SELECT id FROM ventas WHERE producto_id = ? LIMIT 1", [(int) $actual["id"]]);
        if ($ventasPrevias !== []) {
            throw new RuntimeException("El producto {$producto['codigo']} ya tiene ventas reales; se canceló la adaptación para protegerlas.");
        }
        $productoId = (int) $actual["id"];
    } else {
        $insertar = $db->prepare(
            "INSERT INTO productos
             (codigo, ean13, nombre, marca, descripcion, fecha_alta, categoria_id, precio, stock,
              stock_minimo, unidad_venta_codigo, contenido_presentacion,
              unidades_por_presentacion, unidad_base_codigo, activo)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 1)"
        );
        $insertar->execute([
            $producto["codigo"], $producto["nombre"], $producto["marca"], $producto["descripcion"],
            $producto["fecha_alta"], $producto["categoria_id"], $producto["precio"], $producto["stock_minimo"],
            $producto["unidad"], $producto["contenido"], $producto["unidades"], $producto["unidad_base"],
        ]);
        $productoId = (int) $db->insert_id;
        $actual = ["stock" => 0];
        $creado = true;
    }

    $duplicado = filas($db, "SELECT id FROM productos WHERE ean13 = ? AND id <> ?", [$producto["ean13"], $productoId]);
    if ($duplicado !== []) {
        throw new RuntimeException("El EAN demo {$producto['ean13']} ya pertenece a otro producto.");
    }

    $actualizar = $db->prepare(
        "UPDATE productos SET ean13=?, nombre=?, marca=?, descripcion=?, fecha_alta=?, fecha_baja=NULL,
             categoria_id=?, precio=?, stock_minimo=?, unidad_venta_codigo=?, contenido_presentacion=?,
             unidades_por_presentacion=?, unidad_base_codigo=?, activo=1 WHERE id=?"
    );
    $actualizar->execute([
        $producto["ean13"], $producto["nombre"], $producto["marca"], $producto["descripcion"],
        $producto["fecha_alta"], $producto["categoria_id"], $producto["precio"], $producto["stock_minimo"],
        $producto["unidad"], $producto["contenido"], $producto["unidades"], $producto["unidad_base"], $productoId,
    ]);

    $lotesExistentes = filas($db, "SELECT * FROM producto_lotes WHERE producto_id = ? ORDER BY id FOR UPDATE", [$productoId]);
    $lotesReferenciados = filas(
        $db,
        "SELECT vl.lote_id FROM venta_lotes vl JOIN producto_lotes l ON l.id=vl.lote_id WHERE l.producto_id=? LIMIT 1",
        [$productoId]
    );
    if ($lotesReferenciados !== []) {
        throw new RuntimeException("El producto {$producto['codigo']} tiene lotes utilizados por ventas; no se modificó.");
    }
    if (count($lotesExistentes) > 1) {
        throw new RuntimeException("El producto {$producto['codigo']} ya tiene varios lotes y requiere revisión manual.");
    }

    $stockAnterior = (int) $actual["stock"];
    $primerLote = $producto["lotes"][0];
    if ($lotesExistentes === []) {
        $crearLote = $db->prepare(
            "INSERT INTO producto_lotes
             (producto_id,numero_lote,cantidad_inicial,stock_actual,fecha_ingreso,fecha_vencimiento,estado,origen,usuario_id)
             VALUES (?,?,?,?,?,?,'ACTIVO','ALTA_INICIAL',?)"
        );
        $crearLote->execute([
            $productoId, $primerLote["numero"], $primerLote["cantidad"], $primerLote["cantidad"],
            $primerLote["ingreso"], $primerLote["vencimiento"], $adminId,
        ]);
        $primerLoteId = (int) $db->insert_id;
    } else {
        $primerLoteId = (int) $lotesExistentes[0]["id"];
        $actualizarLote = $db->prepare(
            "UPDATE producto_lotes SET numero_lote=?,cantidad_inicial=?,stock_actual=?,fecha_ingreso=?,
             fecha_vencimiento=?,estado='ACTIVO',origen='ALTA_INICIAL',usuario_id=? WHERE id=?"
        );
        $actualizarLote->execute([
            $primerLote["numero"], $primerLote["cantidad"], $primerLote["cantidad"],
            $primerLote["ingreso"], $primerLote["vencimiento"], $adminId, $primerLoteId,
        ]);
    }

    $stockActual = (int) $primerLote["cantidad"];
    $stock = $db->prepare("UPDATE productos SET stock=? WHERE id=?");
    $stock->execute([$stockActual, $productoId]);
    registrarMovimientoStock(
        $db, $productoId, $producto["nombre"], $adminId, null, "AJUSTE",
        $stockActual - $stockAnterior, $stockAnterior, $stockActual, $producto["unidad_singular"],
        DEMO_CLAVE . ": adaptación del stock inicial al primer lote", $primerLoteId
    );

    $lotesCreados = [$primerLote["numero"]];
    foreach (array_slice($producto["lotes"], 1) as $lote) {
        $crearLote = $db->prepare(
            "INSERT INTO producto_lotes
             (producto_id,numero_lote,cantidad_inicial,stock_actual,fecha_ingreso,fecha_vencimiento,estado,origen,usuario_id)
             VALUES (?,?,?,?,?,?,'ACTIVO','INGRESO',?)"
        );
        $crearLote->execute([
            $productoId, $lote["numero"], $lote["cantidad"], $lote["cantidad"],
            $lote["ingreso"], $lote["vencimiento"], $adminId,
        ]);
        $loteId = (int) $db->insert_id;
        $anterior = $stockActual;
        $stockActual += (int) $lote["cantidad"];
        $stock->execute([$stockActual, $productoId]);
        registrarMovimientoStock(
            $db, $productoId, $producto["nombre"], $adminId, null, "INGRESO",
            (int) $lote["cantidad"], $anterior, $stockActual, $producto["unidad_singular"],
            DEMO_CLAVE . ": ingreso del lote {$lote['numero']}", $loteId
        );
        actualizarFechaMovimiento($db, (int) $db->insert_id, $lote["ingreso"]);
        $lotesCreados[] = $lote["numero"];
    }

    registrarHistorialProducto(
        $db,
        $productoId,
        $adminId,
        "DEMO_ADAPTACION",
        DEMO_CLAVE . ": producto de demostración; EAN ficticio no comercial {$producto['ean13']}."
    );

    return [
        "id" => $productoId,
        "codigo" => $producto["codigo"],
        "nombre" => $producto["nombre"],
        "ean13" => $producto["ean13"],
        "creado" => $creado,
        "stock_inicial_demo" => $stockActual,
        "stock_anterior" => $stockAnterior,
        "lotes" => $lotesCreados,
    ];
}

function crearVentaDemo(
    mysqli $db,
    int $productoId,
    int $vendedorId,
    int $clienteId,
    int $cantidad,
    string $margen,
    string $fecha,
    string $clave
): array {
    $productoStmt = $db->prepare(
        "SELECT p.nombre,p.precio,p.stock,p.unidades_por_presentacion,
                u.nombre_singular,u.nombre_plural,ub.nombre_singular unidad_base_singular
         FROM productos p JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
         JOIN unidades_venta ub ON ub.codigo=p.unidad_base_codigo
         WHERE p.id=? AND p.activo=1 FOR UPDATE"
    );
    $productoStmt->execute([$productoId]);
    $producto = $productoStmt->get_result()->fetch_assoc();
    if (!$producto) throw new RuntimeException("Producto demo inexistente o inactivo.");
    $vendible = stockVendible($db, $productoId, true);
    if ($vendible < $cantidad) throw new RuntimeException("Stock vigente insuficiente para la venta demo {$clave}.");

    $bonificacion = obtenerBonificacion($db, $cantidad);
    $calculo = calcularPrecioComercial((string) $producto["precio"], $margen, $bonificacion, $cantidad);
    $insertar = $db->prepare(
        "INSERT INTO ventas
         (producto_id,producto_nombre,cantidad,precio_referencial_snapshot,porcentaje_vendedor,
          bonificacion_porcentaje,precio_final_unitario,unidad_venta_snapshot,
          unidad_venta_plural_snapshot,unidades_por_presentacion_snapshot,unidad_base_snapshot,
          precio_unitario,total,usuario_id,cliente_id,fecha,estado)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'ACTIVA')"
    );
    $insertar->execute([
        $productoId, $producto["nombre"], $cantidad, $calculo["precio_referencial"],
        $calculo["porcentaje_vendedor"], $calculo["bonificacion_porcentaje"],
        $calculo["precio_final_unitario"], $producto["nombre_singular"], $producto["nombre_plural"],
        $producto["unidades_por_presentacion"], $producto["unidad_base_singular"],
        $calculo["precio_final_unitario"], $calculo["total"], $vendedorId, $clienteId, $fecha,
    ]);
    $ventaId = (int) $db->insert_id;
    asignarLotesFefo($db, $productoId, $ventaId, $cantidad);
    $stockAnterior = (int) $producto["stock"];
    $stockNuevo = $stockAnterior - $cantidad;
    $actualizarStock = $db->prepare("UPDATE productos SET stock=? WHERE id=?");
    $actualizarStock->execute([$stockNuevo, $productoId]);

    $motivo = DEMO_CLAVE . ":{$clave}";
    $historial = $db->prepare(
        "INSERT INTO venta_historial
         (venta_id,usuario_id,tipo,cantidad_nueva,bonificacion_nueva,precio_final_nuevo,
          total_nuevo,estado_anterior,estado_nuevo,motivo,fecha)
         VALUES (?,?,'VENTA_CREADA',?,?,?,?, 'NUEVA','ACTIVA',?,?)"
    );
    $historial->execute([
        $ventaId, $vendedorId, $cantidad, $calculo["bonificacion_porcentaje"],
        $calculo["precio_final_unitario"], $calculo["total"], $motivo, $fecha,
    ]);
    registrarMovimientoStock(
        $db, $productoId, $producto["nombre"], $vendedorId, $ventaId, "VENTA", -$cantidad,
        $stockAnterior, $stockNuevo, $producto["nombre_singular"], $motivo . " mediante FEFO"
    );
    actualizarFechaMovimiento($db, (int) $db->insert_id, $fecha);

    return ["id" => $ventaId, "clave" => $clave, "calculo" => $calculo, "cantidad" => $cantidad];
}

function modificarVentaDemo(mysqli $db, int $ventaId, int $cantidadNueva, int $actorId, string $fecha, string $clave): void {
    $stmt = $db->prepare("SELECT * FROM ventas WHERE id=? FOR UPDATE");
    $stmt->execute([$ventaId]);
    $venta = $stmt->get_result()->fetch_assoc();
    if (!$venta || $venta["estado"] === "CANCELADA") throw new RuntimeException("Venta demo no modificable.");
    $productoStmt = $db->prepare("SELECT stock FROM productos WHERE id=? FOR UPDATE");
    $productoStmt->execute([$venta["producto_id"]]);
    $stockAnterior = (int) $productoStmt->get_result()->fetch_assoc()["stock"];
    $cantidadAnterior = (int) $venta["cantidad"];
    $diferencia = $cantidadNueva - $cantidadAnterior;
    if ($diferencia > 0) asignarLotesFefo($db, (int) $venta["producto_id"], $ventaId, $diferencia);
    elseif ($diferencia < 0) devolverCantidadALotes($db, (int) $venta["producto_id"], $ventaId, -$diferencia, $actorId);
    $stockNuevo = $stockAnterior - $diferencia;
    $db->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$stockNuevo, $venta["producto_id"]]);

    $bonificacion = obtenerBonificacion($db, $cantidadNueva);
    $calculo = calcularPrecioComercial(
        (string) $venta["precio_referencial_snapshot"],
        (string) $venta["porcentaje_vendedor"],
        $bonificacion,
        $cantidadNueva
    );
    $actualizar = $db->prepare(
        "UPDATE ventas SET cantidad=?,bonificacion_porcentaje=?,precio_final_unitario=?,precio_unitario=?,
         total=?,estado='MODIFICADA',fecha_modificacion=? WHERE id=?"
    );
    $actualizar->execute([
        $cantidadNueva, $calculo["bonificacion_porcentaje"], $calculo["precio_final_unitario"],
        $calculo["precio_final_unitario"], $calculo["total"], $fecha, $ventaId,
    ]);
    $motivo = DEMO_CLAVE . ":{$clave}";
    $historial = $db->prepare(
        "INSERT INTO venta_historial
         (venta_id,usuario_id,tipo,cantidad_anterior,cantidad_nueva,bonificacion_anterior,
          bonificacion_nueva,precio_final_anterior,precio_final_nuevo,total_anterior,total_nuevo,
          estado_anterior,estado_nuevo,motivo,fecha)
         VALUES (?,?,'VENDEDOR_MODIFICA_CANTIDAD',?,?,?,?,?,?,?,?,?,'MODIFICADA',?,?)"
    );
    $historial->execute([
        $ventaId, $actorId, $cantidadAnterior, $cantidadNueva, $venta["bonificacion_porcentaje"],
        $calculo["bonificacion_porcentaje"], $venta["precio_final_unitario"], $calculo["precio_final_unitario"],
        $venta["total"], $calculo["total"], $venta["estado"], $motivo, $fecha,
    ]);
    registrarMovimientoStock(
        $db, (int) $venta["producto_id"], $venta["producto_nombre"], $actorId, $ventaId,
        "MODIFICACION_VENTA", -$diferencia, $stockAnterior, $stockNuevo,
        $venta["unidad_venta_snapshot"], $motivo
    );
    actualizarFechaMovimiento($db, (int) $db->insert_id, $fecha);
}

function cancelarVentaDemo(mysqli $db, int $ventaId, int $actorId, string $fecha, string $clave): void {
    $stmt = $db->prepare("SELECT * FROM ventas WHERE id=? FOR UPDATE");
    $stmt->execute([$ventaId]);
    $venta = $stmt->get_result()->fetch_assoc();
    if (!$venta) throw new RuntimeException("Venta demo inexistente.");
    if ($venta["estado"] === "CANCELADA") return;
    $productoStmt = $db->prepare("SELECT stock FROM productos WHERE id=? FOR UPDATE");
    $productoStmt->execute([$venta["producto_id"]]);
    $stockAnterior = (int) $productoStmt->get_result()->fetch_assoc()["stock"];
    devolverCantidadALotes($db, (int) $venta["producto_id"], $ventaId, (int) $venta["cantidad"], $actorId);
    $stockNuevo = $stockAnterior + (int) $venta["cantidad"];
    $db->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$stockNuevo, $venta["producto_id"]]);
    $motivo = DEMO_CLAVE . ":{$clave}";
    $db->prepare("UPDATE ventas SET estado='CANCELADA',fecha_modificacion=?,motivo_cancelacion=? WHERE id=?")
        ->execute([$fecha, $motivo, $ventaId]);
    $historial = $db->prepare(
        "INSERT INTO venta_historial
         (venta_id,usuario_id,tipo,cantidad_anterior,cantidad_nueva,bonificacion_anterior,
          bonificacion_nueva,precio_final_anterior,precio_final_nuevo,total_anterior,total_nuevo,
          estado_anterior,estado_nuevo,motivo,fecha)
         VALUES (?,?,'VENDEDOR_CANCELA',?,0,?,0,?,0,?,0,?,'CANCELADA',?,?)"
    );
    $historial->execute([
        $ventaId, $actorId, $venta["cantidad"], $venta["bonificacion_porcentaje"],
        $venta["precio_final_unitario"], $venta["total"], $venta["estado"], $motivo, $fecha,
    ]);
    registrarMovimientoStock(
        $db, (int) $venta["producto_id"], $venta["producto_nombre"], $actorId, $ventaId,
        "CANCELACION", (int) $venta["cantidad"], $stockAnterior, $stockNuevo,
        $venta["unidad_venta_snapshot"], $motivo
    );
    actualizarFechaMovimiento($db, (int) $db->insert_id, $fecha);
}

function resumenDemo(mysqli $db): array {
    $productos = filas(
        $db,
        "SELECT p.id,p.codigo,p.ean13,p.nombre,p.marca,p.precio,p.stock,p.stock_minimo,
                p.unidad_venta_codigo,p.unidades_por_presentacion,p.unidad_base_codigo,p.fecha_alta
         FROM productos p JOIN producto_historial ph ON ph.producto_id=p.id
         WHERE ph.tipo='DEMO_ADAPTACION' AND ph.detalle LIKE ? ORDER BY p.id",
        [DEMO_CLAVE . "%"]
    );
    $ventas = filas(
        $db,
        "SELECT DISTINCT v.id,v.fecha,v.producto_id,v.producto_nombre,v.cantidad,
                v.precio_referencial_snapshot,v.porcentaje_vendedor,v.bonificacion_porcentaje,
                v.precio_final_unitario,v.total,v.estado,
                CONCAT_WS(' ',u.nombre,u.apellido) vendedor,
                CONCAT_WS(' ',c.nombre,c.apellido) cliente
         FROM ventas v JOIN venta_historial vh ON vh.venta_id=v.id
         LEFT JOIN usuarios u ON u.id=v.usuario_id LEFT JOIN usuarios c ON c.id=v.cliente_id
         WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? ORDER BY v.fecha,v.id",
        [DEMO_CLAVE . ":%"]
    );
    $dias = (int) (filas($db, "SELECT dias_alerta_vencimiento FROM configuracion_comercial WHERE id=1")[0]["dias_alerta_vencimiento"] ?? 30);
    return [
        "usuarios" => filas($db, "SELECT id,rol FROM usuarios ORDER BY id"),
        "productos" => $productos,
        "lotes" => filas($db, "SELECT l.id,p.nombre,l.numero_lote,l.cantidad_inicial,l.stock_actual,l.fecha_ingreso,l.fecha_vencimiento,l.estado FROM producto_lotes l JOIN productos p ON p.id=l.producto_id WHERE l.numero_lote LIKE 'DEMO-%' ORDER BY l.id"),
        "ventas" => $ventas,
        "ventas_por_bonificacion" => filas($db, "SELECT bonificacion_porcentaje,COUNT(DISTINCT v.id) cantidad FROM ventas v JOIN venta_historial vh ON vh.venta_id=v.id WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? GROUP BY bonificacion_porcentaje ORDER BY bonificacion_porcentaje", [DEMO_CLAVE . ":%"]),
        "ventas_por_vendedor" => filas($db, "SELECT u.id,CONCAT_WS(' ',u.nombre,u.apellido) vendedor,COUNT(DISTINCT v.id) ventas FROM ventas v JOIN usuarios u ON u.id=v.usuario_id JOIN venta_historial vh ON vh.venta_id=v.id WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? GROUP BY u.id,u.nombre,u.apellido", [DEMO_CLAVE . ":%"]),
        "ventas_por_cliente" => filas($db, "SELECT u.id,CONCAT_WS(' ',u.nombre,u.apellido) cliente,COUNT(DISTINCT v.id) ventas FROM ventas v JOIN usuarios u ON u.id=v.cliente_id JOIN venta_historial vh ON vh.venta_id=v.id WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? GROUP BY u.id,u.nombre,u.apellido", [DEMO_CLAVE . ":%"]),
        "ventas_por_producto" => filas($db, "SELECT v.producto_nombre,COUNT(DISTINCT v.id) operaciones,SUM(CASE WHEN v.estado='CANCELADA' THEN 0 ELSE v.cantidad END) cantidad_vigente,SUM(CASE WHEN v.estado='CANCELADA' THEN 0 ELSE v.total END) monto_vigente FROM ventas v JOIN venta_historial vh ON vh.venta_id=v.id WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? GROUP BY v.producto_id,v.producto_nombre ORDER BY v.producto_nombre", [DEMO_CLAVE . ":%"]),
        "sin_stock" => filas($db, "SELECT nombre,stock,stock_minimo FROM productos WHERE stock=0 AND activo=1 ORDER BY nombre"),
        "stock_bajo" => filas($db, "SELECT nombre,stock,stock_minimo FROM productos WHERE stock>0 AND stock<=stock_minimo AND activo=1 ORDER BY nombre"),
        "proximos" => filas($db, "SELECT p.nombre,l.numero_lote,l.stock_actual,l.fecha_vencimiento FROM producto_lotes l JOIN productos p ON p.id=l.producto_id WHERE l.estado='ACTIVO' AND l.stock_actual>0 AND l.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY) ORDER BY l.fecha_vencimiento", [$dias]),
        "vencidos" => filas($db, "SELECT p.nombre,l.numero_lote,l.stock_actual,l.fecha_vencimiento FROM producto_lotes l JOIN productos p ON p.id=l.producto_id WHERE l.estado='ACTIVO' AND l.stock_actual>0 AND l.fecha_vencimiento<CURDATE() ORDER BY l.fecha_vencimiento"),
        "movimientos" => filas($db, "SELECT tipo,COUNT(*) cantidad FROM stock_movimientos WHERE motivo LIKE ? GROUP BY tipo ORDER BY tipo", [DEMO_CLAVE . "%"]),
        "estados_ventas" => filas($db, "SELECT v.estado,COUNT(DISTINCT v.id) cantidad FROM ventas v JOIN venta_historial vh ON vh.venta_id=v.id WHERE vh.tipo='VENTA_CREADA' AND vh.motivo LIKE ? GROUP BY v.estado ORDER BY v.estado", [DEMO_CLAVE . ":%"]),
    ];
}

$db->query(
    "CREATE TABLE IF NOT EXISTS demo_seed_control (
        clave VARCHAR(80) PRIMARY KEY,
        aplicado_en DATETIME NOT NULL,
        resumen_json LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$yaAplicado = filas($db, "SELECT clave,aplicado_en FROM demo_seed_control WHERE clave=?", [DEMO_CLAVE]);
if ($yaAplicado !== []) {
    echo "La carga " . DEMO_CLAVE . " ya estaba aplicada. No se duplicó ningún dato.\n";
    echo json_encode(resumenDemo($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

$usuariosAntes = filas($db, "SELECT * FROM usuarios ORDER BY id");
$ventasAntes = filas($db, "SELECT * FROM ventas ORDER BY id");
$historialAntes = filas($db, "SELECT * FROM venta_historial ORDER BY id");
$movimientosAntes = filas($db, "SELECT * FROM stock_movimientos ORDER BY id");
$productosAntes = filas($db, "SELECT id,codigo,stock FROM productos ORDER BY id");
$roles = array_count_values(array_column($usuariosAntes, "rol"));

echo "Usuarios detectados: " . count($usuariosAntes) . " (admin=" . ($roles["admin"] ?? 0)
    . ", vendedor=" . ($roles["vendedor"] ?? 0) . ", cliente=" . ($roles["cliente"] ?? 0) . ").\n";
echo "Productos detectados: " . count($productosAntes) . "; ventas históricas: " . count($ventasAntes) . ".\n";

if (!$aplicar) {
    echo "Diagnóstico correcto. Ejecutá nuevamente con --aplicar para realizar la carga incremental.\n";
    exit;
}

$admins = filas($db, "SELECT id FROM usuarios WHERE rol='admin' ORDER BY id");
$vendedores = filas($db, "SELECT id FROM usuarios WHERE rol='vendedor' ORDER BY id");
$clientes = filas($db, "SELECT id FROM usuarios WHERE rol='cliente' ORDER BY id");
if ($admins === [] || $vendedores === [] || $clientes === []) {
    throw new RuntimeException("Se requiere al menos un administrador, un vendedor y un cliente existentes. No se creó ningún usuario.");
}
$adminId = (int) $admins[0]["id"];
$vendedorId = (int) $vendedores[0]["id"];
$clienteId = (int) $clientes[0]["id"];

$db->begin_transaction();
try {
    $db->query("INSERT INTO unidades_venta (codigo,nombre_singular,nombre_plural,activo) VALUES ('BOTELLA','Botella','Botellas',1) ON DUPLICATE KEY UPDATE nombre_singular='Botella',nombre_plural='Botellas',activo=1");

    $reglas = [
        ["desde" => 6, "hasta" => 9, "porcentaje" => "5.00"],
        ["desde" => 10, "hasta" => null, "porcentaje" => "10.00"],
    ];
    foreach ($reglas as $regla) {
        $coincidente = filas(
            $db,
            "SELECT id FROM bonificaciones_cantidad WHERE cantidad_desde=? AND cantidad_hasta <=> ? AND porcentaje=? AND activo=1 LIMIT 1",
            [$regla["desde"], $regla["hasta"], $regla["porcentaje"]]
        );
        if ($coincidente === []) {
            $insertar = $db->prepare("INSERT INTO bonificaciones_cantidad (cantidad_desde,cantidad_hasta,porcentaje,activo) VALUES (?,?,?,1)");
            $insertar->execute([$regla["desde"], $regla["hasta"], $regla["porcentaje"]]);
        }
    }
    if ((float) obtenerBonificacion($db, 2) !== 0.0 || (float) obtenerBonificacion($db, 6) !== 5.0 || (float) obtenerBonificacion($db, 10) !== 10.0) {
        throw new RuntimeException("Las reglas activas no producen las bonificaciones demo esperadas 0%, 5% y 10%.");
    }

    $definiciones = [
        ["codigo"=>"ALF-JORG-CHO-24","nombre"=>"Alfajor Chocolate Triple","marca"=>"Demo Dulce","descripcion"=>"Alfajor triple relleno con dulce de leche y cobertura sabor chocolate. " . DEMO_AVISO,"categoria"=>"Alfajores","unidad"=>"CAJA","unidad_singular"=>"Caja","unidades"=>24,"unidad_base"=>"UNIDAD","contenido"=>"24 unidades","precio"=>"18000.00","stock_minimo"=>5,"alta"=>-60,"lotes"=>[["numero"=>"DEMO-AFT-001","cantidad"=>12,"ingreso"=>-45,"vence"=>15],["numero"=>"DEMO-AFT-002","cantidad"=>10,"ingreso"=>-15,"vence"=>90]]],
        ["codigo"=>"ALF-GUAY-TRI-24","nombre"=>"Alfajor Blanco Triple","marca"=>"Demo Dulce","descripcion"=>"Alfajor triple relleno con cobertura blanca. " . DEMO_AVISO,"categoria"=>"Alfajores","unidad"=>"CAJA","unidad_singular"=>"Caja","unidades"=>24,"unidad_base"=>"UNIDAD","contenido"=>"24 unidades","precio"=>"17500.00","stock_minimo"=>4,"alta"=>-52,"lotes"=>[["numero"=>"DEMO-AFB-001","cantidad"=>5,"ingreso"=>-40,"vence"=>25],["numero"=>"DEMO-AFB-002","cantidad"=>7,"ingreso"=>-10,"vence"=>120]]],
        ["codigo"=>"GOL-PICO-DUL-50","nombre"=>"Chupetines Frutales","marca"=>"Candy Demo","descripcion"=>"Bolsa surtida de chupetines frutales. " . DEMO_AVISO,"categoria"=>"Golosinas","unidad"=>"BOLSA","unidad_singular"=>"Bolsa","unidades"=>50,"unidad_base"=>"UNIDAD","contenido"=>"50 unidades","precio"=>"9500.00","stock_minimo"=>5,"alta"=>-45,"lotes"=>[["numero"=>"DEMO-CHF-001","cantidad"=>25,"ingreso"=>-30,"vence"=>150]]],
        ["codigo"=>"GOL-MAST-SUR-800","nombre"=>"Caramelos Masticables","marca"=>"Candy Demo","descripcion"=>"Caramelos masticables surtidos. " . DEMO_AVISO,"categoria"=>"Golosinas","unidad"=>"BOLSA","unidad_singular"=>"Bolsa","unidades"=>100,"unidad_base"=>"UNIDAD","contenido"=>"100 unidades","precio"=>"7800.00","stock_minimo"=>8,"alta"=>-38,"lotes"=>[["numero"=>"DEMO-CAM-001","cantidad"=>15,"ingreso"=>-35,"vence"=>45],["numero"=>"DEMO-CAM-002","cantidad"=>15,"ingreso"=>-5,"vence"=>180]]],
        ["codigo"=>"CHO-LECH-100-20","nombre"=>"Chocolate con Leche 100 g","marca"=>"Choco Demo","descripcion"=>"Tableta de chocolate con leche de 100 gramos. " . DEMO_AVISO,"categoria"=>"Chocolates","unidad"=>"CAJA","unidad_singular"=>"Caja","unidades"=>20,"unidad_base"=>"UNIDAD","contenido"=>"20 unidades","precio"=>"32000.00","stock_minimo"=>3,"alta"=>-31,"lotes"=>[["numero"=>"DEMO-TEST-VENCIDO-001","cantidad"=>1,"ingreso"=>-80,"vence"=>-10],["numero"=>"DEMO-CHL-001","cantidad"=>5,"ingreso"=>-30,"vence"=>70],["numero"=>"DEMO-CHL-002","cantidad"=>5,"ingreso"=>-5,"vence"=>180]]],
        ["codigo"=>"GAL-CHOCOLINAS","nombre"=>"Galletitas de Chocolate","marca"=>"Galleta Demo","descripcion"=>"Galletitas dulces sabor chocolate. " . DEMO_AVISO,"categoria"=>"Galletitas","unidad"=>"CAJA","unidad_singular"=>"Caja","unidades"=>18,"unidad_base"=>"PAQUETE","contenido"=>"18 paquetes","precio"=>"24000.00","stock_minimo"=>4,"alta"=>-24,"lotes"=>[["numero"=>"DEMO-GCH-001","cantidad"=>7,"ingreso"=>-25,"vence"=>110],["numero"=>"DEMO-GCH-002","cantidad"=>8,"ingreso"=>-8,"vence"=>210]]],
        ["codigo"=>"SNA-PAPAS-CLA-20","nombre"=>"Papas Fritas Clásicas","marca"=>"Snack Demo","descripcion"=>"Papas fritas clásicas en paquetes individuales. " . DEMO_AVISO,"categoria"=>"Snacks","unidad"=>"CAJA","unidad_singular"=>"Caja","unidades"=>20,"unidad_base"=>"PAQUETE","contenido"=>"20 paquetes","precio"=>"30000.00","stock_minimo"=>5,"alta"=>-17,"lotes"=>[["numero"=>"DEMO-PFC-001","cantidad"=>10,"ingreso"=>-20,"vence"=>120],["numero"=>"DEMO-PFC-002","cantidad"=>10,"ingreso"=>-4,"vence"=>240]]],
        ["codigo"=>"BEB-COCA-500-12","nombre"=>"Gaseosa Cola 500 ml","marca"=>"Bebida Demo","descripcion"=>"Gaseosa sabor cola en botella de 500 ml. " . DEMO_AVISO,"categoria"=>"Bebidas","unidad"=>"PACK","unidad_singular"=>"Pack","unidades"=>12,"unidad_base"=>"BOTELLA","contenido"=>"12 botellas","precio"=>"18000.00","stock_minimo"=>5,"alta"=>-10,"lotes"=>[["numero"=>"DEMO-GCO-001","cantidad"=>18,"ingreso"=>-25,"vence"=>20],["numero"=>"DEMO-GCO-002","cantidad"=>4,"ingreso"=>-3,"vence"=>25]]],
    ];

    $productos = [];
    foreach ($definiciones as $indice => $definicion) {
        $definicion["ean13"] = eanDemo(101 + $indice);
        $definicion["fecha_alta"] = fechaDemo($definicion["alta"], "09:00:00");
        $definicion["categoria_id"] = categoriaId($db, $definicion["categoria"]);
        foreach ($definicion["lotes"] as &$lote) {
            $lote["ingreso"] = fechaDemo($lote["ingreso"], "08:30:00");
            $lote["vencimiento"] = fechaVencimientoDemo($lote["vence"]);
        }
        unset($lote);
        $adaptado = adaptarProductoDemo($db, $definicion, $adminId);
        $productos[$definicion["codigo"]] = $adaptado;
    }

    $planes = [
        ["V01","ALF-JORG-CHO-24",2,"10.00",-55], ["V02","ALF-GUAY-TRI-24",2,"5.00",-52],
        ["V03","GOL-PICO-DUL-50",10,"15.00",-49], ["V04","GOL-MAST-SUR-800",3,"0.00",-46],
        ["V05","CHO-LECH-100-20",2,"20.00",-43], ["V06","GAL-CHOCOLINAS",6,"10.00",-40],
        ["V07","SNA-PAPAS-CLA-20",10,"5.00",-37], ["V08","BEB-COCA-500-12",10,"15.00",-34],
        ["V09","ALF-JORG-CHO-24",6,"10.00",-31], ["V10","ALF-GUAY-TRI-24",6,"0.00",-28],
        ["V11","GOL-PICO-DUL-50",8,"5.00",-25], ["V12","GOL-MAST-SUR-800",6,"15.00",-22],
        ["V13","CHO-LECH-100-20",1,"0.00",-19], ["V14","GAL-CHOCOLINAS",2,"20.00",-16],
        ["V15","SNA-PAPAS-CLA-20",2,"10.00",-13], ["V16","BEB-COCA-500-12",5,"5.00",-10],
        ["V17","ALF-JORG-CHO-24",1,"0.00",-8], ["V18","GOL-PICO-DUL-50",7,"10.00",-6],
        ["V19","GOL-MAST-SUR-800",5,"10.00",-5], ["V20","BEB-COCA-500-12",2,"0.00",-4],
        ["V21","ALF-JORG-CHO-24",3,"5.00",-3], ["V22","BEB-COCA-500-12",2,"10.00",-3],
        ["V23","BEB-COCA-500-12",2,"15.00",-2],
    ];
    $ventasDemo = [];
    foreach ($planes as $indice => [$clave,$codigo,$cantidad,$margen,$dias]) {
        $ventasDemo[$clave] = crearVentaDemo(
            $db, $productos[$codigo]["id"], $vendedorId, $clienteId, $cantidad, $margen,
            fechaDemo($dias, sprintf("%02d:15:00", 9 + ($indice % 8))), $clave
        );
    }
    modificarVentaDemo($db, $ventasDemo["V19"]["id"], 3, $vendedorId, fechaDemo(-4, "14:20:00"), "V19-MODIFICADA-5-A-3");
    cancelarVentaDemo($db, $ventasDemo["V21"]["id"], $vendedorId, fechaDemo(-2, "16:10:00"), "V21-CANCELADA");
    cancelarVentaDemo($db, $ventasDemo["V23"]["id"], $vendedorId, fechaDemo(-1, "11:30:00"), "V23-CANCELADA");

    if (huella(filas($db, "SELECT * FROM usuarios ORDER BY id")) !== huella($usuariosAntes)) {
        throw new RuntimeException("La verificación detectó cambios en usuarios o TOTP.");
    }
    foreach ($ventasAntes as $ventaAnterior) {
        $actual = filas($db, "SELECT * FROM ventas WHERE id=?", [(int) $ventaAnterior["id"]]);
        if ($actual === [] || huella($actual[0]) !== huella($ventaAnterior)) throw new RuntimeException("Cambió una venta histórica real.");
    }
    foreach ($historialAntes as $filaAnterior) {
        $actual = filas($db, "SELECT * FROM venta_historial WHERE id=?", [(int) $filaAnterior["id"]]);
        if ($actual === [] || huella($actual[0]) !== huella($filaAnterior)) throw new RuntimeException("Cambió una auditoría histórica de venta.");
    }
    foreach ($movimientosAntes as $filaAnterior) {
        $actual = filas($db, "SELECT * FROM stock_movimientos WHERE id=?", [(int) $filaAnterior["id"]]);
        if ($actual === [] || huella($actual[0]) !== huella($filaAnterior)) throw new RuntimeException("Cambió un movimiento histórico.");
    }
    foreach ($productosAntes as $productoAnterior) {
        if (filas($db, "SELECT id FROM productos WHERE id=? AND codigo <=> ?", [(int) $productoAnterior["id"], $productoAnterior["codigo"]]) === []) {
            throw new RuntimeException("Falta un producto que existía antes de la carga.");
        }
    }

    $resumen = resumenDemo($db);
    $marker = $db->prepare("INSERT INTO demo_seed_control (clave,aplicado_en,resumen_json) VALUES (?,NOW(),?)");
    $marker->execute([DEMO_CLAVE, json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    $db->commit();

    echo "Carga demo aplicada correctamente.\n";
    echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignorado) {}
    fwrite(STDERR, "No se aplicó la carga demo: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
