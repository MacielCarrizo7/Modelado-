<?php
require "seguridad.php";
require "conexion.php";
requerirUsuarioJson(["admin", "vendedor"]);
header("Content-Type: application/json; charset=UTF-8");

$conexion = Conexion::obtenerInstancia();
$desde = trim($_GET["desde"] ?? "");
$hasta = trim($_GET["hasta"] ?? "");
$productoIdTexto = trim($_GET["producto_id"] ?? "");
$vendedorIdTexto = trim($_GET["vendedor_id"] ?? "");
$estado = strtoupper(trim($_GET["estado"] ?? ""));
$estadosValidos = ["ACTIVA", "MODIFICADA", "CANCELADA"];

if (($desde !== "" && !fechaIsoValida($desde)) || ($hasta !== "" && !fechaIsoValida($hasta))) responderJson(["error" => "Ingresá fechas válidas."], 400);
if ($desde !== "" && $hasta !== "" && $desde > $hasta) responderJson(["error" => "La fecha desde no puede ser posterior a la fecha hasta."], 400);
if ($estado !== "" && !in_array($estado, $estadosValidos, true)) responderJson(["error" => "El estado seleccionado no es válido."], 400);

$condiciones = [];
$parametros = [];
if ($_SESSION["usuario_rol"] === "vendedor") {
    $condiciones[] = "v.usuario_id = ?";
    $parametros[] = (int) $_SESSION["usuario_id"];
} elseif ($vendedorIdTexto !== "") {
    if (!ctype_digit($vendedorIdTexto) || (int) $vendedorIdTexto <= 0) responderJson(["error" => "El vendedor seleccionado no es válido."], 400);
    $vendedorId = (int) $vendedorIdTexto;
    $check = $conexion->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'vendedor'");
    $check->execute([$vendedorId]);
    if (!$check->get_result()->fetch_assoc()) responderJson(["error" => "El vendedor seleccionado no existe."], 400);
    $condiciones[] = "v.usuario_id = ?";
    $parametros[] = $vendedorId;
}
if ($desde !== "") { $condiciones[] = "v.fecha >= ?"; $parametros[] = $desde . " 00:00:00"; }
if ($hasta !== "") { $condiciones[] = "v.fecha < ?"; $parametros[] = (new DateTimeImmutable($hasta))->modify("+1 day")->format("Y-m-d 00:00:00"); }
if ($productoIdTexto !== "") {
    if (!ctype_digit($productoIdTexto) || (int) $productoIdTexto <= 0) responderJson(["error" => "El producto seleccionado no es válido."], 400);
    $productoId = (int) $productoIdTexto;
    $check = $conexion->prepare("SELECT id FROM productos WHERE id = ?");
    $check->execute([$productoId]);
    if (!$check->get_result()->fetch_assoc()) responderJson(["error" => "El producto seleccionado no existe."], 400);
    $condiciones[] = "v.producto_id = ?"; $parametros[] = $productoId;
}
if ($estado !== "") { $condiciones[] = "v.estado = ?"; $parametros[] = $estado; }

$sql = "SELECT v.id, v.producto_id, v.producto_nombre, v.cantidad,
               v.precio_referencial_snapshot, v.porcentaje_vendedor,
               v.bonificacion_porcentaje, v.precio_final_unitario,
               v.unidad_venta_snapshot, v.unidad_venta_plural_snapshot, v.precio_unitario, v.total, v.fecha, v.estado,
               v.fecha_modificacion, v.motivo_cancelacion, v.cliente_id, v.usuario_id,
               CONCAT_WS(' ', cliente.nombre, cliente.apellido) AS cliente,
               CONCAT_WS(' ', vendedor.nombre, vendedor.apellido) AS vendedor
        FROM ventas v
        LEFT JOIN usuarios cliente ON v.cliente_id = cliente.id
        LEFT JOIN usuarios vendedor ON v.usuario_id = vendedor.id";
if ($condiciones !== []) $sql .= " WHERE " . implode(" AND ", $condiciones);
$sql .= " ORDER BY v.fecha DESC, v.id DESC";
$stmt = $conexion->prepare($sql);
$stmt->execute($parametros);
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);
