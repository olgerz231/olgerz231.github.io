<?php
// Iniciar sesión
session_start();

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

// Obtener información del usuario desde la sesión
$nombre_usuario = $_SESSION['usuario'];
$email_usuario = $_SESSION['email'] ?? '';
$id_usuario = $_SESSION['id'] ?? '';
$usuario_rol = isset($_SESSION['rol']) ? strtolower($_SESSION['rol']) : '';

// Verificar que el usuario tenga permisos de administrador
if ($usuario_rol !== 'administrador') {
    $_SESSION['error'] = "Acceso denegado. Requiere privilegios de administrador.";
    header("Location: index.php");
    exit();
}

// Configuración de la base de datos
$host = 'localhost';
$dbname = 'attendsync';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Procesar formulario de agregar estudiante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_student') {
    $cedula = trim($_POST['cedula']);
    $primer_apellido = trim($_POST['primer_apellido']);
    $segundo_apellido = trim($_POST['segundo_apellido']);
    $nombre = trim($_POST['nombre']);
    $grupo_id = intval($_POST['grupo_id']);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT id FROM estudiantes WHERE cedula = ?");
        $stmt->execute([$cedula]);
        
        if ($stmt->rowCount() > 0) {
            $error_message = "Ya existe un estudiante con esa cédula";
        } else {
            $stmt = $pdo->prepare("INSERT INTO estudiantes (cedula, primer_apellido, segundo_apellido, nombre) VALUES (?, ?, ?, ?)");
            $stmt->execute([$cedula, $primer_apellido, $segundo_apellido, $nombre]);
            $estudiante_id = $pdo->lastInsertId();
            
            if ($grupo_id > 0) {
                $stmt = $pdo->prepare("INSERT INTO grupo_estudiante (grupo_id, estudiante_id) VALUES (?, ?)");
                $stmt->execute([$grupo_id, $estudiante_id]);
            }
            
            $pdo->commit();
            $success_message = "Estudiante agregado correctamente";
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "Error al agregar estudiante: " . $e->getMessage();
    }
}

// Procesar eliminación de estudiante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $estudiante_id = intval($_POST['estudiante_id']);
    
    try {
        $stmt = $pdo->prepare("DELETE FROM estudiantes WHERE id = ?");
        $stmt->execute([$estudiante_id]);
        $success_message = "Estudiante eliminado correctamente";
    } catch(PDOException $e) {
        $error_message = "Error al eliminar estudiante: " . $e->getMessage();
    }
}

// Obtener lista de grupos para los formularios
$grupos = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM grupos ORDER BY nombre");
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Si no hay grupos, continuamos sin error, los desplegables estarán vacíos
}

