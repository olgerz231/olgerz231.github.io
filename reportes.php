
<?php
session_start();

// ======================
// VERIFICACIÓN DE SESIÓN Y ROL
// ======================
if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit();
}

$rol_usuario = isset($_SESSION['rol']) ? strtolower($_SESSION['rol']) : '';
$allowed_roles = ['profesor', 'administrador'];

if (!in_array($rol_usuario, $allowed_roles)) {
    $_SESSION['error'] = "Acceso denegado. Tu rol ($rol_usuario) no tiene permiso para esta sección.";
    header("Location: ../index.php");
    exit();
}

$nombre_usuario = $_SESSION['usuario'];
$id_usuario = $_SESSION['id'] ?? '';
$apellidos_usuario = $_SESSION['apellidos'] ?? ''; // Asumiendo que esta variable existe en tu sesión

// ======================
// CONEXIÓN A LA BASE DE DATOS
// ======================
$host = '127.0.0.1';
$dbname = 'attendsync';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// ===================================
// NUEVO: LÓGICA PARA DESCARGAR PDF (ACTUALIZADO)
// ===================================
if (isset($_GET['action']) && $_GET['action'] == 'download_pdf' && isset($_GET['id'])) {
    
    require('fpdf.php');

    $id_reporte = (int)$_GET['id'];

    // Obtener los datos del reporte, incluyendo la nueva fecha_reporte
    $stmt = $pdo->prepare("
        SELECT r.nombre, r.contenido, r.fecha_creacion, r.fecha_reporte, u.nombres, u.apellidos 
        FROM reportes r 
        JOIN usuarios u ON r.autor_id = u.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$id_reporte]);
    $reporte = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reporte) {
        die("Reporte no encontrado.");
    }

    class PDF extends FPDF
    {
        function Header()
        {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'AttendSync - Reporte del Sistema', 0, 1, 'C');
            $this->Ln(5);
        }

        function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }
    }

    $pdf = new PDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, utf8_decode($reporte['nombre']), 0, 1, 'L');
    $pdf->Ln(5);

    // Metadatos del reporte (con fecha_reporte)
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 7, 'Autor: ' . utf8_decode($reporte['nombres'] . ' ' . $reporte['apellidos']), 0, 1);
    if (!empty($reporte['fecha_reporte'])) {
         $pdf->Cell(0, 7, 'Fecha del Reporte: ' . date('d/m/Y', strtotime($reporte['fecha_reporte'])), 0, 1);
    }
    $pdf->Cell(0, 7, 'Fecha de creacion: ' . date('d/m/Y H:i', strtotime($reporte['fecha_creacion'])), 0, 1);
    $pdf->Ln(10);

    // Contenido del reporte
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 10, utf8_decode($reporte['contenido']));
    
    $pdf->Output('D', 'Reporte_AttendSync_' . $id_reporte . '.pdf');
    exit;
}

