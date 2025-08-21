<?php
// Habilitar la visualización de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// NUEVO: Incluir el autoloader de Composer para poder usar la librería PhpSpreadsheet
require 'vendor/autoload.php';

// NUEVO: Declarar la clase que usaremos de la librería
use PhpOffice\PhpSpreadsheet\IOFactory;

// Iniciar sesión
session_start();

// Incluir el archivo de conexión a la base de datos
require_once 'conexion.php';

// Verificar si el usuario está logueado y tiene permisos de administrador
if (!isset($_SESSION['usuario']) || strtolower($_SESSION['rol']) !== 'administrador') {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();
$success_message = '';
$error_message = '';

// MODIFICADO: Lógica para agregar grupo e importar estudiantes desde Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $nombre_grupo = trim($_POST['nombre']);
    $profesor_id = intval($_POST['profesor_id']);

    if (empty($nombre_grupo) || $profesor_id <= 0) {
        $error_message = "El nombre del grupo y el profesor son obligatorios.";
    } else {
        // Iniciar una transacción: si algo falla, se revierte todo
        $conn->begin_transaction();

        try {
            // 1. Crear el grupo en la base de datos
            $stmt_grupo = $conn->prepare("INSERT INTO grupos (nombre, profesor_id) VALUES (?, ?)");
            $stmt_grupo->bind_param("si", $nombre_grupo, $profesor_id);
            if (!$stmt_grupo->execute()) {
                throw new Exception("Error al crear el grupo: " . $stmt_grupo->error);
            }
            $grupo_id = $stmt_grupo->insert_id; // Obtenemos el ID del grupo recién creado
            $stmt_grupo->close();

            $success_message = "Grupo '$nombre_grupo' creado correctamente.";

            // 2. Procesar el archivo Excel si se ha subido uno
            if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['archivo_excel']['tmp_name'];

                // Cargar el archivo Excel usando la librería PhpSpreadsheet
                $spreadsheet = IOFactory::load($file_tmp_name);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                $estudiantes_importados = 0;

                // Preparar las sentencias SQL para usarlas repetidamente dentro del bucle (más eficiente)
                $stmt_find_student = $conn->prepare("SELECT id FROM estudiantes WHERE cedula = ?");
                $stmt_create_student = $conn->prepare("INSERT INTO estudiantes (cedula, primer_apellido, segundo_apellido, nombre) VALUES (?, ?, ?, ?)");
                // Usamos INSERT IGNORE para que no falle si el estudiante ya estaba asignado a ese grupo
                $stmt_link_student = $conn->prepare("INSERT IGNORE INTO grupo_estudiante (grupo_id, estudiante_id) VALUES (?, ?)");

                // Iterar sobre cada fila del Excel (empezando en la 2 para saltar los encabezados)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $cedula = trim($worksheet->getCell('A' . $row)->getValue());
                    $primer_apellido = trim($worksheet->getCell('B' . $row)->getValue());
                    $segundo_apellido = trim($worksheet->getCell('C' . $row)->getValue());
                    $nombre = trim($worksheet->getCell('D' . $row)->getValue());

                    // Si la cédula está vacía, ignoramos la fila
                    if (empty($cedula)) {
                        continue;
                    }

                    $estudiante_id = null;
                    // Buscar si el estudiante ya existe por su cédula
                    $stmt_find_student->bind_param("s", $cedula);
                    $stmt_find_student->execute();
                    $result_student = $stmt_find_student->get_result();

                    if ($result_student->num_rows > 0) {
                        // Si existe, obtenemos su ID
                        $estudiante = $result_student->fetch_assoc();
                        $estudiante_id = $estudiante['id'];
                    } else {
                        // Si no existe, lo creamos
                        $stmt_create_student->bind_param("ssss", $cedula, $primer_apellido, $segundo_apellido, $nombre);
                        if (!$stmt_create_student->execute()) {
                            throw new Exception("Error al crear al estudiante con cédula $cedula en la fila $row.");
                        }
                        $estudiante_id = $stmt_create_student->insert_id;
                    }

                    // Asignar el estudiante (nuevo o existente) al grupo
                    if ($estudiante_id) {
                        $stmt_link_student->bind_param("ii", $grupo_id, $estudiante_id);
                        $stmt_link_student->execute();
                        $estudiantes_importados++;
                    }
                }

                // Cerramos las sentencias preparadas
                $stmt_find_student->close();
                $stmt_create_student->close();
                $stmt_link_student->close();

                $success_message .= " Se importaron y asignaron $estudiantes_importados estudiantes desde el archivo.";
            }

            // Si todo salió bien, confirmamos los cambios en la base de datos
            $conn->commit();
        } catch (Exception $e) {
            // Si algo falló, revertimos todos los cambios
            $conn->rollback();
            $error_message = "Ocurrió un error en el proceso: " . $e->getMessage();
        }
    }
}


