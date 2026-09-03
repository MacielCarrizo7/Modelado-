<?php
require "seguridad.php";
requerirPaginaAutenticada(["admin"]);
require "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit;
}

$token = $_POST["csrf_token"] ?? "";
$usuarioId = filter_input(INPUT_POST, "usuario_id", FILTER_VALIDATE_INT);
if (!hash_equals($_SESSION["csrf_token"] ?? "", is_string($token) ? $token : "")) {
    http_response_code(403);
    echo "La sesión del formulario venció.";
    exit;
}
if (!$usuarioId || $usuarioId < 1) {
    http_response_code(400);
    echo "Usuario inválido.";
    exit;
}

$conexion = Conexion::obtenerInstancia();
$stmt = $conexion->prepare(
    "UPDATE usuarios
     SET totp_secret_encrypted = NULL,
         totp_enabled = 0,
         totp_confirmed_at = NULL,
         totp_last_timeslice = NULL
     WHERE id = ?"
);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();

header("Location: registro.php?totp_restablecido=1");
exit;
