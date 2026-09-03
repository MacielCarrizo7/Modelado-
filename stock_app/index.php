<?php
require "seguridad.php";
require "conexion.php";
require "totp_servicio.php";
iniciarSesionAplicacion();
header("Cache-Control: no-store");

if (autenticacionCompleta()) {
    header("Location: stock.php");
    exit;
}
if (sesionMfaPendienteValida()) {
    header("Location: " . destinoMfaPendiente());
    exit;
}

$conexion = Conexion::obtenerInstancia();
$error = "";
$dni = "";
$nombre = "";
$apellido = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $dni = trim($_POST["dni"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $apellido = trim($_POST["apellido"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($dni === "" || $nombre === "" || $apellido === "" || $password === "") {
        $error = "Los datos ingresados no son correctos.";
    } else {
        // apellido IS NULL permite que una cuenta anterior incorpore el dato
        // solo después de comprobar sus credenciales originales.
        $stmt = $conexion->prepare(
            "SELECT id, dni, nombre, apellido, password, rol, totp_enabled
             FROM usuarios
             WHERE dni = ? AND nombre = ? AND (apellido = ? OR apellido IS NULL)
             LIMIT 1"
        );
        $stmt->bind_param("sss", $dni, $nombre, $apellido);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();

        $passwordValida = $usuario && password_verify($password, $usuario["password"]);

        if ($passwordValida) {
            if ($usuario["apellido"] === null) {
                $actualizar = $conexion->prepare(
                    "UPDATE usuarios SET apellido = ? WHERE id = ? AND apellido IS NULL"
                );
                $actualizar->bind_param("si", $apellido, $usuario["id"]);
                $actualizar->execute();
                $usuario["apellido"] = $apellido;
            }

            try {
                claveCifradoTotp();
                $requiereEnrolamiento = !(bool) $usuario["totp_enabled"];
                iniciarSesionPendiente($usuario, $requiereEnrolamiento);
                header("Location: " . ($requiereEnrolamiento ? "configurar_totp.php" : "verificar_totp.php"));
                exit;
            } catch (RuntimeException $e) {
                error_log("Configuración TOTP no disponible: " . $e->getMessage());
                $error = "El segundo factor no está disponible temporalmente. Contactá al administrador.";
            }
        } else {
            $error = "Los datos ingresados no son correctos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acceso seguro al sistema Control Stock">
    <title>Iniciar sesión | Control Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/estilos.css" rel="stylesheet">
</head>
<body class="login-body">
    <main class="container py-4">
        <div class="login-layout">
            <section class="login-presentacion d-none d-lg-flex" aria-label="Presentación">
                <div>
                    <span class="marca-icono marca-icono-claro mb-4" aria-hidden="true">CS</span>
                    <p class="etiqueta text-white-50">Gestión simple y segura</p>
                    <h1 class="display-5 fw-bold mb-3">Todo tu negocio en un solo lugar.</h1>
                    <p class="lead text-white-50 mb-0">Administrá productos y ventas con una experiencia clara para cada equipo.</p>
                </div>
            </section>

            <section class="login-card" aria-labelledby="titulo-login">
                <div class="d-flex align-items-center gap-2 mb-4 d-lg-none">
                    <span class="marca-icono" aria-hidden="true">CS</span>
                    <span class="fw-bold">Control Stock</span>
                </div>
                <p class="etiqueta text-primary mb-2">Bienvenido</p>
                <h2 id="titulo-login" class="h2 fw-bold mb-2">Iniciar sesión</h2>
                <p class="texto-secundario mb-4">Ingresá tus datos para acceder a tu cuenta.</p>

                <?php if ($error !== ""): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET["sesion_vencida"])): ?>
                    <div class="alert alert-warning" role="status">La verificación venció. Iniciá sesión nuevamente.</div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <div class="mb-3">
                        <label for="dni" class="form-label">DNI</label>
                        <input type="text" id="dni" name="dni" class="form-control" inputmode="numeric" autocomplete="username" maxlength="20" value="<?= htmlspecialchars($dni, ENT_QUOTES, "UTF-8") ?>" required autofocus>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="nombre" class="form-label">Nombre</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" autocomplete="given-name" maxlength="100" value="<?= htmlspecialchars($nombre, ENT_QUOTES, "UTF-8") ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="apellido" class="form-label">Apellido</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" autocomplete="family-name" maxlength="100" value="<?= htmlspecialchars($apellido, ENT_QUOTES, "UTF-8") ?>" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100">Iniciar sesión</button>
                </form>

                <p class="texto-secundario small text-center mt-4 mb-0">Las cuentas son administradas por personal autorizado.</p>
            </section>
        </div>
    </main>
</body>
</html>
