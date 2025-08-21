<?php
session_start();

// Habilitar mensajes de error para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Función para limpiar datos de entrada
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Incluir el archivo de conexión
require_once 'conexion.php';

// Redirigir si ya hay sesión activa (MODIFICADO)
if (isset($_SESSION['id']) && isset($_SESSION['rol'])) {
    $rol = strtolower($_SESSION['rol']);
    $redirectPage = ($rol == 'administrador') ? 'panel_administrador.php' : "panel_$rol.php";
    
    // Redirección segura
    $base_url = (isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
    $base_url = rtrim($base_url, '/\\');
    header("Location: $base_url/$redirectPage");
    exit();
}

// Obtener conexión a la base de datos
$db = getDBConnection();

// Procesar formulario de registro (CÓDIGO CORREGIDO)
if (isset($_POST['registrarse'])) {
    $nombre = cleanInput($_POST['nombre']);
    $email = cleanInput($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validaciones
    if (empty($nombre) || empty($email) || empty($password) || empty($confirm_password)) {
        $error_registro = "Todos los campos son obligatorios.";
    } elseif ($password !== $confirm_password) {
        $error_registro = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 6) {
        $error_registro = "La contraseña debe tener al menos 6 caracteres.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_registro = "El formato del email no es válido.";
    } else {
        try {
            // Verificar si el email ya existe
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($resultado->num_rows > 0) {
                $error_registro = "El email ya está registrado.";
            } else {
                // Encriptar contraseña
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // SOLUCIÓN 1: Si tu tabla tiene campo 'usuario', incluirlo
                // Verificar estructura de la tabla primero
                $check_columns = $db->query("SHOW COLUMNS FROM usuarios LIKE 'usuario'");
                
                if ($check_columns->num_rows > 0) {
                    // La tabla tiene campo 'usuario' - incluirlo en el INSERT
                    $stmt = $db->prepare("INSERT INTO usuarios (nombres, email, password, rol, usuario) VALUES (?, ?, ?, 'administrador', ?)");
                    $stmt->bind_param("ssss", $nombre, $email, $password_hash, $email);
                } else {
                    // La tabla NO tiene campo 'usuario' - usar solo los campos básicos
                    $stmt = $db->prepare("INSERT INTO usuarios (nombres, email, password, rol) VALUES (?, ?, ?, 'administrador')");
                    $stmt->bind_param("sss", $nombre, $email, $password_hash);
                }
                
                if ($stmt->execute()) {
                    $error_registro = "success:Administrador registrado exitosamente. Ahora puede iniciar sesión.";
                } else {
                    // Mostrar error específico para diagnóstico
                    $error_registro = "Error al registrar el usuario: " . $db->error;
                }
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_registro = "Error del sistema: " . $e->getMessage();
            error_log($e->getMessage());
        }
    }
}

// Procesar formulario de inicio de sesión (CÓDIGO MEJORADO)
if (isset($_POST['iniciar_sesion'])) {
    $email = cleanInput($_POST['email']);
    $password = $_POST['password'];
    $rol_seleccionado = strtolower($_POST['rol']);
    
    if (empty($email) || empty($password) || empty($rol_seleccionado)) {
        $error_login = "Todos los campos son obligatorios.";
    } else {
        try {
            // Verificar estructura de la tabla para incluir campo 'usuario' si existe
            $check_columns = $db->query("SHOW COLUMNS FROM usuarios LIKE 'usuario'");
            
            if ($check_columns->num_rows > 0) {
                $stmt = $db->prepare("SELECT id, nombres, password, rol, usuario FROM usuarios WHERE email = ?");
            } else {
                $stmt = $db->prepare("SELECT id, nombres, password, rol FROM usuarios WHERE email = ?");
            }
            
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($resultado->num_rows == 1) {
                $fila = $resultado->fetch_assoc();
                
                if (password_verify($password, $fila['password'])) {
                    if (strtolower($fila['rol']) == $rol_seleccionado) {
                        session_regenerate_id(true);
                        
                        // ESTABLECER LAS VARIABLES DE SESIÓN QUE panel_profesor.php ESPERA
                        $_SESSION['profesor_id'] = $fila['id'];  // <-- CLAVE PARA EVITAR EL BUCLE
                        $_SESSION['id'] = $fila['id'];  // También establecer id general
                        $_SESSION['rol'] = $fila['rol'];
                        $_SESSION['email'] = $email;  // Cambiar 'usuario' por 'email'
                        $_SESSION['nombres'] = $fila['nombres'];
                        
                        // Si existe el campo 'usuario', agregarlo a la sesión
                        if (isset($fila['usuario'])) {
                            $_SESSION['usuario'] = $fila['usuario'];
                        } else {
                            $_SESSION['usuario'] = $email; // Usar email como fallback
                        }
                        
                        // Redirigir según rol
                        $redirectPage = ($rol_seleccionado == 'administrador') ? 'panel_administrador.php' : "panel_$rol_seleccionado.php";
                        header("Location: $redirectPage");
                        exit();
                    } else {
                        $error_login = "Tu rol real es {$fila['rol']} (no $rol_seleccionado)";
                    }
                } else {
                    $error_login = "Contraseña incorrecta";
                }
            } else {
                $error_login = "Email no registrado";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_login = "Error del sistema: " . $e->getMessage();
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login AttendSync</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Montserrat", sans-serif;
        }
        body {
            width: 100%;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f0f4f3;
        }
        .container {
            width: 800px;
            height: 500px;
            display: flex;
            position: relative;
            background-color: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }
        .container-form {
            width: 100%;
            overflow: hidden;
        }
        .container-form form {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transition: transform 0.5s ease-in;
        } 
        .container-form h2 {
            font-size: 30px;
            margin-bottom: 20px;
        }
        .container-form span {
            font-size: 12px;
            margin-bottom: 15px;
        }
        .container-input {
            width: 300px;
            height: 40px;
            margin-bottom: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 0 15px;
            background-color: #EEEEEE;
            border-radius: 5px;
            position: relative;
        }
        .container-input input, 
        .container-input select {
            border: none;
            outline: none;
            width: 100%;
            height: 100%;
            background-color: inherit;
        }
        .container-form a {
            color: black;
            font-size: 14px;
            margin-bottom: 20px;
            margin-top: 5px;
            text-decoration: none;
        }
        .button {
            width: 170px;
            height: 45px;
            font-size: 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
            background-color: #3ab397;
            color: white;
            transition: background-color 0.3s;
        }
        .button:hover {
            background-color: #2d8a7a;
        }
        /* Animación formulario */
        .sign-up {
            transform: translateX(-100%);
        }
        .container.toggle .sign-in {
            transform: translateX(100%);
        }
        .container.toggle .sign-up {
            transform: translateX(0);
        }
        /* Welcome */
        .container-welcome {
            position: absolute;
            width: 50%;
            height: 100%;
            display: flex;
            align-items: center;
            transform: translateX(100%);
            background-color: #3ab397;
            transition: transform 0.5s ease-in-out, border-radius 0.5s ease-in-out;
            overflow: hidden;
            border-radius: 50% 0 0 50%;
        }
        .container.toggle .container-welcome {
            transform: translateX(0);
            border-radius: 0 50% 50% 0;
            background-color: #3aa8ad;
        }
        .container-welcome .welcome {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            padding: 0 50px;
            color: white;
            transition: transform 0.5s ease-in-out;
        }
        .welcome-sign-in {
            transform: translateX(100%);
        }
        .container-welcome h3 {
            font-size: 40px;
        }
        .container-welcome p {
            font-size: 14px;
            text-align: center;
        }
        .container-welcome .button {
            border: 2px solid white;
            background-color: transparent;
        }
        .container.toggle .welcome-sign-in {
            transform: translateX(0);
        }
        .container.toggle .welcome-sign-up {
            transform: translateX(-100%);
        }
        .error-message {
            color: red;
            margin: 10px 0;
            font-size: 14px;
            text-align: center;
            max-width: 300px;
        }
        .success-message {
            color: green;
            margin: 10px 0;
            font-size: 14px;
            text-align: center;
            max-width: 300px;
        }
        .active {
            display: flex !important;
        }
        .admin-notice {
            color: #3ab397;
            font-weight: bold;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            cursor: pointer;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="container" id="container">
        <!-- Formulario de inicio de sesión -->
        <div class="container-form">
            <form class="sign-in" method="POST">
                <h2>Iniciar Sesión</h2>
                <span>Use su correo y contraseña</span>
                
                <?php if(!empty($error_login)): ?>
                    <div class="error-message"><?php echo $error_login; ?></div>
                <?php endif; ?>
                
                <?php if(!empty($error_registro)): ?>
                    <?php if(strpos($error_registro, 'success:') === 0): ?>
                        <div class="success-message"><?php echo substr($error_registro, 8); ?></div>
                    <?php else: ?>
                        <div class="error-message"><?php echo $error_registro; ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="container-input">
                    <ion-icon name="mail-outline"></ion-icon>
                    <input type="text" name="email" placeholder="Email" required> 
                </div>
                <div class="container-input">
                    <ion-icon name="lock-closed-outline"></ion-icon>
                    <input type="password" name="password" id="login-password" placeholder="Contraseña" required>
                    <ion-icon name="eye-outline" class="toggle-password" onclick="togglePassword('login-password', this)"></ion-icon>
                </div>
                <div class="container-input">
                    <ion-icon name="person-circle-outline"></ion-icon>
                    <select name="rol" required>
                        <option value="" disabled selected>Seleccione su rol</option>
                        <option value="administrador">Administrador</option>
                        <option value="profesor">Profesor</option>
                    </select>
                </div>
                <a href="#">¿Olvidaste tu contraseña?</a>
                <button type="submit" name="iniciar_sesion" class="button">INICIAR SESIÓN</button>
            </form>
        </div>
        
        <!-- Formulario de registro -->
        <div class="container-form">
            <form class="sign-up" method="POST">
                <h2>Registro de Administrador</h2>
                <span>Complete todos los campos para registrarse como Administrador</span>
                
                <?php if(!empty($error_registro) && strpos($error_registro, 'success:') === false): ?>
                    <div class="error-message"><?php echo $error_registro; ?></div>
                <?php endif; ?>
                
                <div class="container-input">
                    <ion-icon name="person-circle-outline"></ion-icon>
                    <input type="text" name="nombre" placeholder="Nombre completo" required> 
                </div>
                <div class="container-input">
                    <ion-icon name="mail-outline"></ion-icon>
                    <input type="email" name="email" placeholder="Correo electrónico" required> 
                </div>
                <div class="container-input">
                    <ion-icon name="lock-closed-outline"></ion-icon>
                    <input type="password" name="password" id="register-password" placeholder="Contraseña" required>
                    <ion-icon name="eye-outline" class="toggle-password" onclick="togglePassword('register-password', this)"></ion-icon>
                </div>
                <div class="container-input">
                    <ion-icon name="lock-closed-outline"></ion-icon>
                    <input type="password" name="confirm_password" id="confirm-password" placeholder="Confirmar Contraseña" required>
                    <ion-icon name="eye-outline" class="toggle-password" onclick="togglePassword('confirm-password', this)"></ion-icon>
                </div>
                <div class="admin-notice">
                    * Todos los registros desde este formulario serán como Administradores
                </div>
                <button type="submit" name="registrarse" class="button">REGISTRARSE COMO ADMIN</button>
            </form>
        </div>
        
        <div class="container-welcome">
            <!-- Sección 1 sign-in a sing up -->
            <div class="welcome-sign-up welcome active">
                <h3>¡Bienvenido!</h3>
                <p>Ingrese sus datos personales para registrarse como Administrador</p>
                <button type="button" class="button" id="btn-sign-up">Registrarse</button>
            </div>
            <!-- Sección 2 sign-up a sing in -->
            <div class="welcome-sign-in welcome">
                <h3>¡Hola!</h3>
                <p>Inicie sesión con sus credenciales de Administrador o Profesor</p>
                <button type="button" class="button" id="btn-sign-in">Iniciar Sesión</button>
            </div>
        </div>
    </div>

    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    
    <script>
        // JavaScript para la animación de los formularios
        const container = document.querySelector(".container");
        const btnSigIn = document.getElementById("btn-sign-in");
        const btnSigUp = document.getElementById("btn-sign-up");

        btnSigIn.addEventListener("click", () => {
            container.classList.remove("toggle");
        });

        btnSigUp.addEventListener("click", () => {
            container.classList.add("toggle");
        });
        
        // Mostrar mensaje de éxito en registro
        <?php if(!empty($error_registro) && strpos($error_registro, 'success:') !== false): ?>
            container.classList.remove("toggle");
        <?php endif; ?>
        
        // Función para mostrar/ocultar contraseña
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.name = "eye-off-outline";
            } else {
                input.type = "password";
                icon.name = "eye-outline";
            }
        }
    </script>
</body>
</html>