// Lógica para editar un grupo (sin cambios)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    // ... tu código de edición existente va aquí ...
}

// ===== INICIO DE LA SECCIÓN MODIFICADA =====
// Lógica para eliminar un grupo
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete') {
    $grupo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($grupo_id > 0) {
        // Iniciar una transacción para asegurar la integridad de los datos
        $conn->begin_transaction();
        try {
            // 1. Eliminar las relaciones de estudiantes en la tabla `grupo_estudiante`
            $stmt1 = $conn->prepare("DELETE FROM grupo_estudiante WHERE grupo_id = ?");
            $stmt1->bind_param("i", $grupo_id);
            $stmt1->execute();
            $stmt1->close();

            // 2. Eliminar el grupo de la tabla `grupos`
            $stmt2 = $conn->prepare("DELETE FROM grupos WHERE id = ?");
            $stmt2->bind_param("i", $grupo_id);
            $stmt2->execute();

            if ($stmt2->affected_rows > 0) {
                $success_message = "Grupo eliminado correctamente.";
            } else {
                $error_message = "El grupo no fue encontrado o ya había sido eliminado.";
            }
            $stmt2->close();

            // Si todo fue bien, confirmar los cambios
            $conn->commit();

        } catch (Exception $e) {
            // Si algo falló, revertir todos los cambios
            $conn->rollback();
            $error_message = "Error al eliminar el grupo: " . $e->getMessage();
        }
    } else {
        $error_message = "ID de grupo no válido.";
    }
    // NOTA: No es necesario redirigir, la página se recargará y mostrará el mensaje.
}
// ===== FIN DE LA SECCIÓN MODIFICADA =====


