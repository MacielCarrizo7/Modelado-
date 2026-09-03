<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin"]);
requerirCsrfJson();

$db = Conexion::obtenerInstancia();
$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT) ?: 0;
$ean13 = normalizarEan13($_POST["ean13"] ?? null);
$nombre = trim($_POST["nombre"] ?? "");
$marca = trim($_POST["marca"] ?? "");
$descripcion = trim($_POST["descripcion"] ?? "");
$precioTexto = trim($_POST["precio"] ?? "");
$stockMinimoTexto = trim($_POST["stock_minimo"] ?? "0");
$unidad = strtoupper(trim($_POST["unidad_venta_codigo"] ?? ""));
$unidadBase = strtoupper(trim($_POST["unidad_base_codigo"] ?? "UNIDAD"));
$unidadesTexto = trim($_POST["unidades_por_presentacion"] ?? "1");
$categoriaId = filter_input(INPUT_POST, "categoria_id", FILTER_VALIDATE_INT) ?: 0;

if ($ean13 !== null && !ean13Valido($ean13)) {
    responderJson(["error" => "Ingresá un EAN-13 válido, incluyendo su dígito verificador."], 400);
}
if ($id <= 0 || $nombre === "" || mb_strlen($nombre) > 150 || mb_strlen($marca) > 100
    || mb_strlen($descripcion) > 3000 || !decimalValido($precioTexto, 10)
    || decimalAEntero($precioTexto) <= 0 || !ctype_digit($stockMinimoTexto)
    || !ctype_digit($unidadesTexto) || (int) $unidadesTexto < 1 || $categoriaId <= 0) {
    responderJson(["error" => "Revisá los datos del producto."], 400);
}

$precio = decimalNormalizado($precioTexto);
$stockMinimo = (int) $stockMinimoTexto;
$unidades = (int) $unidadesTexto;
$marca = $marca !== "" ? $marca : null;
$descripcion = $descripcion !== "" ? $descripcion : null;

try {
    $db->begin_transaction();
    $actualStmt = $db->prepare(
        "SELECT nombre, ean13, marca, descripcion, precio, stock, stock_minimo,
                unidad_venta_codigo, unidades_por_presentacion, unidad_base_codigo, categoria_id
         FROM productos WHERE id = ? FOR UPDATE"
    );
    $actualStmt->execute([$id]);
    $actual = $actualStmt->get_result()->fetch_assoc();
    if (!$actual) throw new DomainException("Producto no encontrado.");

    $unidadStmt = $db->prepare(
        "SELECT codigo, nombre_singular, nombre_plural FROM unidades_venta
         WHERE codigo IN (?, ?) AND activo = 1 FOR UPDATE"
    );
    $unidadStmt->execute([$unidad, $unidadBase]);
    $unidadesDisponibles = [];
    foreach ($unidadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) $unidadesDisponibles[$fila["codigo"]] = $fila;
    if (!isset($unidadesDisponibles[$unidad], $unidadesDisponibles[$unidadBase])) {
        throw new DomainException("Seleccioná unidades de venta y base válidas.");
    }
    if ((int) $actual["stock"] > 0 && $actual["unidad_venta_codigo"] !== $unidad) {
        throw new DomainException("No se puede cambiar la presentación mientras exista stock. Ajustá o agotá los lotes primero.");
    }
    $categoria = $db->prepare("SELECT id FROM categorias WHERE id = ? AND activo = 1 FOR UPDATE");
    $categoria->execute([$categoriaId]);
    if (!$categoria->get_result()->fetch_assoc()) throw new DomainException("Seleccioná una categoría válida.");

    $contenido = $unidades . " " . ($unidades === 1
        ? $unidadesDisponibles[$unidadBase]["nombre_singular"]
        : $unidadesDisponibles[$unidadBase]["nombre_plural"]);
    $actualizar = $db->prepare(
        "UPDATE productos SET ean13 = ?, nombre = ?, marca = ?, descripcion = ?, categoria_id = ?,
                precio = ?, stock_minimo = ?, unidad_venta_codigo = ?, contenido_presentacion = ?,
                unidades_por_presentacion = ?, unidad_base_codigo = ?
         WHERE id = ?"
    );
    $actualizar->execute([
        $ean13, $nombre, $marca, $descripcion, $categoriaId, $precio, $stockMinimo,
        $unidad, $contenido, $unidades, $unidadBase, $id,
    ]);
    registrarHistorialProducto(
        $db, $id, (int) $_SESSION["usuario_id"], "PRODUCTO_MODIFICADO",
        json_encode(["anterior" => $actual, "nuevo" => ["ean13" => $ean13, "nombre" => $nombre, "marca" => $marca, "precio" => $precio]], JSON_UNESCAPED_UNICODE)
    );
    $db->commit();
    responderJson(["success" => true]);
} catch (DomainException $e) {
    $db->rollback();
    responderJson(["error" => $e->getMessage()], 400);
} catch (mysqli_sql_exception $e) {
    $db->rollback();
    if ((int) $e->getCode() === 1062) responderJson(["error" => "Ya existe un producto registrado con este EAN-13."], 409);
    error_log("Modificación de producto: " . $e->getMessage());
    responderJson(["error" => "No se pudo modificar el producto."], 500);
} catch (Throwable $e) {
    $db->rollback();
    error_log("Modificación de producto: " . $e->getMessage());
    responderJson(["error" => "No se pudo modificar el producto."], 500);
}
