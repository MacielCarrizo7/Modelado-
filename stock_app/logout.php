<?php
require "seguridad.php";
iniciarSesionAplicacion();
session_unset();
session_destroy();

if (ini_get("session.use_cookies")) {
    $parametros = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $parametros["path"], $parametros["domain"], $parametros["secure"], $parametros["httponly"]);
}

header("Location: login.php");
exit;
?>