// Obtener la lista de todos los grupos (sin cambios)
$grupos = [];
try {
    $query = "SELECT g.id, g.nombre, u.nombres AS profesor_nombres, u.apellidos AS profesor_apellidos FROM grupos g JOIN usuarios u ON g.profesor_id = u.id ORDER BY g.nombre";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $grupos[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error al cargar los grupos: " . $e->getMessage();
}

// Obtener la lista de todos los profesores (sin cambios)
$profesores = [];
try {
    $query_profesores = "SELECT id, nombres, apellidos FROM usuarios WHERE rol = 'profesor' ORDER BY apellidos, nombres";
    $result_profesores = $conn->query($query_profesores);
    if ($result_profesores) {
        while ($row = $result_profesores->fetch_assoc()) {
            $profesores[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error al cargar la lista de profesores: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Grupos - AttendSync</title>
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
            --error-color: #c62828;
            --success-color: #2e7d32;
            --warning-color: #f9a825;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
        }

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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.8rem;
        }

        .card {
            background-color: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-color);
            margin-bottom: 20px;
        }

        .card h2 {
            color: var(--primary-color);
            margin-bottom: 20px; /* Aumentado el margen inferior */
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* NUEVO: Estilo para el icono en el h2 */
        .card h2 svg {
            fill: var(--primary-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: #f9fafb; /* Ligeramente gris para diferenciarlo */
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(58, 168, 173, 0.15);
            background-color: var(--white);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233aa8ad' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 15px;
        }

        /* MODIFICADO: Contenedor del formulario con Grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr; /* Dos columnas de igual tamaño */
            gap: 20px;
            align-items: start; /* Alinear items al inicio */
        }
        
        /* NUEVO: Hacer que un elemento ocupe las dos columnas */
        .grid-col-span-2 {
            grid-column: span 2;
        }


        /* ===== NUEVO: Estilos para el input de archivo personalizado ===== */
        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 130px; /* Altura aumentada */
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: border-color 0.3s, background-color 0.3s;
            cursor: pointer;
            background-color: #f9fafb;
        }
        .file-upload-wrapper:hover {
            border-color: var(--primary-color);
            background-color: #f5fbf9;
        }
        .file-upload-wrapper input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-upload-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .file-upload-text {
            color: var(--text-color);
            font-weight: 500;
        }
        .file-upload-text span {
            color: var(--primary-color);
            font-weight: 700;
        }
        .file-upload-hint {
            color: #888;
            font-size: 0.85em;
            margin-top: 5px;
        }
        /* ===== Fin de estilos para el input de archivo ===== */

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            font-size: 0.9em;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }

        .data-table thead tr {
            background-color: var(--primary-color);
            color: white;
            text-align: left;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
        }

        .data-table tbody tr {
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tbody tr:nth-of-type(even) {
            background-color: #f9f9f9;
        }

        .data-table tbody tr:hover {
            background-color: #f1f1f1;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn {
            padding: 12px 20px; /* Padding aumentado para un botón más grande */
            border-radius: 8px; /* Bordes más redondeados */
            cursor: pointer;
            font-weight: 600; /* Fuente más gruesa */
            transition: all 0.3s;
            border: none;
            font-size: 1em; /* Tamaño de fuente ligeramente mayor */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-block {
             width: 100%; /* El botón ocupa todo el ancho */
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2e9e87;
            box-shadow: 0 4px 10px rgba(58, 179, 151, 0.3);
        }

        .btn-danger {
            background-color: var(--error-color);
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85em;
            border-radius: 4px;
        }

        .btn-danger:hover {
            background-color: #b01c1c;
        }
    </style>
</head>

<body>
    <?php
    $current_page = basename($_SERVER['PHP_SELF']);
    $current_hash = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_FRAGMENT) : '';
    $nombre_usuario_display = $_SESSION['nombres'] ?? $_SESSION['usuario'] ?? 'Administrador';
    $apellidos_usuario_display = $_SESSION['apellidos'] ?? '';
    ?>

    <div class="sidebar">
        <div class="sidebar-header">
            <h2>AttendSync</h2>
        </div>

        <a href="panel_administrador.php" class="sidebar-btn <?php echo ($current_page == 'panel_administrador.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />
            </svg>
            <span>Panel Principal</span>
        </a>

        <a href="usuarios.php" class="sidebar-btn <?php echo ($current_page == 'usuarios.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
            </svg>
            <span>Gestión de Usuarios</span>
        </a>

        <a href="gestion_grupos.php" class="sidebar-btn <?php echo ($current_page == 'gestion_grupos.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" />
            </svg>
            <span>Gestión de Grupos</span>
        </a>

        <a href="gestion_estudiantes.php" class="sidebar-btn <?php echo ($current_page == 'gestion_estudiantes.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
            </svg>
            <span>Gestión de Estudiantes</span>
        </a>

        <a href="reportes.php" class="sidebar-btn <?php echo ($current_page == 'reportes.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" />
            </svg>
            <span>Reportes</span>
        </a>

        <a href="ajustes_admin.php" class="sidebar-btn <?php echo ($current_page == 'ajustes_admin.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24">
                <path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12-.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z" />
            </svg>
            <span>Ajustes y Reportes</span>
        </a>
    </div>

    <div class="main-content">
        <div class="admin-header">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($nombre_usuario_display, 0, 1) . substr($apellidos_usuario_display, 0, 1)); ?>
                </div>
                <span><?php echo htmlspecialchars($nombre_usuario_display); ?></span>
                <form action="logout.php" method="post"><button type="submit" class="logout-btn">Cerrar sesión</button></form>
            </div>
        </div>

        <div class="container">
            <h1>Gestión de Grupos</h1>
            <?php if (!empty($success_message)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="card">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 -960 960 960" width="24"><path d="M440-200h80v-167l64 64 56-57-160-160-160 160 57 56 63-63v167ZM240-80q-33 0-56.5-23.5T160-160v-640q0-33 23.5-56.5T240-880h320l240 240v480q0 33-23.5 56.5T720-80H240Z"/></svg>
                    Agregar Nueva Sección e Importar Estudiantes
                </h2>
                <form action="gestion_grupos.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nombre">Sección:</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" required pattern="[0-9-]+" title="Solo se permiten números y guiones (-)" placeholder="Ej: 10-1">
                        </div>
                        <div class="form-group">
                            <label for="profesor_id">Profesor a Cargo:</label>
                            <select id="profesor_id" name="profesor_id" class="form-control form-select" required>
                                <option value="">Seleccione un profesor</option>
                                <?php foreach ($profesores as $profesor): ?>
                                    <option value="<?php echo htmlspecialchars($profesor['id']); ?>">
                                        <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group grid-col-span-2">
                             <label for="archivo_excel">Importar Lista de Estudiantes (Opcional)</label>
                             <div class="file-upload-wrapper" id="file-drop-zone">
                                 <input type="file" id="archivo_excel" name="archivo_excel" accept=".xls,.xlsx">
                                 <div class="file-upload-icon">
                                     <svg xmlns="http://www.w3.org/2000/svg" height="48" viewBox="0 -960 960 960" width="48"><path d="M440-320v-326L336-542l-56-58 200-200 200 200-56 58-104-104v326h-80ZM240-160q-33 0-56.5-23.5T160-240v-120h80v120h480v-120h80v120q0 33-23.5 56.5T720-160H240Z" fill="var(--primary-color)"/></svg>
                                 </div>
                                 <div class="file-upload-text" id="file-upload-text-content">
                                     <span>Haga clic para buscar</span> o arrastre el archivo aquí
                                 </div>
                                 <div class="file-upload-hint">
                                     Formato Excel (.xls, .xlsx). Columnas: Cédula, Apellido1, Apellido2, Nombre.
                                 </div>
                             </div>
                        </div>

                        <div class="form-group grid-col-span-2">
                             <button type="submit" class="btn btn-primary btn-block">
                                 <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="white"><path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/></svg>
                                 Agregar Sección e Importar
                             </button>
                        </div>

                    </div>
                </form>
            </div>
            <div class="card">
                <h2>Listado de Grupos</h2>
                <?php if (empty($grupos)): ?>
                    <p style="text-align: center; color: #888;">No hay grupos registrados.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Profesor a Cargo</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grupos as $grupo): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($grupo['id']); ?></td>
                                    <td><?php echo htmlspecialchars($grupo['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($grupo['profesor_nombres'] . ' ' . $grupo['profesor_apellidos']); ?></td>
                                    <td>
                                        <a href="?action=delete&id=<?php echo htmlspecialchars($grupo['id']); ?>" onclick="return confirm('¿Está seguro de que desea eliminar este grupo? Se eliminarán también las relaciones de estudiantes.')" class="btn btn-danger btn-sm">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            // --- Script para activar el enlace de la barra lateral ---
            const currentPage = '<?php echo basename($_SERVER['PHP_SELF']); ?>';
            const sidebarLinks = document.querySelectorAll('.sidebar-btn');
            sidebarLinks.forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });

            // --- Script para validación del input de sección ---
            const seccionInput = document.getElementById('nombre');
            if (seccionInput) {
                seccionInput.addEventListener('input', function(event) {
                    this.value = this.value.replace(/[^0-9-]/g, '');
                });
            }
            
            // --- NUEVO: Script para el input de archivo personalizado ---
            const fileInput = document.getElementById('archivo_excel');
            const fileDropZone = document.getElementById('file-drop-zone');
            const fileUploadText = document.getElementById('file-upload-text-content');

            if (fileInput) {
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length > 0) {
                        fileUploadText.textContent = `Archivo: ${fileInput.files[0].name}`;
                        fileDropZone.style.borderColor = 'var(--success-color)';
                    } else {
                        fileUploadText.innerHTML = `<span>Haga clic para buscar</span> o arrastre el archivo aquí`;
                         fileDropZone.style.borderColor = 'var(--border-color)';
                    }
                });

                // Efectos visuales de arrastrar y soltar (Drag and Drop)
                fileDropZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    fileDropZone.style.borderColor = 'var(--primary-color)';
                    fileDropZone.style.backgroundColor = '#f5fbf9';
                });

                 fileDropZone.addEventListener('dragleave', (e) => {
                    e.preventDefault();
                    fileDropZone.style.borderColor = 'var(--border-color)';
                    fileDropZone.style.backgroundColor = '#f9fafb';
                });

                fileDropZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    fileDropZone.style.borderColor = 'var(--border-color)';
                    fileDropZone.style.backgroundColor = '#f9fafb';
                    // Asignar los archivos soltados al input
                    if (e.dataTransfer.files.length > 0) {
                        fileInput.files = e.dataTransfer.files;
                        // Disparar el evento 'change' manualmente para actualizar el texto
                        const changeEvent = new Event('change', { bubbles: true });
                        fileInput.dispatchEvent(changeEvent);
                    }
                });
            }
        });
    </script>
</body>

</html>