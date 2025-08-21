<?php
session_start();

// Habilitar mensajes de error para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Redirigir si no hay sesión activa o el rol no es administrador
if (!isset($_SESSION['id']) || strtolower($_SESSION['rol']) !== 'administrador') {
    header("Location: ../index.php");
    exit();
}

// Incluir el archivo de conexión
require_once 'conexion.php';
$db = getDBConnection();

$nombre_usuario = $_SESSION['nombres'] ?? 'Administrador';
$id_usuario_sesion = $_SESSION['id'] ?? null;
$apellidos_usuario = $_SESSION['apellidos'] ?? '';

// ======================
// LÓGICA PARA ELIMINAR USUARIO
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_usuario'])) {
    $id_a_eliminar = (int)$_POST['id_usuario'];

    // Asegurarse de que el administrador no se pueda eliminar a sí mismo
    if ($id_a_eliminar === $id_usuario_sesion) {
        $_SESSION['error'] = "No puedes eliminar tu propia cuenta de administrador.";
    } else {
        try {
            // Eliminar el usuario de la base de datos
            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $id_a_eliminar);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['mensaje'] = "Usuario eliminado exitosamente.";
                } else {
                    $_SESSION['error'] = "No se encontró el usuario o no se pudo eliminar.";
                }
            } else {
                $_SESSION['error'] = "Error al eliminar el usuario: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['error'] = "Error del sistema al eliminar: " . $e->getMessage();
            error_log($e->getMessage());
        }
    }
    // Redirigir para evitar re-envío del formulario
    header("Location: panel_administrador.php");
    exit();
}

// ======================
// OBTENER DATOS REALES DEL SISTEMA PARA LAS TARJETAS
// ======================
$total_usuarios = 0;
$total_profesores = 0;
$total_administradores = 0;
$total_reportes = 0;
$reportes_este_mes = 0;

try {
    // 1. Contar usuarios por rol (incluyendo administradores y profesores)
    $stmt_usuarios = $db->prepare("SELECT rol, COUNT(*) as count FROM usuarios GROUP BY rol");
    $stmt_usuarios->execute();
    $resultados_usuarios = $stmt_usuarios->get_result();
    while ($row = $resultados_usuarios->fetch_assoc()) {
        $total_usuarios += $row['count'];
        if (strtolower($row['rol']) === 'profesor') {
            $total_profesores = $row['count'];
        } elseif (strtolower($row['rol']) === 'administrador') {
            $total_administradores = $row['count'];
        }
    }
    $stmt_usuarios->close();

    // 2. Contar todos los reportes y los de este mes
    $stmt_reportes_total = $db->prepare("SELECT COUNT(*) as total FROM reportes");
    $stmt_reportes_total->execute();
    $resultado_total = $stmt_reportes_total->get_result()->fetch_assoc();
    $total_reportes = $resultado_total['total'];
    $stmt_reportes_total->close();
    
    $primer_dia_mes = date('Y-m-01');
    $ultimo_dia_mes = date('Y-m-t'); 
    $stmt_reportes_mes = $db->prepare("SELECT COUNT(*) as total_mes FROM reportes WHERE fecha_creacion BETWEEN ? AND ?");
    $stmt_reportes_mes->bind_param("ss", $primer_dia_mes, $ultimo_dia_mes);
    $stmt_reportes_mes->execute();
    $resultado_mes = $stmt_reportes_mes->get_result()->fetch_assoc();
    $reportes_este_mes = $resultado_mes['total_mes'];
    $stmt_reportes_mes->close();
    
} catch (Exception $e) {
    error_log("Error al obtener datos del panel: " . $e->getMessage());
}

// ======================
// OBTENER LA LISTA DE TODOS LOS USUARIOS
// ======================
$usuarios = [];
try {
    $stmt_lista = $db->prepare("SELECT id, nombres, email, rol, fecha_creacion FROM usuarios ORDER BY id DESC");
    $stmt_lista->execute();
    $resultado_lista = $stmt_lista->get_result();
    $usuarios = $resultado_lista->fetch_all(MYSQLI_ASSOC);
    $stmt_lista->close();
} catch (Exception $e) {
    $_SESSION['error'] = "Error al cargar la lista de usuarios: " . $e->getMessage();
    error_log($e->getMessage());
}

