<?php
// Configuración de Base de Datos - Hostinger
define('DB_HOST', 'localhost');
define('DB_NAME', 'u482097276_efectivo');
define('DB_USER', 'u482097276_lclorenzo');
define('DB_PASS', 'Saboryaroma90*');

// Zona horaria de Argentina. Se fija explícitamente porque el servidor de hosting
// normalmente corre en UTC: sin esto, un movimiento cargado a las 21:30 quedaría
// asentado como "00:30 del día siguiente".
// Se usa el offset fijo '-03:00' y no 'America/Argentina/Buenos_Aires' porque en
// hosting compartido las tablas de zonas horarias de MySQL suelen no estar cargadas.
// Argentina no aplica horario de verano, así que el offset es estable todo el año.
define('TZ_OFFSET_MYSQL', '-03:00');
define('TZ_PHP', 'America/Argentina/Buenos_Aires');

date_default_timezone_set(TZ_PHP);

// Conexión PDO
function getConnection() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        // MySQL guarda los TIMESTAMP internamente en UTC y los convierte con la zona
        // horaria de la sesión, tanto al escribir como al leer. Fijándola acá, los
        // registros nuevos Y los ya existentes se leen en hora argentina.
        $conn->exec("SET time_zone = '" . TZ_OFFSET_MYSQL . "'");

        return $conn;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]));
    }
}

// Headers para API JSON
function setApiHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    // Los datos de caja cambian constantemente: ninguna respuesta de la API debe
    // quedar cacheada por el navegador, el service worker o un proxy intermedio.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}

// Respuesta JSON
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