// Función para obtener estudiantes con filtros
function getStudents($pdo, $search = '', $grupo_filter = '') {
    $sql = "SELECT e.id, e.cedula, e.primer_apellido, e.segundo_apellido, e.nombre, 
                    GROUP_CONCAT(DISTINCT g.nombre ORDER BY g.nombre SEPARATOR ', ') as grupos
            FROM estudiantes e
            LEFT JOIN grupo_estudiante ge ON e.id = ge.estudiante_id
            LEFT JOIN grupos g ON ge.grupo_id = g.id";
    
    $params = [];
    $where_conditions = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(e.cedula LIKE ? OR e.nombre LIKE ? OR e.primer_apellido LIKE ? OR e.segundo_apellido LIKE ?)";
        $search_param = "%$search%";
        array_push($params, $search_param, $search_param, $search_param, $search_param);
    }
    
    if (!empty($grupo_filter)) {
        $where_conditions[] = "e.id IN (SELECT ge_filter.estudiante_id FROM grupo_estudiante ge_filter WHERE ge_filter.grupo_id = ?)";
        $params[] = $grupo_filter;
    }
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $sql .= " GROUP BY e.id ORDER BY e.primer_apellido, e.segundo_apellido, e.nombre";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener estudiantes para la tabla
$search = $_GET['search'] ?? '';
$grupo_filter = $_GET['grupo_filter'] ?? '';
$students = getStudents($pdo, $search, $grupo_filter);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Estudiantes - AttendSync</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Montserrat", sans-serif; }
        :root { --primary-color: #3ab397; --secondary-color: #3aa8ad; --background-color: #f0f4f3; --text-color: #333; --white: #ffffff; --border-color: #e1e5eb; --error-color: #c62828; --success-color: #2e7d32; --warning-color: #f9a825; }
        body { background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: linear-gradient(180deg, var(--primary-color), #2e9e87); color: white; padding: 25px 0; box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1); position: fixed; height: 100vh; z-index: 100; }
        .sidebar-header { padding: 0 20px 25px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.15); margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .sidebar-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; font-weight: 700; }
        .sidebar-btn { display: flex; align-items: center; gap: 10px; padding: 12px 20px; background: none; border: none; color: #ecf0f1; text-align: left; cursor: pointer; font-size: 16px; transition: all 0.3s; border-left: 4px solid transparent; width: 100%; text-decoration: none; }
        .sidebar-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-btn.active { background: rgba(255, 255, 255, 0.15); border-left: 4px solid white; font-weight: bold; }
        .main-content { flex: 1; margin-left: 280px; padding: 20px; }
        .admin-header { display: flex; justify-content: flex-end; align-items: center; gap: 15px; margin-bottom: 20px; width: 100%; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem; }
        .logout-btn { background-color: var(--secondary-color); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 500; transition: background-color 0.3s; }
        .logout-btn:hover { background-color: #2a8a7a; }
        .container { max-width: 1200px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1 { color: var(--primary-color); margin-bottom: 20px; font-size: 1.8rem; }
        .card { background-color: white; border-radius: 8px; padding: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); border-top: 4px solid var(--primary-color); margin-bottom: 20px; }
        .card h2 { color: var(--primary-color); margin-bottom: 15px; font-size: 1.3rem; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color); font-size: 0.95rem; }
        .form-control { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; transition: all 0.3s; background-color: var(--white); }
        .form-control:focus { border-color: var(--secondary-color); outline: none; box-shadow: 0 0 0 3px rgba(58, 168, 173, 0.15); }
        .form-select { appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233aa8ad' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 15px center; background-size: 15px; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        .data-table { width: 100%; border-collapse: collapse; margin: 25px 0; font-size: 0.9em; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .data-table thead tr { background-color: var(--primary-color); color: white; text-align: left; }
        .data-table th, .data-table td { padding: 12px 15px; }
        .data-table tbody tr { border-bottom: 1px solid var(--border-color); }
        .data-table tbody tr:nth-of-type(even) { background-color: #f9f9f9; }
        .data-table tbody tr:hover { background-color: #f1f1f1; }
        .search-container { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .search-input { flex: 0 0 250px; }
        .filter-group { display: flex; align-items: center; gap: 10px; }
        .filter-label { font-weight: 500; font-size: 0.95rem; white-space: nowrap; }
        .btn { padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: 500; transition: all 0.3s; border: none; font-size: 0.9em; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: #2e9e87; }
        .action-buttons { display: flex; gap: 8px; justify-content: flex-start; }
        .view-btn { padding: 8px 15px; font-size: 0.85rem; background-color: var(--secondary-color); color: white; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s; }
        .view-btn:hover { background-color: #2a8a7a; }
        .delete-btn { padding: 8px 15px; font-size: 0.85rem; background-color: var(--error-color); color: white; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s; }
        .delete-btn:hover { background-color: #c0392b; }
        .alert { padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); overflow-y: auto; }
        .modal-content { background-color: var(--white); margin: 5% auto; padding: 25px; border-radius: 10px; box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3); width: 80%; max-width: 700px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border-color); }
        .modal-title { margin: 0; color: var(--primary-color); font-size: 1.5rem; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-color); transition: color 0.3s; }
        .close-btn:hover { color: var(--error-color); }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <h2>AttendSync</h2>
    </div>
    <a href="panel_administrador.php" class="sidebar-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span>Panel Principal</span>
    </a>
    <a href="usuarios.php" class="sidebar-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Usuarios</span>
    </a>
    <a href="gestion_grupos.php" class="sidebar-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
        <span>Gestión de Grupos</span>
    </a>
    <a href="gestion_estudiantes.php" class="sidebar-btn active">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Gestión de Estudiantes</span>
    </a>
    <a href="reportes.php" class="sidebar-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        <span>Reportes</span>
    </a>
    <a href="ajustes_admin.php" class="sidebar-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12-.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
        <span>Ajustes y Reportes</span>
    </a>
</div>

<div class="main-content">
    <div class="admin-header">
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($nombre_usuario, 0, 1) . substr($email_usuario, 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
            <form action="logout.php" method="post"><button type="submit" class="logout-btn">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="container">
        <h1>Gestión de Estudiantes</h1>
        <?php if (isset($success_message)): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>
        <?php if (isset($error_message)): ?><div class="alert alert-error"><?php echo $error_message; ?></div><?php endif; ?>

        <div class="card">
            <h2>Agregar Nuevo Estudiante</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_student">
                <div class="form-row">
                    <div class="form-group"><label for="cedula">Cédula:</label><input type="text" id="cedula" name="cedula" class="form-control" required></div>
                    <div class="form-group"><label for="nombre">Nombre:</label><input type="text" id="nombre" name="nombre" class="form-control" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="primer_apellido">Primer Apellido:</label><input type="text" id="primer_apellido" name="primer_apellido" class="form-control" required></div>
                    <div class="form-group"><label for="segundo_apellido">Segundo Apellido:</label><input type="text" id="segundo_apellido" name="segundo_apellido" class="form-control"></div>
                </div>
                <div class="form-group">
                    <label for="grupo_id">Asignar a Grupo (opcional):</label>
                    <select id="grupo_id" name="grupo_id" class="form-control form-select">
                        <option value="">Seleccionar grupo</option>
                        <?php foreach ($grupos as $grupo): ?>
                            <option value="<?php echo $grupo['id']; ?>"><?php echo htmlspecialchars($grupo['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Agregar Estudiante</button>
            </form>
        </div>

        <div class="card">
            <h2>Lista de Estudiantes</h2>
            <div class="search-container">
                <form method="GET" style="display: flex; gap: 15px; align-items: center; width: 100%;">
                    <div class="search-input"><input type="text" name="search" class="form-control" placeholder="Buscar por cédula o nombre..." value="<?php echo htmlspecialchars($search); ?>"></div>
                    <div class="filter-group">
                        <span class="filter-label">Grupo:</span>
                        <select name="grupo_filter" class="form-control form-select">
                            <option value="">Todos los grupos</option>
                            <?php foreach ($grupos as $grupo): ?>
                                <option value="<?php echo $grupo['id']; ?>" <?php echo ($grupo_filter == $grupo['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grupo['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Buscar</button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>Cédula</th><th>Apellidos</th><th>Nombre</th><th>Grupos</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="5" style="text-align: center;">No se encontraron estudiantes</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['cedula']); ?></td>
                                    <td><?php echo htmlspecialchars(trim($student['primer_apellido'] . ' ' . $student['segundo_apellido'])); ?></td>
                                    <td><?php echo htmlspecialchars($student['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($student['grupos'] ?? 'Sin grupo'); ?></td>
                                    <td class="action-buttons">
                                        <button class="view-btn" onclick="viewStudent(<?php echo $student['id']; ?>)">Ver Asistencia</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de eliminar este estudiante? Se eliminarán todos sus registros de asistencia.')">
                                            <input type="hidden" name="action" value="delete_student">
                                            <input type="hidden" name="estudiante_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" class="delete-btn">Eliminar</button>
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

<div id="attendance-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-title-student">Asistencia del Estudiante</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div id="modal-body">
            <div id="attendance-summary" style="margin-bottom: 20px;">
                <h4>Porcentaje de Asistencia: <span id="attendance-percentage" style="font-weight: 600;">0%</span></h4>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="attendance-table-content">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            <p id="no-attendance-message" style="text-align: center; font-style: italic; color: #888; display: none;">No hay registros de asistencia para este estudiante.</p>
        </div>
    </div>
</div>

<script>
// --- Código para el Modal y la Asistencia (CORREGIDO) ---

const attendanceModal = document.getElementById('attendance-modal');
const modalTitle = document.getElementById('modal-title-student');
const attendanceTableBody = document.querySelector('#attendance-table-content tbody');
const attendancePercentageSpan = document.getElementById('attendance-percentage');
const noAttendanceMessage = document.getElementById('no-attendance-message');

function viewStudent(studentId) {
    attendanceTableBody.innerHTML = '';
    noAttendanceMessage.style.display = 'none';
    attendancePercentageSpan.textContent = 'Cargando...';
    modalTitle.textContent = 'Asistencia del Estudiante';
    attendanceModal.style.display = 'block';

    fetch('get_attendance.php?id_estudiante=' + studentId)
        .then(response => {
            if (!response.ok) {
                throw new Error('La respuesta de la red no fue exitosa.');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                attendanceTableBody.innerHTML = `<tr><td colspan="2" style="text-align: center; color: var(--error-color);">${data.error}</td></tr>`;
                attendancePercentageSpan.textContent = 'N/A';
            } else {
                modalTitle.textContent = `Asistencia de ${data.nombre_completo}`;

                if (data.registros.length === 0) {
                    noAttendanceMessage.style.display = 'block';
                    attendancePercentageSpan.textContent = '0%'; // Sin registros, 0%
                } else {
                    let presentCount = 0;
                    let lateCount = 0;
                    const totalRecords = data.registros.length;

                    data.registros.forEach(record => {
                        const row = document.createElement('tr');
                        let statusColor = 'var(--text-color)'; // Color por defecto

                        // Asignar color según el estado
                        switch(record.estado) {
                            case 'Presente':    statusColor = 'var(--success-color)'; break;
                            case 'Tardía':      statusColor = 'var(--warning-color)'; break;
                            case 'Ausente':     statusColor = 'var(--error-color)'; break;
                            case 'Justificado': statusColor = 'var(--secondary-color)'; break;
                        }

                        row.innerHTML = `
                            <td>${record.fecha}</td>
                            <td style="font-weight: bold; color: ${statusColor};">${record.estado}</td>
                        `;
                        attendanceTableBody.appendChild(row);

                        // Contar para el cálculo del porcentaje
                        if (record.estado_code === 'P') {
                            presentCount++;
                        } else if (record.estado_code === 'T') {
                            lateCount++;
                        }
                    });

                    // Calcular y mostrar porcentaje
                    // Política: (Presentes + Tardías) / Total de clases registradas.
                    if (totalRecords > 0) {
                        const attendedClasses = presentCount + lateCount;
                        const percentage = ((attendedClasses / totalRecords) * 100).toFixed(1);
                        attendancePercentageSpan.textContent = `${percentage}%`;
                    } else {
                        attendancePercentageSpan.textContent = `0%`;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error al obtener la asistencia:', error);
            attendanceTableBody.innerHTML = '<tr><td colspan="2" style="text-align: center; color: var(--error-color);">Error al cargar los datos.</td></tr>';
            attendancePercentageSpan.textContent = 'N/A';
        });
}

function closeModal() {
    attendanceModal.style.display = 'none';
}

window.addEventListener('click', (event) => {
    if (event.target === attendanceModal) {
        closeModal();
    }
});

// Script para la activación del menú lateral
document.addEventListener('DOMContentLoaded', () => {
    const currentPage = '<?php echo basename($_SERVER['PHP_SELF']); ?>';
    const menuLinks = document.querySelectorAll('.sidebar-btn');
    
    menuLinks.forEach(link => {
        if (link.getAttribute('href') === currentPage) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
});
</script>

</body>
</html>