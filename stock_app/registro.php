<?php
require "seguridad.php";
requerirPaginaAutenticada(["admin"]);
require "conexion.php";
$conexion = Conexion::obtenerInstancia();
$error = "";
$rolesValidos = ["admin", "vendedor", "cliente"];

if (!isset($_SESSION["csrf_crear_usuario"])) {
    $_SESSION["csrf_crear_usuario"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $dni = trim($_POST["dni"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $apellido = trim($_POST["apellido"] ?? "");
    $password = $_POST["password"] ?? "";
    $rol = $_POST["rol"] ?? "";

    if (!hash_equals($_SESSION["csrf_crear_usuario"], $token)) {
        http_response_code(400);
        $error = "La sesión del formulario venció. Actualizá la página e intentá nuevamente.";
    } elseif ($dni === "" || $nombre === "" || $apellido === "" || $password === "") {
        $error = "Completá todos los campos obligatorios.";
    } elseif (mb_strlen($dni) > 20 || mb_strlen($nombre) > 100 || mb_strlen($apellido) > 100) {
        $error = "Uno de los datos supera la longitud permitida.";
    } elseif (!in_array($rol, $rolesValidos, true)) {
        $error = "Seleccioná un rol válido.";
    } else {
        $check = $conexion->prepare("SELECT id FROM usuarios WHERE dni = ? LIMIT 1");
        $check->bind_param("s", $dni);
        $check->execute();

        if ($check->get_result()->fetch_assoc()) {
            $error = "Ya existe una cuenta con ese DNI.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conexion->prepare(
                "INSERT INTO usuarios (dni, nombre, apellido, password, rol) VALUES (?, ?, ?, ?, ?)"
            );
            $insert->bind_param("sssss", $dni, $nombre, $apellido, $hash, $rol);
            $insert->execute();

            $_SESSION["csrf_crear_usuario"] = bin2hex(random_bytes(32));
            header("Location: registro.php?creado=1");
            exit;
        }
    }
}

$resultadoUsuarios = $conexion->query(
    "SELECT id, dni, nombre, apellido, rol, fecha_registro, totp_enabled, totp_secret_encrypted
     FROM usuarios ORDER BY fecha_registro DESC, id DESC"
);
$usuarios = $resultadoUsuarios->fetch_all(MYSQLI_ASSOC);
$personal = array_values(array_filter($usuarios, fn($u) => in_array($u["rol"], ["admin", "vendedor"], true)));
$clientes = array_values(array_filter($usuarios, fn($u) => $u["rol"] === "cliente"));

function e(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | Control Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/estilos.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar app-navbar sticky-top py-3">
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="admin.php">
                <span class="marca-icono" aria-hidden="true">CS</span>
                <span class="fw-bold">Control Stock</span>
            </a>
            <div class="d-flex gap-2">
                <a href="admin.php" class="btn btn-outline-secondary btn-sm">Volver al panel</a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Cerrar sesión</a>
            </div>
        </div>
    </nav>

    <main class="container py-4 py-md-5">
        <div class="mb-4">
            <p class="etiqueta text-primary mb-1">Administración</p>
            <h1 class="h2 fw-bold mb-2">Gestión de usuarios</h1>
            <p class="texto-secundario mb-0">Creá cuentas y asigná el nivel de acceso correspondiente.</p>
        </div>

        <?php if (isset($_GET["creado"])): ?>
            <div class="alert alert-success" role="status">El usuario fue creado correctamente.</div>
        <?php endif; ?>
        <?php if (isset($_GET["totp_restablecido"])): ?>
            <div class="alert alert-success" role="status">El segundo factor fue restablecido. El usuario deberá configurarlo en su próximo acceso.</div>
        <?php endif; ?>
        <?php if ($error !== ""): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-12 col-lg-5">
                <section class="seccion-card" aria-labelledby="titulo-crear-usuario">
                    <h2 id="titulo-crear-usuario" class="h5 fw-bold mb-1">Crear usuario</h2>
                    <p class="texto-secundario small mb-4">Todos los campos son obligatorios.</p>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_crear_usuario"]) ?>">
                        <div class="mb-3">
                            <label for="dni" class="form-label">DNI</label>
                            <input type="text" id="dni" name="dni" class="form-control" inputmode="numeric" maxlength="20" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label for="nombre" class="form-label">Nombre</label>
                                <input type="text" id="nombre" name="nombre" class="form-control" maxlength="100" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="apellido" class="form-label">Apellido</label>
                                <input type="text" id="apellido" name="apellido" class="form-control" maxlength="100" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required>
                        </div>
                        <div class="mb-4">
                            <label for="rol" class="form-label">Rol</label>
                            <select id="rol" name="rol" class="form-select" required>
                                <option value="cliente">Cliente</option>
                                <option value="vendedor">Vendedor</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">+ Crear usuario</button>
                    </form>
                </section>
            </div>

            <div class="col-12 col-lg-7">
                <section class="seccion-card mb-4" aria-labelledby="titulo-personal">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-3">
                        <div><p class="etiqueta text-primary mb-1">Equipo interno</p><h2 id="titulo-personal" class="h5 fw-bold mb-0">Personal del sistema <span class="badge text-bg-light border"><?= count($personal) ?></span></h2></div>
                        <select id="filtroPersonal" class="form-select form-select-sm" style="max-width: 190px"><option value="">Todos</option><option value="admin">Administradores</option><option value="vendedor">Vendedores</option></select>
                    </div>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>DNI</th><th>Nombre</th><th>Apellido</th><th>Rol</th><th>2FA</th><th></th></tr></thead><tbody id="personalBody">
                    <?php foreach ($personal as $usuario): ?>
                        <tr data-rol="<?= e($usuario["rol"]) ?>"><td><?= e($usuario["dni"]) ?></td><td class="fw-semibold"><?= e($usuario["nombre"]) ?></td><td><?= e($usuario["apellido"] ?? "Pendiente") ?></td><td><span class="badge text-bg-primary"><?= $usuario["rol"] === "admin" ? "Administrador" : "Vendedor" ?></span></td><td><span class="badge <?= (bool) $usuario["totp_enabled"] ? "text-bg-success" : "text-bg-secondary" ?>"><?= (bool) $usuario["totp_enabled"] ? "Activado" : "Sin configurar" ?></span></td><td class="text-end"><?php if ((bool) $usuario["totp_enabled"] || !empty($usuario["totp_secret_encrypted"])): ?><form method="POST" action="restablecer_totp.php" onsubmit="return confirm('¿Restablecer el autenticador?');"><input type="hidden" name="csrf_token" value="<?= e(tokenCsrf()) ?>"><input type="hidden" name="usuario_id" value="<?= (int) $usuario["id"] ?>"><button class="btn btn-outline-danger btn-sm">Restablecer 2FA</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <section class="seccion-card" aria-labelledby="titulo-clientes">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-3"><div><p class="etiqueta text-primary mb-1">Comercios</p><h2 id="titulo-clientes" class="h5 fw-bold mb-0">Clientes <span class="badge text-bg-light border"><?= count($clientes) ?></span></h2></div><input id="buscarCliente" class="form-control form-control-sm" style="max-width: 260px" placeholder="Buscar DNI, nombre o apellido"></div>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>DNI</th><th>Nombre</th><th>Apellido</th><th>2FA</th><th></th></tr></thead><tbody id="clientesBody">
                    <?php foreach ($clientes as $usuario): $busqueda = mb_strtolower($usuario["dni"] . " " . $usuario["nombre"] . " " . ($usuario["apellido"] ?? "")); ?>
                        <tr data-busqueda="<?= e($busqueda) ?>"><td><?= e($usuario["dni"]) ?></td><td class="fw-semibold"><?= e($usuario["nombre"]) ?></td><td><?= e($usuario["apellido"] ?? "Pendiente") ?></td><td><span class="badge <?= (bool) $usuario["totp_enabled"] ? "text-bg-success" : "text-bg-secondary" ?>"><?= (bool) $usuario["totp_enabled"] ? "Activado" : "Sin configurar" ?></span></td><td class="text-end"><?php if ((bool) $usuario["totp_enabled"] || !empty($usuario["totp_secret_encrypted"])): ?><form method="POST" action="restablecer_totp.php" onsubmit="return confirm('¿Restablecer el autenticador?');"><input type="hidden" name="csrf_token" value="<?= e(tokenCsrf()) ?>"><input type="hidden" name="usuario_id" value="<?= (int) $usuario["id"] ?>"><button class="btn btn-outline-danger btn-sm">Restablecer 2FA</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </section>
            </div>
        </div>
    </main>
    <script>
        document.getElementById("filtroPersonal").addEventListener("change", (e) => {
            document.querySelectorAll("#personalBody tr").forEach((fila) => fila.hidden = e.target.value !== "" && fila.dataset.rol !== e.target.value);
        });
        document.getElementById("buscarCliente").addEventListener("input", (e) => {
            const termino = e.target.value.trim().toLocaleLowerCase("es");
            document.querySelectorAll("#clientesBody tr").forEach((fila) => fila.hidden = !fila.dataset.busqueda.includes(termino));
        });
    </script>
</body>
</html>
