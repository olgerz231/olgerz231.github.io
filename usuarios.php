<?php
// Inicio del archivo usuarios.php
session_start();

require_once 'conexion.php'; // Asegúrate de que la ruta es correcta

// Verificar sesión y rol
if (!isset($_SESSION['usuario']) || strtolower($_SESSION['rol']) != 'administrador') {
    header("Location: index.php");
    exit();
}

// Definir variables de usuario
$nombre_usuario = $_SESSION['usuario'] ?? "Invitado";
$apellidos_usuario = $_SESSION['apellidos'] ?? ''; // Asumiendo que esta variable existe en tu sesión
$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje']);
unset($_SESSION['error']);

// Función para limpiar inputs
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Obtener conexión usando la nueva clase
$db = getDBConnection();

// Procesar formulario de creación de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    // Recoger y limpiar datos
    $nombres = cleanInput($_POST['nombres']);
    $apellidos = cleanInput($_POST['apellidos']);
    $email = cleanInput($_POST['email']);
    $usuario = cleanInput($_POST['username']);

    // Validación de contraseñas
    if ($_POST['password'] !== $_POST['confirm_password']) {
        $_SESSION['error'] = "Las contraseñas no coinciden.";
        header("Location: usuarios.php");
        exit();
    }

    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $rol = in_array($_POST['rol'], ['profesor', 'administrador']) ? $_POST['rol'] : 'profesor';
    $materia_id = $_POST['materia_id'] ?? null;

    // Iniciar transacción
    $db->begin_transaction();

    try {
        // Insertar usuario
        $stmt_user = $db->prepare("INSERT INTO usuarios (nombres, apellidos, email, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_user->bind_param("ssssss", $nombres, $apellidos, $email, $usuario, $password, $rol);

        if (!$stmt_user->execute()) {
            throw new Exception("Error al crear usuario: " . $stmt_user->error);
        }

        $ultimo_id = $db->insert_id;

        // Asignar materia si es profesor
        if ($rol === 'profesor' && $materia_id) {
            $stmt_materia = $db->prepare("INSERT INTO profesor_materia (profesor_id, materia_id) VALUES (?, ?)");
            $stmt_materia->bind_param("ii", $ultimo_id, $materia_id);
            if (!$stmt_materia->execute()) {
                throw new Exception("Error al asignar materia: " . $stmt_materia->error);
            }
        }

        $db->commit();
        $_SESSION['mensaje'] = "Usuario creado exitosamente";
        header("Location: usuarios.php");
        exit();

    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = $e->getMessage();
        error_log($e->getMessage());
    }
}

