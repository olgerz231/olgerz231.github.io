<?php
session_start();

// 1. CONEXIÓN A LA BASE DE DATOS (PDO UNIFICADO)
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendsync');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Error de conexión: " . $e->getMessage());
    die("Error en el sistema. Por favor intente más tarde.");
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['profesor_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: login.php");
    exit();
}

// Obtener información del profesor
$profesor_id = $_SESSION['profesor_id'];
$stmt_prof = $pdo->prepare("SELECT nombres AS nombre, apellidos AS apellido FROM usuarios WHERE id = ? AND rol = 'profesor'");
$stmt_prof->execute([$profesor_id]);
$profesor = $stmt_prof->fetch();

if (!$profesor) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Procesar búsqueda si se envió el formulario
$resultados = [];
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['buscar'])) {
    $cedula = $_POST['cedula'];
    $fecha = $_POST['fecha'];

    // 1. Obtener los datos del estudiante por cédula
    $sql_estudiante = "SELECT id, nombre, primer_apellido, segundo_apellido, cedula FROM estudiantes WHERE cedula = ?";
    $stmt_est = $pdo->prepare($sql_estudiante);
    $stmt_est->execute([$cedula]);
    $estudiante_info = $stmt_est->fetch();

    if ($estudiante_info) {
        // 2. Si el estudiante existe, buscar todas sus asistencias para esa fecha
        $sql_asistencias = "SELECT hora_bloque, estado, justificacion 
                            FROM asistencias 
                            WHERE estudiante_id = ? AND fecha = ?
                            ORDER BY CAST(SUBSTRING(hora_bloque, 2) AS UNSIGNED)";
        $stmt_asis = $pdo->prepare($sql_asistencias);
        $stmt_asis->execute([$estudiante_info['id'], $fecha]);
        $asistencias_dia = $stmt_asis->fetchAll();
        
        // 3. Preparar los resultados para mostrarlos
        $resultados = $estudiante_info;
        $resultados['asistencias'] = $asistencias_dia;
        $resultados['fecha_consulta'] = $fecha;
    }
}

