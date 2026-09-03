<?php
require "seguridad.php";
require "conexion.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin", "vendedor"]);

$ean13 = normalizarEan13($_GET["ean13"] ?? null);
if ($ean13 === null || !ean13Valido($ean13)) responderJson(["error" => "El código leído no es un EAN-13 válido."], 400);
$db = Conexion::obtenerInstancia();
$sql =
    "SELECT p.id, p.ean13, p.nombre, p.marca, p.precio, p.stock, p.activo,
            p.unidades_por_presentacion, p.contenido_presentacion,
            u.nombre_singular AS unidad_singular, u.nombre_plural AS unidad_plural,
            COALESCE((SELECT SUM(l.stock_actual) FROM producto_lotes l
                      WHERE l.producto_id=p.id AND l.estado='ACTIVO' AND l.stock_actual>0
                        AND (l.fecha_vencimiento IS NULL OR l.fecha_vencimiento >= CURDATE())), 0) AS stock_vendible
     FROM productos p JOIN unidades_venta u ON u.codigo=p.unidad_venta_codigo
     WHERE p.ean13 = ?";
if ($_SESSION["usuario_rol"] !== "admin") $sql .= " AND p.activo = 1";
$stmt = $db->prepare($sql);
$stmt->execute([$ean13]);
$producto = $stmt->get_result()->fetch_assoc();
if (!$producto) responderJson(["error" => "No se encontró un producto con este EAN-13."], 404);
responderJson($producto);
