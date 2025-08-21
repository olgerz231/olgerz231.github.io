<?php

/**
 * Archivo de conexión a la base de datos AttendSync
 * Última actualización: [Fecha actual]
 */

// Configuración de la conexión
define('DB_HOST', '127.0.0.1');    // Usar 127.0.0.1 es más confiable que 'localhost'
define('DB_USER', 'root');         // Usuario de la base de datos
define('DB_PASS', '');             // Contraseña (vacía por defecto en XAMPP)
define('DB_NAME', 'attendsync');   // Nombre de la base de datos
define('DB_CHARSET', 'utf8mb4');   // Codificación de caracteres
define('DEBUG_MODE', true);  // Cambiar a false en producción

class Database
{
    private static $instance = null;
    private $connection;

    // Constructor privado para singleton
    private function __construct()
    {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if ($this->connection->connect_error) {
                throw new Exception("Error de conexión: " . $this->connection->connect_error);
            }

            // Establecer el charset
            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            // Registrar el error y mostrar mensaje seguro
            error_log("Error de base de datos: " . $e->getMessage());
            die("Error en el sistema. Por favor intente más tarde.");
        }
    }

    // Método para obtener la instancia única
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    // Método para obtener la conexión
    public function getConnection()
    {
        return $this->connection;
    }

    // Evitar la clonación del objeto
    private function __clone() {}
}

// Función para obtener la conexión
function getDBConnection()
{
    return Database::getInstance()->getConnection();
}

// Prueba de conexión automática (opcional para desarrollo)
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    try {
        $testConn = getDBConnection();
        if ($testConn->ping()) {
            error_log("Conexión a la base de datos establecida correctamente");
        }
    } catch (Exception $e) {
        error_log("Error en prueba de conexión: " . $e->getMessage());
    }
}
?>