// Define la página actual para la barra lateral
$currentPage = 'consulta';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Asistencia - AttendSync</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap");
        :root {
            --primary-color: #3ab397;
            --secondary-color: #3aa8ad;
            --background-color: #f0f4f3;
            --text-color: #333;
            --card-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            --border-radius: 8px;
            --border-color: #e1e5eb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Montserrat", sans-serif; background-color: var(--background-color); display: flex; min-height: 100vh; color: var(--text-color); line-height: 1.6; }
        .sidebar { width: 220px; background-color: var(--primary-color); padding: 20px 0; height: 100vh; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 0 25px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.15); margin-bottom: 25px; }
        .sidebar-header h2 { color: white; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; margin: 0; font-weight: 600; }
        .sidebar-header svg { fill: white; }
        .sidebar-btn { padding: 12px 25px; background: none; border: none; color: white; text-align: left; cursor: pointer; font-size: 15px; transition: all 0.3s; border-left: 4px solid transparent; text-decoration: none; display: flex; align-items: center; gap: 12px; margin: 2px 0; }
        .sidebar-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-btn.active { background: rgba(255, 255, 255, 0.15); border-left: 4px solid white; font-weight: 500; }
        .sidebar-btn svg { width: 20px; height: 20px; fill: white; }
        .main-content { flex: 1; margin-left: 220px; padding: 30px; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        h1 { color: var(--primary-color); font-size: 2rem; font-weight: 700; margin: 0; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-avatar { width: 48px; height: 48px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 18px; }
        .user-info span { font-weight: 600; color: var(--text-color); font-size: 1.1em; }
        .btn { padding: 10px 18px; border-radius: var(--border-radius); cursor: pointer; font-weight: 600; transition: all 0.3s ease; border: none; font-size: 1em; text-decoration: none; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: #2e9e87; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background-color: white; border-radius: 8px; padding: 25px; box-shadow: var(--card-shadow); border-top: 4px solid var(--primary-color); margin-bottom: 25px; }
        .card h2 { color: var(--primary-color); margin-bottom: 20px; font-size: 1.3rem; font-weight: 600; }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 250px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #555; }
        .form-control { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 8px; font-family: "Montserrat", sans-serif; font-size: 14px; }
        .result-card { background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin-top: 20px; }
        .result-content { background-color: white; padding: 20px; border-radius: 4px; border-left: 4px solid var(--primary-color); }
        .result-item { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #eee; display: flex; flex-wrap: wrap; align-items: center; }
        .result-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .result-label { font-weight: 600; color: #555; display: inline-block; width: 200px; }
        .result-value { color: #333; }
        .leccion-tag { background-color: var(--secondary-color); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8em; font-weight: 600; margin-right: 10px; }
        @media (max-width: 768px) {
            .sidebar { width: 80px; }
            .sidebar-header h2 span, .sidebar-btn span { display: none; }
            .sidebar-btn { justify-content: center; padding: 12px 5px; }
            .main-content { margin-left: 80px; padding: 20px; }
            h1 { font-size: 1.6rem; }
        }
    </style>
</head>

<body>
    
    <?php include 'sidebar_profesor.php'; ?>

    <div class="main-content">
        <div class="admin-header">
            <h1>Consulta de Asistencia</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo htmlspecialchars(substr($profesor['nombre'], 0, 1) . substr($profesor['apellido'], 0, 1)); ?>
                </div>
                <span>
                    Prof. <?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?>
                </span>
                <a href="logout.php" class="btn btn-primary" style="margin-left: 15px;">Cerrar Sesión</a>
            </div>
        </div>

        <div class="container">
            <div class="card">
                <h2>Buscar Estudiante</h2>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="consulta-fecha">Fecha:</label>
                            <input type="date" id="consulta-fecha" name="fecha" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="consulta-cedula">Cédula del Estudiante:</label>
                            <input type="text" id="consulta-cedula" name="cedula" placeholder="Ingrese la cédula" class="form-control" required>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" name="buscar" class="btn btn-primary">Buscar</button>
                    </div>
                </form>
            </div>

            <?php if ($_SERVER["REQUEST_METHOD"] == "POST") : ?>
                <div class="card">
                    <h2>Resultados de la Consulta</h2>
                    <div class="result-card">
                        <div class="result-content">
                            <?php if (!empty($resultados)) : ?>
                                <div class="result-item">
                                    <span class="result-label">Estudiante:</span>
                                    <span class="result-value">
                                        <?php echo htmlspecialchars($resultados['nombre'] . ' ' . $resultados['primer_apellido'] . ($resultados['segundo_apellido'] ? ' ' . $resultados['segundo_apellido'] : '')); ?>
                                    </span>
                                </div>
                                <div class="result-item">
                                    <span class="result-label">Cédula:</span>
                                    <span class="result-value"><?php echo htmlspecialchars($resultados['cedula']); ?></span>
                                </div>
                                <div class="result-item">
                                    <span class="result-label">Fecha:</span>
                                    <span class="result-value"><?php echo date('d/m/Y', strtotime($resultados['fecha_consulta'])); ?></span>
                                </div>
                                
                                <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

                                <?php if (!empty($resultados['asistencias'])) : ?>
                                    <?php foreach ($resultados['asistencias'] as $asistencia_leccion) :
                                        $leccion_display = '';
                                        $hora_bloque = $asistencia_leccion['hora_bloque'];

                                        if (strpos($hora_bloque, 'L') === 0) {
                                            $leccion_num = htmlspecialchars(substr($hora_bloque, 1));
                                            $display_label = "Lección " . $leccion_num;
                                        } elseif (strpos($hora_bloque, ':') !== false) {
                                            $display_label = 'Horario Antiguo (' . htmlspecialchars($hora_bloque) . ')';
                                        } else {
                                            $display_label = 'Registro (' . htmlspecialchars($hora_bloque) . ')';
                                        }

                                        $estado_texto = '';
                                        switch (strtoupper($asistencia_leccion['estado'])) {
                                            case 'P': $estado_texto = 'Presente'; break;
                                            case 'A': $estado_texto = 'Ausente'; break;
                                            case 'T': $estado_texto = 'Tardía'; break;
                                            case 'J': $estado_texto = 'Justificada'; break;
                                            default: $estado_texto = 'Desconocido';
                                        }
                                    ?>
                                    <div class="result-item">
                                        <span class="result-label"><span class="leccion-tag"><?php echo $display_label; ?></span></span>
                                        <span class="result-value"><?php echo $estado_texto; ?></span>
                                    </div>
                                    <?php if (strtoupper($asistencia_leccion['estado']) === 'J' && !empty($asistencia_leccion['justificacion'])) : ?>
                                        <div class="result-item" style="padding-left: 20px;">
                                            <span class="result-label" style="color: #777;"><em>Razón:</em></span>
                                            <span class="result-value"><?php echo htmlspecialchars($asistencia_leccion['justificacion']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="result-item">
                                        <span class="result-value">No se encontraron registros de asistencia para este estudiante en la fecha seleccionada.</span>
                                    </div>
                                <?php endif; ?>
                                
                            <?php else : ?>
                                <p>No se encontró ningún estudiante con la cédula proporcionada.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>