// Procesar formulario de adición de materia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_materia'])) {
    $nombre_materia = cleanInput($_POST['nombre_materia']);

    try {
        $stmt = $db->prepare("INSERT INTO materias (nombre_materia) VALUES (?)");
        $stmt->bind_param("s", $nombre_materia);

        if ($stmt->execute()) {
            $_SESSION['mensaje'] = "Materia '{$nombre_materia}' agregada exitosamente.";
        } else {
            throw new Exception("Error al agregar materia: " . $stmt->error);
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: usuarios.php");
    exit();
}

// Obtener todas las materias para la lista desplegable
$materias = [];
$result = $db->query("SELECT id_materia, nombre_materia FROM materias ORDER BY nombre_materia");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $materias[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - AttendSync</title>
    <style>
        /* ====================== */
        /* ESTILOS GENERALES */
        /* ====================== */
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Montserrat", sans-serif;
        }

        :root {
            --primary-color: #3ab397;
            --secondary-color: #3aa8ad;
            --background-color: #f0f4f3;
            --text-color: #333;
            --white: #ffffff;
            --border-color: #e1e5eb;
            --error-color: #d32f2f;
            --success-color: #2e7d32;
            --warning-color: #f9a825;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
        }

        /* ====================== */
        /* SIDEBAR */
        /* ====================== */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-color), #2e9e87);
            color: white;
            padding: 25px 0;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-header {
            padding: 0 20px 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-header h2 {
            margin: 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            font-weight: 700;
        }

        .sidebar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: none;
            border: none;
            color: #ecf0f1;
            text-align: left;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            width: 100%;
            text-decoration: none;
        }

        .sidebar-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-btn.active {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid white;
            font-weight: bold;
        }
        
        .sidebar-btn svg {
             fill: white; 
             width: 20px; 
             height: 20px;
        }

        /* ====================== */
        /* MAIN CONTENT */
        /* ====================== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
        }

        .admin-header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            width: 100%;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .user-info span {
            display: none;
        }
        
        @media (min-width: 768px) {
             .user-info span {
                display: inline;
             }
        }


        .logout-btn {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s;
        }

        .logout-btn:hover {
            background-color: #2a8a7a;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: var(--white);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        h1 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.8rem;
        }

        /* ====================== */
        /* MENSAJES */
        /* ====================== */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            border-left: 4px solid transparent;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: var(--success-color);
            border-left-color: var(--success-color);
        }

        .alert-error {
            background-color: #ffebee;
            color: var(--error-color);
            border-left-color: var(--error-color);
        }

        /* Card */
        .user-card {
            background-color: var(--white);
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .card-title {
            margin-top: 0;
            margin-bottom: 30px;
            color: var(--secondary-color);
            font-size: 1.5rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .card-title svg {
            margin-right: 12px;
            fill: var(--secondary-color);
        }
        
        /* Form Elements */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: var(--white);
        }
        
        .form-control:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(58, 168, 173, 0.15);
        }
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233aa8ad' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 15px;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            transition: color 0.3s;
        }

        .toggle-password:hover {
            color: var(--secondary-color);
        }
        
        /* Radio Buttons */
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
        }
        
        .radio-option input {
            margin-right: 8px;
            accent-color: var(--secondary-color);
        }

        /* Materias section */
        .materias-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            align-items: flex-end;
        }

        .materias-container .form-group {
            flex: 1;
        }
        
        /* Buttons */
        .btn {
            padding: 13px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background-color: #2e9e87;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(58, 179, 151, 0.25);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 2px solid var(--secondary-color);
            color: var(--secondary-color);
        }
        
        .btn-outline:hover {
            background-color: rgba(58, 168, 173, 0.1);
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 35px;
            flex-wrap: wrap;
        }
        
        .btn-add {
            padding: 13px 20px;
        }

        /* ====================== */
        /* RESPONSIVE */
        /* ====================== */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .materias-container {
                flex-direction: column;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
<?php
// Obtenemos la URL actual para compararla
$current_page = basename($_SERVER['PHP_SELF']);
// Obtenemos el ancla actual si existe
$current_hash = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_FRAGMENT) : '';
?>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>AttendSync</h2>
    </div>
    
    <a href="panel_administrador.php" id="menu-panel-principal" class="sidebar-btn <?php echo ($current_page == 'panel_administrador.php' && empty($current_hash)) ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span>Panel Principal</span>
    </a>
    
    <a href="usuarios.php" id="menu-gestion-usuarios" class="sidebar-btn <?php echo ($current_page == 'usuarios.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Usuarios</span>
    </a>
    
    <a href="gestion_grupos.php" id="menu-gestion-grupos" class="sidebar-btn <?php echo ($current_page == 'gestion_grupos.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
        <span>Gestión de Grupos</span>
    </a>
    
    <a href="gestion_estudiantes.php" id="menu-gestion-estudiantes" class="sidebar-btn <?php echo ($current_page == 'gestion_estudiantes.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Estudiantes</span>
    </a>
    
    <a href="reportes.php" id="menu-reportes" class="sidebar-btn <?php echo ($current_page == 'reportes.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        <span>Reportes</span>
    </a>

    <a href="ajustes_admin.php" id="menu-ajustes" class="sidebar-btn <?php echo ($current_page == 'ajustes_admin.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
        <span>Ajustes y Reportes</span>
    </a>
</div>