// ======================
// PROCESAMIENTO DE FORMULARIOS (ACTUALIZADO)
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_reporte'])) {
        try {
            // Se inserta la nueva fecha_reporte en la base de datos
            $stmt = $pdo->prepare("INSERT INTO reportes (nombre, tipo, fecha_reporte, contenido, autor_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['titulo_reporte'] ?? 'Sin título',
                $_POST['tipo_reporte'] ?? 'general',
                $_POST['fecha_reporte'] ?: null, // Guardar fecha del reporte
                $_POST['descripcion_reporte'] ?? '',
                $id_usuario
            ]);
            $_SESSION['mensaje'] = "Reporte guardado correctamente";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al guardar el reporte: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['eliminar_reporte'])) {
        try {
            $id_eliminar = (int)$_POST['id_reporte'];
            if ($rol_usuario == 'administrador') {
                $stmt = $pdo->prepare("DELETE FROM reportes WHERE id = ?");
                $stmt->execute([$id_eliminar]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM reportes WHERE id = ? AND autor_id = ?");
                $stmt->execute([$id_eliminar, $id_usuario]);
            }
            if ($stmt->rowCount() > 0) {
                $_SESSION['mensaje'] = "Reporte eliminado correctamente";
            } else {
                $_SESSION['error'] = "No se pudo eliminar el reporte. Verifica tus permisos.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error al eliminar el reporte: " . $e->getMessage();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ======================
// OBTENER REPORTES DE LA BASE DE DATOS
// ======================
try {
    $stmt = $pdo->prepare("
        SELECT r.*, u.nombres, u.apellidos 
        FROM reportes r 
        JOIN usuarios u ON r.autor_id = u.id 
        ORDER BY r.fecha_creacion DESC
    ");
    $stmt->execute();
    $reportes_guardados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error al cargar los reportes: " . $e->getMessage();
    $reportes_guardados = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Reportes - AttendSync</title>
    <style>
        /* ESTILOS CSS (Sin cambios respecto a la versión anterior) */
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Montserrat", sans-serif; }
        :root {
            --primary-color: #3ab397; --secondary-color: #3aa8ad; --background-color: #f0f4f3;
            --text-color: #333; --white: #ffffff; --border-color: #e1e5eb;
            --error-color: #d32f2f; --success-color: #2e7d32; --info-color: #1976d2;
        }
        body { background-color: var(--background-color); color: var(--text-color); display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px; background: linear-gradient(180deg, var(--primary-color), #2e9e87); color: white;
            padding: 25px 0; box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1); position: fixed; height: 100vh; z-index: 100;
        }
        .sidebar-header {
            padding: 0 20px 25px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.15); margin-bottom: 25px;
            display: flex; align-items: center; gap: 10px;
        }
        .sidebar-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; font-weight: 700; }
        .sidebar-btn {
            display: flex; align-items: center; gap: 10px; padding: 12px 20px; background: none; border: none;
            color: #ecf0f1; text-align: left; cursor: pointer; font-size: 16px; transition: all 0.3s;
            border-left: 4px solid transparent; width: 100%; text-decoration: none;
        }
        .sidebar-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-btn.active { background: rgba(255, 255, 255, 0.15); border-left: 4px solid white; font-weight: bold; }
        .sidebar-btn svg { fill: white; width: 20px; height: 20px; }
        .main-content { flex: 1; margin-left: 280px; padding: 20px; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-avatar {
            width: 42px; height: 42px; border-radius: 50%; background-color: var(--secondary-color);
            display: flex; align-items: center; justify-content: center; color: white; font-weight: 500; font-size: 1.1rem;
        }
        .logout-btn {
            background-color: var(--secondary-color); color: white; border: none; padding: 8px 16px;
            border-radius: 4px; cursor: pointer; font-weight: 500; transition: background-color 0.3s;
        }
        .logout-btn:hover { background-color: #2a8a7a; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: var(--primary-color); margin-bottom: 25px; font-size: 1.8rem; }
        .card {
            background-color: white; border-radius: 8px; padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08); border-top: 4px solid var(--primary-color);
            margin-bottom: 30px;
        }
        .card h2 { color: var(--primary-color); margin-bottom: 20px; font-size: 1.3rem; }
        .form-row { display: flex; flex-wrap: wrap; gap: 20px; }
        .form-group { flex: 1; min-width: 200px; margin-bottom: 20px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #555; }
        .form-control {
            width: 100%; padding: 12px 15px; border: 1px solid var(--border-color);
            border-radius: 8px; font-family: "Montserrat", sans-serif; font-size: 14px;
        }
        .form-control:focus {
            border-color: var(--secondary-color); outline: none; box-shadow: 0 0 0 3px rgba(58, 168, 173, 0.15);
        }
        textarea.form-control { min-height: 120px; resize: vertical; }
        .btn {
            padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer;
            font-weight: 500; font-size: 15px; transition: background-color 0.3s;
            text-decoration: none; display: inline-block;
        }
        .btn-primary { background-color: var(--secondary-color); color: white; }
        .btn-primary:hover { background-color: #2e8e94; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background-color: #f9f9f9; font-weight: 600; color: var(--primary-color); }
        tr:hover { background-color: rgba(58, 179, 151, 0.05); }
        td .badge {
            display: inline-block; padding: 4px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; color: white;
            background-color: var(--primary-color);
        }
        .actions-cell { display: flex; gap: 8px; align-items: center; }
        .action-btn {
            padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; border: none;
            color: white; transition: opacity 0.3s; text-decoration: none; display: inline-flex; align-items: center;
        }
        .action-btn.view-btn { background-color: var(--secondary-color); }
        .action-btn.download-btn { background-color: var(--info-color); }
        .action-btn.delete-btn { background-color: var(--error-color); }
        .action-btn:hover { opacity: 0.85; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal.visible { display: flex; }
        .modal-content { background-color: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 650px; max-height: 85vh; overflow-y: auto; position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 15px; }
        .close-btn { font-size: 28px; font-weight: bold; cursor: pointer; background: none; border: none; }
        #reporteModalContent { white-space: pre-wrap; word-wrap: break-word; line-height: 1.6; }
        .success-message, .error-message { color: white; padding: 15px; border-radius: 5px; margin-bottom: 25px; border-left: 4px solid; }
        .success-message { background-color: var(--success-color); border-color: #1b5e20; }
        .error-message { background-color: var(--error-color); border-color: #b71c1c; }
    </style>
</head>

<body>
    <?php
    $current_page = basename($_SERVER['PHP_SELF']);
    $nombres_usuario = $_SESSION['nombres'] ?? 'Administrador';
    $apellidos_usuario = $_SESSION['apellidos'] ?? '';
    ?>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>AttendSync</h2>
        </div>
        <a href="panel_administrador.php" class="sidebar-btn <?php echo ($current_page == 'panel_administrador.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
            <span>Panel Principal</span>
        </a>
        <a href="usuarios.php" class="sidebar-btn <?php echo ($current_page == 'usuarios.php') ? 'active' : ''; ?>">
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
            <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12-.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
            <span>Ajustes y Reportes</span>
        </a>
    </div>

    <div class="main-content">
        <div class="admin-header">
            <h1>Gestión de Reportes</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($nombres_usuario, 0, 1) . substr($apellidos_usuario, 0, 1)); ?></div>
                <span><?php echo htmlspecialchars($nombres_usuario); ?></span>
                <form action="../logout.php" method="post" style="margin:0;">
                    <button type="submit" class="logout-btn">Cerrar sesión</button>
                </form>
            </div>
        </div>

        <div class="container">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['mensaje'])): ?>
                <div class="success-message"><?php echo htmlspecialchars($_SESSION['mensaje']); unset($_SESSION['mensaje']); ?></div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Crear Nuevo Reporte</h2>
                <form method="POST" action="reportes.php">
                    <div class="form-group">
                        <label for="titulo_reporte">Título del Reporte</label>
                        <input type="text" id="titulo_reporte" name="titulo_reporte" class="form-control" required placeholder="Ej: Reporte de Ausentismo Mensual">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tipo_reporte">Tipo de Reporte</label>
                            <select id="tipo_reporte" name="tipo_reporte" class="form-control" required>
                                <option value="">Seleccione un tipo...</option>
                                <option value="asistencia">Asistencia</option>
                                <option value="evaluacion">Evaluación</option>
                                <option value="administrativo">Administrativo</option>
                                <option value="general">General</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fecha_reporte">Fecha del Reporte</label>
                            <input type="date" id="fecha_reporte" name="fecha_reporte" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="descripcion_reporte">Descripción</label>
                        <textarea id="descripcion_reporte" name="descripcion_reporte" class="form-control" required placeholder="Describa el propósito y contenido del reporte..."></textarea>
                    </div>
                    <button type="submit" name="guardar_reporte" class="btn btn-primary">Guardar Reporte</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Reportes Guardados (<?php echo count($reportes_guardados); ?>)</h2>
                <?php if (empty($reportes_guardados)): ?>
                    <p>No hay reportes guardados aún.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Título</th>
                                    <th>Tipo</th>
                                    <th>Fecha del Reporte</th>
                                    <th>Autor</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportes_guardados as $reporte): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reporte['nombre']); ?></td>
                                        <td><span class="badge"><?php echo ucfirst(htmlspecialchars($reporte['tipo'])); ?></span></td>
                                        <td><?php echo !empty($reporte['fecha_reporte']) ? date('d/m/Y', strtotime($reporte['fecha_reporte'])) : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($reporte['nombres'] . ' ' . $reporte['apellidos']); ?></td>
                                        <td class="actions-cell">
                                            <button class="action-btn view-btn" onclick='verReporte(<?php echo json_encode($reporte, JSON_HEX_APOS); ?>)'>Ver</button>
                                            <a href="?action=download_pdf&id=<?php echo $reporte['id']; ?>" class="action-btn download-btn">PDF</a>
                                            <?php if ($rol_usuario == 'administrador' || $reporte['autor_id'] == $id_usuario): ?>
                                                <form method="POST" action="reportes.php" style="display: inline;" onsubmit="return confirm('¿Estás seguro de que quieres eliminar este reporte?')">
                                                    <input type="hidden" name="id_reporte" value="<?php echo $reporte['id']; ?>">
                                                    <button type="submit" name="eliminar_reporte" class="action-btn delete-btn">Eliminar</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="reporteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="reporteModalTitle">Título del Reporte</h2>
                <button class="close-btn">&times;</button>
            </div>
            <div id="reporteModalContent">Contenido del reporte...</div>
        </div>
    </div>
    
<script>
document.addEventListener('DOMContentLoaded', () => {
    // ======== LÓGICA DEL MODAL (SIN CAMBIOS) ========
    const modal = document.getElementById('reporteModal');
    const modalTitle = document.getElementById('reporteModalTitle');
    const modalContent = document.getElementById('reporteModalContent');
    const closeBtn = modal.querySelector('.close-btn');

    window.verReporte = function(reporte) {
        modalTitle.textContent = reporte.nombre;
        
        // Construir el contenido del modal, incluyendo la fecha del reporte
        let content = `Fecha del Reporte: ${reporte.fecha_reporte ? new Date(reporte.fecha_reporte + 'T00:00:00').toLocaleDateString() : 'No especificada'}\n\n`;
        content += reporte.contenido;
        modalContent.textContent = content;
        
        modal.classList.add('visible');
    }

    function closeModal() {
        modal.classList.remove('visible');
    }

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    
    // ======== ASIGNAR FECHA ACTUAL AL CAMPO DE FECHA ========
    // new Date().toISOString().split('T')[0] es una forma segura de obtener YYYY-MM-DD
    const fechaReporteInput = document.getElementById('fecha_reporte');
    if(fechaReporteInput) {
        fechaReporteInput.value = new Date().toISOString().split('T')[0];
    }
    
    // ======== LÓGICA PARA OCULTAR MENSAJES (SIN CAMBIOS) ========
    const successMessage = document.querySelector('.success-message');
    const errorMessage = document.querySelector('.error-message');

    if (successMessage) { setTimeout(() => { successMessage.style.display = 'none'; }, 5000); }
    if (errorMessage) { setTimeout(() => { errorMessage.style.display = 'none'; }, 5000); }
});
</script>

</body>
</html>
```