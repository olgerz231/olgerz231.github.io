<?php
// Iniciar sesión (si no está activa)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Limpiar datos de sesión (evita fugas de información)
$_SESSION = [];

// 2. Eliminar la cookie de sesión (más robusto)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destruir la sesión
session_destroy();

// 4. Redirigir con HTTP 303 (evita reenvío de formularios)
header("Location: login.php", true, 303);
exit();
?>