<div class="main-content">
    <div class="admin-header">
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($nombre_usuario, 0, 1) . substr($apellidos_usuario, 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <h1><svg viewBox="0 0 24 24" width="35" height="35" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="#3ab397"/></svg> Gestión de Usuarios</h1>
        
        <div class="user-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="#3aa8ad"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                Agregar Nueva Materia
            </h2>
            <form method="POST" action="usuarios.php">
                <div class="materias-container">
                    <div class="form-group" style="flex: 1;">
                        <label for="nombre_materia">Nombre de la Materia</label>
                        <input type="text" id="nombre_materia" name="nombre_materia" class="form-control"
                            placeholder="Ej: Química" required>
                    </div>
                    <button type="submit" name="agregar_materia" class="btn btn-primary btn-add">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                        </svg>
                        Agregar Materia
                    </button>
                </div>
            </form>
        </div>

        <div class="user-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="#3aa8ad"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                Crear Nuevo Usuario
            </h2>

            <form method="POST" action="usuarios.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nombres">Nombres</label>
                        <input type="text" id="nombres" name="nombres" class="form-control"
                            placeholder="Ej: María José" required
                            value="<?php echo htmlspecialchars($_POST['nombres'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="apellidos">Apellidos</label>
                        <input type="text" id="apellidos" name="apellidos" class="form-control"
                            placeholder="Ej: González Pérez" required
                            value="<?php echo htmlspecialchars($_POST['apellidos'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" class="form-control"
                            placeholder="Ej: usuario@institucion.edu" required
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="username">Nombre de Usuario</label>
                        <input type="text" id="username" name="username" class="form-control"
                            placeholder="Ej: mjgonzalez" required
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <div class="input-group">
                            <input type="password" id="password" name="password" class="form-control"
                                placeholder="••••••••" required>
                            <span class="toggle-password" onclick="togglePassword('password')">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                                    <path id="toggle-password-icon" d="M12 4.5c-4.73 0-8.62 3.89-8.62 8.62s3.89 8.62 8.62 8.62 8.62-3.89 8.62-8.62S16.73 4.5 12 4.5zM12 18.25c-3.17 0-5.75-2.58-5.75-5.75S8.83 6.75 12 6.75s5.75 2.58 5.75 5.75-2.58 5.75-5.75 5.75zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4z"/>
                                </svg>
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirmar Contraseña</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                placeholder="••••••••" required>
                            <span class="toggle-password" onclick="togglePassword('confirm_password')">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                                    <path id="toggle-confirm_password-icon" d="M12 4.5c-4.73 0-8.62 3.89-8.62 8.62s3.89 8.62 8.62 8.62 8.62-3.89 8.62-8.62S16.73 4.5 12 4.5zM12 18.25c-3.17 0-5.75-2.58-5.75-5.75S8.83 6.75 12 6.75s5.75 2.58 5.75 5.75-2.58 5.75-5.75 5.75zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4z"/>
                                </svg>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de Usuario</label>
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" id="type-admin" name="rol" value="administrador"
                                    <?php echo (($_POST['rol'] ?? '') == 'administrador' ? 'checked' : ''); ?>>
                                <label for="type-admin">Administrador</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="type-teacher" name="rol" value="profesor"
                                    <?php echo (($_POST['rol'] ?? 'profesor') == 'profesor' ? 'checked' : ''); ?>>
                                <label for="type-teacher">Profesor</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="materia-group" style="display: <?php echo (($_POST['rol'] ?? 'profesor') == 'profesor' ? 'block' : 'none'); ?>;">
                        <label for="materia_id">Asignar Materia</label>
                        <select id="materia_id" name="materia_id" class="form-control form-select">
                            <?php foreach ($materias as $materia): ?>
                                <option value="<?php echo htmlspecialchars($materia['id_materia']); ?>">
                                    <?php echo htmlspecialchars($materia['nombre_materia']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" name="crear_usuario" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/>
                        </svg>
                        Crear Usuario
                    </button>
                    <a href="panel_administrador.php" class="btn btn-outline">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function togglePassword(inputId) {
        const passwordInput = document.getElementById(inputId);
        const toggleIcon = passwordInput.nextElementSibling.querySelector('svg');
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';

        // Icono de ojo abierto y cerrado
        const openEyePath = "M12 4.5c-4.73 0-8.62 3.89-8.62 8.62s3.89 8.62 8.62 8.62 8.62-3.89 8.62-8.62S16.73 4.5 12 4.5zM12 18.25c-3.17 0-5.75-2.58-5.75-5.75S8.83 6.75 12 6.75s5.75 2.58 5.75 5.75-2.58 5.75-5.75 5.75zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4z";
        const closedEyePath = "M12 4.5c-4.73 0-8.62 3.89-8.62 8.62s3.89 8.62 8.62 8.62 8.62-3.89 8.62-8.62S16.73 4.5 12 4.5zM12 18.25c-3.17 0-5.75-2.58-5.75-5.75S8.83 6.75 12 6.75s5.75 2.58 5.75 5.75-2.58 5.75-5.75 5.75zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4z"; // Usaré el mismo, ya que el original no está en el código
        
        // El ícono original de la página es el de un ojo abierto.
        // Si el tipo de campo es 'password', significa que el ojo está cerrado y debe mostrar el ícono de ojo abierto.
        // Si el tipo de campo es 'text', significa que el ojo está abierto y debe mostrar un ícono de ojo cerrado.
        // Como no tengo el SVG del ojo cerrado, simplemente mantengo el mismo por ahora.
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        const menuLinks = document.querySelectorAll('.sidebar-btn');
        const rolRadios = document.querySelectorAll('input[name="rol"]');
        const materiaGroup = document.getElementById('materia-group');

        // Función para activar un elemento del menú
        function activateMenuItem(linkElement) {
            menuLinks.forEach(link => link.classList.remove('active'));
            if (linkElement) {
                linkElement.classList.add('active');
            }
        }
        
        // Activar el enlace del menú para esta página
        const userManagementLink = document.getElementById('menu-gestion-usuarios');
        if (userManagementLink) {
            activateMenuItem(userManagementLink);
        }

        // Mostrar u ocultar el campo de materia según el rol
        function updateMateriaVisibility() {
            const selectedRol = document.querySelector('input[name="rol"]:checked').value;
            if (selectedRol === 'profesor') {
                materiaGroup.style.display = 'block';
            } else {
                materiaGroup.style.display = 'none';
            }
        }

        rolRadios.forEach(radio => {
            radio.addEventListener('change', updateMateriaVisibility);
        });

        // Llamar a la función al cargar la página para el estado inicial
        updateMateriaVisibility();
    });
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
</body>
</html>