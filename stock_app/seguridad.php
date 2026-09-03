<?php
function iniciarSesionAplicacion(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set("session.use_strict_mode", "1");
        ini_set("session.cookie_httponly", "1");
        ini_set("session.cookie_samesite", "Lax");
        if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
            ini_set("session.cookie_secure", "1");
        }
        session_start();
    }
}

function tokenCsrf(): string {
    iniciarSesionAplicacion();
    if (!isset($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function autenticacionCompleta(): bool {
    iniciarSesionAplicacion();
    return isset($_SESSION["usuario_id"]) && ($_SESSION["mfa_verified"] ?? false) === true;
}

function destinoMfaPendiente(): string {
    return ($_SESSION["mfa_pending_enrollment"] ?? false) ? "configurar_totp.php" : "verificar_totp.php";
}

function requerirPaginaAutenticada(array $roles = []): void {
    iniciarSesionAplicacion();
    if (!autenticacionCompleta()) {
        $destino = isset($_SESSION["auth_pending_user_id"]) ? destinoMfaPendiente() : "login.php";
        header("Location: " . $destino);
        exit;
    }
    if ($roles !== [] && !in_array($_SESSION["usuario_rol"] ?? "", $roles, true)) {
        http_response_code(403);
        echo "No tenés permiso para acceder a esta página.";
        exit;
    }
}

function responderJson(array $datos, int $estado = 200): never {
    http_response_code($estado);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function requerirUsuarioJson(array $roles = []): void {
    iniciarSesionAplicacion();
    if (!autenticacionCompleta()) {
        responderJson(["error" => "No autorizado"], 401);
    }
    if ($roles !== [] && !in_array($_SESSION["usuario_rol"] ?? "", $roles, true)) {
        responderJson(["error" => "No tenés permiso para esta acción"], 403);
    }
}

function requerirCsrfJson(): void {
    $recibido = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? ($_POST["csrf_token"] ?? "");
    $esperado = $_SESSION["csrf_token"] ?? "";
    if ($esperado === "" || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
        responderJson(["error" => "La sesión del formulario venció. Actualizá la página."], 403);
    }
}

function fechaIsoValida(string $fecha): bool {
    $objeto = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
    $errores = DateTimeImmutable::getLastErrors();
    return $objeto !== false && ($errores === false || ($errores["warning_count"] === 0 && $errores["error_count"] === 0))
        && $objeto->format("Y-m-d") === $fecha;
}