$db->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administrador - AttendSync</title>
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
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            width: 100%;
        }
        
        .main-header h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin: 0;
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
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .panel-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .panel-header h1 {
            font-size: 2.2rem;
            font-weight: 600;
            margin: 0;
        }

        .panel-header svg {
            width: 35px;
            height: 35px;
        }
        
        .panel-description {
            color: #666;
            margin-bottom: 30px;
            font-size: 1rem;
        }

        /* ====================== */
        /* DASHBOARD CARDS */
        /* ====================== */
        .dashboard-cards {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: space-between;
        }

        .card {
            background-color: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            width: calc(33.33% - 20px);
            min-width: 280px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background-color: var(--background-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .card-icon svg {
            width: 24px;
            height: 24px;
        }

        .card h3 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .card p {
            font-size: 1rem;
            color: #666;
            margin: 0;
        }
        
        .card .total-count {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--text-color);
            margin-top: 10px;
        }
        
        /* ====================== */
        /* MENSAJES */
        /* ====================== */
        .success-message {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 25px;
            border-left: 4px solid #2e7d32;
        }

        .error-message {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 25px;
            border-left: 4px solid #c62828;
        }

        /* ====================== */
        /* TABLA DE USUARIOS */
        /* ====================== */
        .user-section {
            margin-top: 40px;
        }
        
        .user-section h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .table-container {
            overflow-x: auto;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .user-table th, .user-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .user-table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
        }

        .user-table tr:hover {
            background-color: #f9f9f9;
        }

        .user-table .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            color: white;
            transition: opacity 0.3s;
        }
        
        .user-table .action-btn:hover {
            opacity: 0.8;
        }
        
        .user-table .delete-btn {
            background-color: #e74c3c;
        }
        
        .user-table .delete-btn:disabled {
            background-color: #95a5a6;
            cursor: not-allowed;
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
            .card {
                width: 100%;
                margin-bottom: 15px;
            }
        }

        @media (max-width: 768px) {
            .user-table thead {
                display: none;
            }
            .user-table, .user-table tbody, .user-table tr, .user-table td {
                display: block;
                width: 100%;
            }
            .user-table tr {
                margin-bottom: 15px;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                background-color: var(--white);
            }
            .user-table td {
                text-align: right;
                padding-left: 50%;
                position: relative;
            }
            .user-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                padding-right: 10px;
                white-space: nowrap;
                font-weight: bold;
                text-align: left;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
<?php
$current_page = basename($_SERVER['PHP_SELF']); 
?>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>AttendSync</h2>
    </div>
    
    <a href="panel_administrador.php" class="sidebar-btn <?php echo ($current_page == 'panel_administrador.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span>Panel Principal</span>
    </a>
    
    <a href= "usuarios.php" class="sidebar-btn <?php echo ($current_page == 'usuarios.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Usuarios</span>
    </a>
    
    <a href="gestion_grupos.php" class="sidebar-btn <?php echo ($current_page == 'gestion_grupos.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
        <span>Gestión de Grupos</span>
    </a>
    
    <a href="gestion_estudiantes.php" class="sidebar-btn <?php echo ($current_page == 'gestion_estudiantes.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Estudiantes</span>
    </a>
    
    <a href="reportes.php" class="sidebar-btn <?php echo ($current_page == 'reportes.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        <span>Reportes</span>
    </a>

    <a href="ajustes_admin.php" class="sidebar-btn <?php echo ($current_page == 'ajustes_admin.php') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
        <span>Ajustes y Reportes</span>
    </a>
</div>

<div class="main-content">
    <div class="admin-header">
        <h1>Panel de Administrador</h1>
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($nombre_usuario, 0, 1) . substr($apellidos_usuario, 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?php 
                    echo htmlspecialchars($_SESSION['error']); 
                    unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['mensaje'])): ?>
            <div class="success-message">
                <?php 
                    echo htmlspecialchars($_SESSION['mensaje']); 
                    unset($_SESSION['mensaje']);
                ?>
            </div>
        <?php endif; ?>

        <div class="panel-header">
            <svg width="35" height="35" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="#3ab397"/>
            </svg>
            <h1>Panel de Administrador</h1>
        </div>
        <p class="panel-description">Bienvenido al panel de control administrativo. Desde aquí puedes gestionar todos los aspectos del sistema.</p>
        
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </div>
                <h3>Usuarios Registrados</h3>
                <p class="total-count">Total: <?php echo $total_usuarios; ?> usuarios</p>
                <p>Administradores: <?php echo $total_administradores; ?></p>
                <p>Profesores: <?php echo $total_profesores; ?></p>
            </div>
            
            <div class="card">
                <div class="card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zM6 20V4h7v5h5v11H6z"/>
                    </svg>
                </div>
                <h3>Reportes Recientes</h3>
                <p class="total-count">Total reportes generados: <?php echo $total_reportes; ?></p>
                <p>Reportes este mes: <?php echo $reportes_este_mes; ?></p>
            </div>
            
            <div class="card" style="visibility: hidden;">
            </div>
        </div>

        <div id="user-section" class="user-section">
            <h2>Gestión de Usuarios</h2>
            <p>A continuación, se muestra una lista de todos los usuarios registrados en el sistema. Puedes eliminar a cualquier usuario con los permisos adecuados.</p>
            <div class="table-container">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Fecha de Creación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="6">No hay usuarios registrados en la base de datos.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usuarios as $usuario): ?>
                                <tr>
                                    <td data-label="ID"><?php echo htmlspecialchars($usuario['id']); ?></td>
                                    <td data-label="Nombre"><?php echo htmlspecialchars($usuario['nombres']); ?></td>
                                    <td data-label="Email"><?php echo htmlspecialchars($usuario['email']); ?></td>
                                    <td data-label="Rol"><?php echo htmlspecialchars(ucfirst($usuario['rol'])); ?></td>
                                    <td data-label="Fecha de Creación"><?php echo date('d/m/Y', strtotime($usuario['fecha_creacion'])); ?></td>
                                    <td data-label="Acciones">
                                        <form method="POST" onsubmit="return confirm('¿Estás seguro de que deseas eliminar a este usuario? Esta acción es irreversible.');" style="display:inline;">
                                            <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($usuario['id']); ?>">
                                            <button type="submit" name="eliminar_usuario" class="action-btn delete-btn" 
                                                    <?php echo ($usuario['id'] == $id_usuario_sesion || strtolower($usuario['rol']) === 'administrador') ? 'disabled' : ''; ?> 
                                                    title="<?php echo ($usuario['id'] == $id_usuario_sesion) ? 'No puedes eliminar tu propia cuenta' : (strtolower($usuario['rol']) === 'administrador' ? 'No se puede eliminar a otro administrador' : 'Eliminar usuario'); ?>">
                                                Eliminar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>