<?php
//Patron singleton
class Conexion {
    private static ?Conexion $instancia = null;
    private mysqli $mysqli;

    private function __construct() {
        $host = "127.0.0.1";
        $puertoEntorno = getenv("CONTROL_STOCK_DB_PORT");
        $puerto = is_string($puertoEntorno) && ctype_digit($puertoEntorno) ? (int) $puertoEntorno : 3306;
        $usuario = "root";
        $clave = "";
        $basedatos = "control_stock";

        $this->mysqli = new mysqli($host, $usuario, $clave, $basedatos, $puerto);

        if ($this->mysqli->connect_error) {
            die("Error de conexion: " . $this->mysqli->connect_error);
        }

        $this->mysqli->set_charset("utf8mb4");
    }

    public static function obtenerInstancia(): mysqli {
        if (self::$instancia === null) {
            self::$instancia = new Conexion();
        }
        return self::$instancia->mysqli;
    }
}
?>
