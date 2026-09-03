<?php
declare(strict_types=1);

require __DIR__ . "/../conexion.php";

function verificar(bool $condicion, string $mensaje): void {
    if (!$condicion) {
        throw new RuntimeException("FALLÓ: {$mensaje}");
    }
    echo "OK: {$mensaje}\n";
}

$archivos = glob(__DIR__ . "/../backups/catalogo_antes_*.json") ?: [];
rsort($archivos, SORT_STRING);
verificar(isset($archivos[0]), "existe el respaldo lógico previo");
$respaldo = json_decode((string) file_get_contents($archivos[0]), true, 512, JSON_THROW_ON_ERROR);
$db = Conexion::obtenerInstancia();

$usuariosActuales = $db->query(
    "SELECT id, dni, nombre, apellido, rol, totp_enabled, totp_confirmed_at,
            SHA2(CONCAT_WS('|', id, dni, nombre, apellido, rol, password,
                COALESCE(totp_secret_encrypted, ''), totp_enabled,
                COALESCE(totp_confirmed_at, '')), 256) AS auth_core_hash
     FROM usuarios ORDER BY id"
)->fetch_all(MYSQLI_ASSOC);
$identidadesAntes = array_map(
    static fn(array $u): array => array_intersect_key($u, array_flip(["id", "dni", "nombre", "apellido", "rol", "totp_enabled", "totp_confirmed_at"])),
    $respaldo["integridad_usuarios"]
);
$identidadesAhora = array_map(
    static fn(array $u): array => array_intersect_key($u, array_flip(["id", "dni", "nombre", "apellido", "rol", "totp_enabled", "totp_confirmed_at"])),
    $usuariosActuales
);
$huellasCorePrevias = [
    "8" => "b2fb35cbbee82a372db4b01939582c433b35b5540c79899f7aca0395e0417318",
    "9" => "8a30c7631d27fc2f8b1c2ce0400f12af631e2d00529a891ce0e7bb9c1521eca7",
    "10" => "e1894759a7711f78ed26ac38d010450ff3212edc404a551742bf9ddb3e764c5f",
    "11" => "ab78fec873d5fcfdfe70356d62c242d35876bbe66fd611a6a71e0bdc8eb15f93",
];
$huellasCoreActuales = [];
foreach ($usuariosActuales as $usuario) {
    $huellasCoreActuales[(string) $usuario["id"]] = $usuario["auth_core_hash"];
}
verificar($identidadesAhora === $identidadesAntes, "IDs, identidades, roles y estado TOTP permanecen sin cambios");
verificar($huellasCoreActuales === $huellasCorePrevias, "contraseñas y secretos TOTP cifrados conservan sus huellas previas");

$categoriasEsperadas = ["Alfajores", "Bebidas", "Chocolates", "Galletitas", "Golosinas", "Snacks"];
$categorias = array_column($db->query(
    "SELECT nombre FROM categorias WHERE activo = 1 ORDER BY nombre"
)->fetch_all(MYSQLI_ASSOC), "nombre");
verificar($categorias === $categoriasEsperadas, "las seis categorías activas son exactas y no están duplicadas");

$esperados = [
    "ALF-JORG-CHO-24" => ["Jorgito Chocolate", "Alfajores", "18000.00", "20", "CAJA", "24 unidades"],
    "ALF-GUAY-TRI-24" => ["Guaymallén Triple Chocolate", "Alfajores", "14400.00", "18", "CAJA", "24 unidades"],
    "GOL-PICO-DUL-50" => ["Chupetín Pico Dulce", "Golosinas", "9500.00", "25", "BOLSA", "50 unidades"],
    "GOL-MAST-SUR-800" => ["Caramelos Masticables Surtidos", "Golosinas", "7500.00", "30", "BOLSA", "800 g"],
    "GOL-DURO-FRU-1K" => ["Caramelos Duros Frutales", "Golosinas", "8200.00", "22", "BOLSA", "1 kg"],
    "CHO-LECH-100-20" => ["Chocolate con Leche 100 g", "Chocolates", "32000.00", "15", "CAJA", "20 unidades"],
    "GAL-CHOCOLINAS" => ["Galletitas Chocolinas", "Galletitas", "24000.00", "15", "CAJA", "Paquetes"],
    "GAL-OREO" => ["Galletitas Oreo", "Galletitas", "26000.00", "15", "CAJA", null],
    "SNA-PAPAS-CLA-20" => ["Papas fritas clásicas", "Snacks", "30000.00", "20", "CAJA", "20 paquetes"],
    "SNA-PALITOS" => ["Palitos salados", "Snacks", "25000.00", "15", "BULTO", null],
    "BEB-COCA-500-12" => ["Coca-Cola 500 ml", "Bebidas", "18000.00", "20", "PACK", "12 botellas"],
    "BEB-AGUA-500-12" => ["Agua mineral 500 ml", "Bebidas", "8500.00", "25", "PACK", "12 botellas"],
];
$filas = $db->query(
    "SELECT p.codigo, p.nombre, c.nombre categoria, p.precio, p.stock,
            p.unidad_venta_codigo, p.contenido_presentacion
     FROM productos p JOIN categorias c ON c.id = p.categoria_id
     WHERE p.activo = 1 ORDER BY p.codigo"
)->fetch_all(MYSQLI_ASSOC);
$actuales = [];
foreach ($filas as $fila) {
    $actuales[$fila["codigo"]] = array_map(
        static fn(mixed $valor): ?string => $valor === null ? null : (string) $valor,
        [$fila["nombre"], $fila["categoria"], $fila["precio"], $fila["stock"], $fila["unidad_venta_codigo"], $fila["contenido_presentacion"]]
    );
}
$catalogoExacto = count($actuales) === 12;
foreach ($esperados as $codigo => $esperado) {
    $coincide = isset($actuales[$codigo]) && $actuales[$codigo] === $esperado;
    if (!$coincide) {
        echo "DIFERENCIA {$codigo}: " . json_encode($actuales[$codigo] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $catalogoExacto = $catalogoExacto && $coincide;
}
verificar($catalogoExacto, "los 12 productos activos coinciden con el catálogo distribuidor");

$historicos = $db->query(
    "SELECT p.id, p.nombre, p.activo, COUNT(v.id) ventas
     FROM productos p LEFT JOIN ventas v ON v.producto_id = p.id
     WHERE p.id IN (1, 3) GROUP BY p.id, p.nombre, p.activo ORDER BY p.id"
)->fetch_all(MYSQLI_ASSOC);
verificar(
    count($historicos) === 2
    && $historicos[0]["activo"] === "0" && $historicos[0]["ventas"] === "3"
    && $historicos[1]["activo"] === "0" && $historicos[1]["ventas"] === "2",
    "los planes demo están inactivos y conservan sus cinco ventas"
);

verificar(count($respaldo["tablas"]["ventas"]) === (int) $db->query("SELECT COUNT(*) total FROM ventas")->fetch_assoc()["total"], "las ventas previas continúan completas");
echo "Verificación del catálogo completada correctamente.\n";
