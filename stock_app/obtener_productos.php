<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
requerirUsuarioJson(["admin", "vendedor", "cliente"]);

$db = Conexion::obtenerInstancia();
$rol = $_SESSION["usuario_rol"];
$condiciones = [];
$parametros = [];

$productoId = filter_input(INPUT_GET, "producto_id", FILTER_VALIDATE_INT) ?: 0;
$ean13 = trim($_GET["ean13"] ?? "");
$nombre = trim($_GET["nombre"] ?? "");
$marca = trim($_GET["marca"] ?? "");
$stockEstado = $_GET["stock_estado"] ?? "";
$estado = strtoupper(trim($_GET["estado"] ?? "ACTIVO"));
$vencimiento = $_GET["vencimiento"] ?? "";
$precioMin = trim($_GET["precio_min"] ?? "");
$precioMax = trim($_GET["precio_max"] ?? "");
$stockMin = trim($_GET["stock_min"] ?? "");
$stockMax = trim($_GET["stock_max"] ?? "");

if ($precioMin !== "" && (!decimalValido($precioMin, 10) || decimalAEntero($precioMin) < 0)) responderJson(["error" => "El precio mínimo no es válido."], 400);
if ($precioMax !== "" && (!decimalValido($precioMax, 10) || decimalAEntero($precioMax) < 0)) responderJson(["error" => "El precio máximo no es válido."], 400);
if ($precioMin !== "" && $precioMax !== "" && decimalAEntero($precioMax) < decimalAEntero($precioMin)) responderJson(["error" => "El precio máximo debe ser mayor o igual al mínimo."], 400);
if ($stockMin !== "" && !ctype_digit($stockMin)) responderJson(["error" => "El stock mínimo del filtro no es válido."], 400);
if ($stockMax !== "" && !ctype_digit($stockMax)) responderJson(["error" => "El stock máximo del filtro no es válido."], 400);
if ($stockMin !== "" && $stockMax !== "" && (int) $stockMax < (int) $stockMin) responderJson(["error" => "El stock máximo debe ser mayor o igual al mínimo."], 400);

if ($rol !== "admin") {
    $condiciones[] = "p.activo = 1";
} elseif ($estado === "ACTIVO") {
    $condiciones[] = "p.activo = 1";
} elseif ($estado === "INACTIVO") {
    $condiciones[] = "p.activo = 0";
} elseif ($estado !== "TODOS") {
    responderJson(["error" => "Estado de producto inválido."], 400);
}
if ($productoId > 0) { $condiciones[] = "p.id = ?"; $parametros[] = $productoId; }
if ($ean13 !== "") { $condiciones[] = "p.ean13 = ?"; $parametros[] = $ean13; }
if ($nombre !== "") { $condiciones[] = "p.nombre LIKE ?"; $parametros[] = "%{$nombre}%"; }
if ($marca !== "") { $condiciones[] = "p.marca LIKE ?"; $parametros[] = "%{$marca}%"; }
if ($precioMin !== "") { $condiciones[] = "p.precio >= ?"; $parametros[] = decimalNormalizado($precioMin); }
if ($precioMax !== "") { $condiciones[] = "p.precio <= ?"; $parametros[] = decimalNormalizado($precioMax); }
if ($stockMin !== "") { $condiciones[] = "p.stock >= ?"; $parametros[] = (int) $stockMin; }
if ($stockMax !== "") { $condiciones[] = "p.stock <= ?"; $parametros[] = (int) $stockMax; }

if ($stockEstado === "con_stock") $condiciones[] = "p.stock > 0";
elseif ($stockEstado === "sin_stock") $condiciones[] = "p.stock = 0";
elseif ($stockEstado === "bajo") $condiciones[] = "p.stock > 0 AND p.stock <= p.stock_minimo";
elseif ($stockEstado !== "") responderJson(["error" => "Filtro de stock inválido."], 400);

if ($vencimiento === "proximo") {
    $condiciones[] = "EXISTS (SELECT 1 FROM producto_lotes lx WHERE lx.producto_id=p.id AND lx.estado='ACTIVO' AND lx.stock_actual>0 AND lx.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (SELECT dias_alerta_vencimiento FROM configuracion_comercial WHERE id=1) DAY))";
} elseif ($vencimiento === "vencido") {
    $condiciones[] = "EXISTS (SELECT 1 FROM producto_lotes lx WHERE lx.producto_id=p.id AND lx.estado='ACTIVO' AND lx.stock_actual>0 AND lx.fecha_vencimiento < CURDATE())";
} elseif ($vencimiento !== "") responderJson(["error" => "Filtro de vencimiento inválido."], 400);

$sql =
    "SELECT p.id, p.codigo, p.ean13, p.nombre, p.marca, p.descripcion, p.fecha_alta,
            p.fecha_baja, p.precio, p.stock, p.stock_minimo, p.unidad_venta_codigo,
            p.contenido_presentacion, p.unidades_por_presentacion, p.unidad_base_codigo,
            p.categoria_id, p.activo, c.nombre AS categoria,
            u.nombre_singular AS unidad_singular, u.nombre_plural AS unidad_plural,
            ub.nombre_singular AS unidad_base_singular, ub.nombre_plural AS unidad_base_plural,
            COALESCE((SELECT SUM(lv.stock_actual) FROM producto_lotes lv
                      WHERE lv.producto_id=p.id AND lv.estado='ACTIVO' AND lv.stock_actual>0
                        AND (lv.fecha_vencimiento IS NULL OR lv.fecha_vencimiento >= CURDATE())), 0) AS stock_vendible,
            (SELECT MIN(le.fecha_vencimiento) FROM producto_lotes le
             WHERE le.producto_id=p.id AND le.estado='ACTIVO' AND le.stock_actual>0
               AND le.fecha_vencimiento IS NOT NULL) AS vencimiento_mas_cercano
     FROM productos p
     JOIN unidades_venta u ON u.codigo = p.unidad_venta_codigo
     JOIN unidades_venta ub ON ub.codigo = p.unidad_base_codigo
     LEFT JOIN categorias c ON c.id = p.categoria_id";
if ($condiciones) $sql .= " WHERE " . implode(" AND ", $condiciones);
$sql .= " ORDER BY COALESCE(c.nombre, 'Sin categoría'), p.nombre";

$stmt = $db->prepare($sql);
$stmt->execute($parametros);
responderJson($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
