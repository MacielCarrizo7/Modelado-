<?php
require "seguridad.php";
require "conexion.php";
require "comercial.php";
require "producto_servicio.php";
requerirUsuarioJson(["admin"]);
requerirCsrfJson();

$db = Conexion::obtenerInstancia();
$ean13 = normalizarEan13($_POST["ean13"] ?? null);
$nombre = trim($_POST["nombre"] ?? "");
$marca = trim($_POST["marca"] ?? "");
$descripcion = trim($_POST["descripcion"] ?? "");
$precioTexto = trim($_POST["precio"] ?? "");
$stockTexto = trim($_POST["stock"] ?? "0");
$stockMinimoTexto = trim($_POST["stock_minimo"] ?? "0");
$unidad = strtoupper(trim($_POST["unidad_venta_codigo"] ?? "UNIDAD"));
$unidadBase = strtoupper(trim($_POST["unidad_base_codigo"] ?? "UNIDAD"));
$unidadesTexto = trim($_POST["unidades_por_presentacion"] ?? "1");
$categoriaId = filter_input(INPUT_POST, "categoria_id", FILTER_VALIDATE_INT) ?: 0;
$numeroLote = trim($_POST["numero_lote"] ?? "");
$fechaIngreso = trim($_POST["fecha_ingreso"] ?? "");
$fechaVencimiento = trim($_POST["fecha_vencimiento"] ?? "");

if ($ean13 === null || !ean13Valido($ean13)) {
    responderJson(["error" => "Ingresá un EAN-13 válido, incluyendo su dígito verificador."], 400);
}
if ($nombre === "" || mb_strlen($nombre) > 150 || $marca === "" || mb_strlen($marca) > 100
    || mb_strlen($descripcion) > 3000 || !decimalValido($precioTexto, 10)
    || decimalAEntero($precioTexto) <= 0 || !ctype_digit($stockTexto)
    || !ctype_digit($stockMinimoTexto) || !ctype_digit($unidadesTexto)
    || (int) $unidadesTexto < 1 || $categoriaId <= 0 || mb_strlen($numeroLote) > 80
    || !fechaIsoOpcionalValida($fechaIngreso) || !fechaIsoOpcionalValida($fechaVencimiento)) {
    responderJson(["error" => "Revisá los datos del producto y del lote inicial."], 400);
}

$precio = decimalNormalizado($precioTexto);
$stock = (int) $stockTexto;
$stockMinimo = (int) $stockMinimoTexto;
$unidades = (int) $unidadesTexto;
$numeroLote = $numeroLote !== "" ? $numeroLote : null;
$fechaVencimiento = $fechaVencimiento !== "" ? $fechaVencimiento : null;
$descripcion = $descripcion !== "" ? $descripcion : null;

try {
    $db->begin_transaction();
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
    $categoria = $db->prepare("SELECT id FROM categorias WHERE id = ? AND activo = 1 FOR UPDATE");
    $categoria->execute([$categoriaId]);
    if (!$categoria->get_result()->fetch_assoc()) throw new DomainException("Seleccioná una categoría válida.");

    $contenido = $unidades . " " . ($unidades === 1
        ? $unidadesDisponibles[$unidadBase]["nombre_singular"]
        : $unidadesDisponibles[$unidadBase]["nombre_plural"]);
    $insertar = $db->prepare(
        "INSERT INTO productos
         (ean13, nombre, marca, descripcion, fecha_alta, categoria_id, precio, stock,
          stock_minimo, unidad_venta_codigo, contenido_presentacion,
          unidades_por_presentacion, unidad_base_codigo, activo)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $insertar->execute([
        $ean13, $nombre, $marca, $descripcion, $categoriaId, $precio, $stock,
        $stockMinimo, $unidad, $contenido, $unidades, $unidadBase,
    ]);
    $productoId = (int) $db->insert_id;
    $usuarioId = (int) $_SESSION["usuario_id"];
    registrarHistorialProducto($db, $productoId, $usuarioId, "PRODUCTO_CREADO", "Alta comercial del producto.");

    if ($stock > 0) {
        $ingresoSql = $fechaIngreso !== "" ? $fechaIngreso . " 00:00:00" : date("Y-m-d H:i:s");
        $lote = $db->prepare(
            "INSERT INTO producto_lotes
             (producto_id, numero_lote, cantidad_inicial, stock_actual, fecha_ingreso,
              fecha_vencimiento, estado, origen, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, 'ACTIVO', 'ALTA_INICIAL', ?)"
        );
        $lote->execute([$productoId, $numeroLote, $stock, $stock, $ingresoSql, $fechaVencimiento, $usuarioId]);
        $loteId = (int) $db->insert_id;
        registrarMovimientoStock(
            $db, $productoId, $nombre, $usuarioId, null, "ALTA_INICIAL", $stock,
            0, $stock, $unidadesDisponibles[$unidad]["nombre_singular"], "Stock del lote inicial", $loteId
        );
    }
    $db->commit();
    responderJson(["success" => true, "producto_id" => $productoId], 201);
} catch (DomainException $e) {
    $db->rollback();
    responderJson(["error" => $e->getMessage()], 400);
} catch (mysqli_sql_exception $e) {
    $db->rollback();
    if ((int) $e->getCode() === 1062) {
        responderJson(["error" => "Ya existe un producto registrado con este EAN-13."], 409);
    }
    error_log("Alta de producto: " . $e->getMessage());
    responderJson(["error" => "No se pudo guardar el producto."], 500);
} catch (Throwable $e) {
    $db->rollback();
    error_log("Alta de producto: " . $e->getMessage());
    responderJson(["error" => "No se pudo guardar el producto."], 500);
}
