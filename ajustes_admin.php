<?php
session_start();

// 1. VERIFICACIÓN DE ROL Y CONEXIÓN A LA BASE DE DATOS
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'administrador') {
    if(isset($_SESSION['usuario'])){
        $_SESSION['user_id'] = $_SESSION['id'];
    } else {
        header("Location: login.php");
        exit();
    }
}

// Configuración de conexión a la base de datos
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendsync');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Error en el sistema. Por favor intente más tarde.");
}

$mensaje = '';
$error = '';

// ==================================================================
// INICIO: DEFINICIÓN DE FUNCIONES PARA EL REPORTE
// ==================================================================

if (!function_exists('obtenerTodosLosGrupos')) {
    function obtenerTodosLosGrupos($pdo)
    {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre FROM grupos ORDER BY nombre ASC");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error al obtener todos los grupos: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('calcularNotaAsistenciaGrupo')) {
    function calcularNotaAsistenciaGrupo($pdo, $estudiante_id, $grupo_id)
    {
        if (!$estudiante_id || !$grupo_id) return 'N/A';
        try {
            $stmt = $pdo->prepare("SELECT estado FROM asistencias WHERE estudiante_id = :estudiante_id AND grupo_id = :grupo_id");
            $stmt->execute(['estudiante_id' => $estudiante_id, 'grupo_id' => $grupo_id]);
            $registros = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $total_clases = count($registros);
            if ($total_clases === 0) return 'N/A';

            $counts = array_count_values($registros);
            $tardias = $counts['T'] ?? 0;
            $ausentes = $counts['A'] ?? 0;

            $ausencias_efectivas = $ausentes + floor($tardias / 2);
            $total_dias_contabilizables = $total_clases;

            if ($total_dias_contabilizables === 0) {
                return '10.0';
            }
            
            $porcentaje = (($total_dias_contabilizables - $ausencias_efectivas) / $total_dias_contabilizables) * 100;
            
            $nota = max(0, $porcentaje / 10);
            return number_format($nota, 1);

        } catch (PDOException $e) {
            error_log("Error al calcular nota de asistencia: " . $e->getMessage());
            return 'Error';
        }
    }
}

if (!function_exists('obtenerEstudiantesPorGrupo')) {
    function obtenerEstudiantesPorGrupo($pdo, $grupo_id)
    {
        if (!$grupo_id) return [];
        try {
            $sql = "SELECT e.id, e.cedula, e.primer_apellido, e.segundo_apellido, e.nombre 
                    FROM estudiantes e JOIN grupo_estudiante ge ON e.id = ge.estudiante_id
                    WHERE ge.grupo_id = :grupo_id ORDER BY e.primer_apellido, e.segundo_apellido, e.nombre";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['grupo_id' => $grupo_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error al obtener estudiantes: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('generarSemanasDelMes')) {
    function generarSemanasDelMes($mes, $ano)
    {
        $semanas = [];
        $dias_en_mes = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);

        for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
            $timestamp = mktime(0, 0, 0, $mes, $dia, $ano);
            $dia_semana_num = (int)date('N', $timestamp);
            if ($dia_semana_num < 6) { // Solo Lunes a Viernes
                $semana_del_mes = (int)date('W', $timestamp) - (int)date('W', strtotime("$ano-$mes-01")) + 1;
                if (!isset($semanas[$semana_del_mes])) {
                    $semanas[$semana_del_mes] = [];
                }
                $semanas[$semana_del_mes][] = $dia;
            }
        }
        return $semanas;
    }
}

if (!function_exists('obtenerReporteMensual')) {
    function obtenerReporteMensual($pdo, $grupo_id, $mes, $ano)
    {
        if (!$grupo_id) return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];

        $estudiantes = obtenerEstudiantesPorGrupo($pdo, $grupo_id);
        if (empty($estudiantes)) return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];

        $stmt_grupo = $pdo->prepare("SELECT nombre FROM grupos WHERE id = :id");
        $stmt_grupo->execute(['id' => $grupo_id]);
        $nombre_grupo = $stmt_grupo->fetchColumn();

        try {
            $stmt = $pdo->prepare("SELECT estudiante_id, DAY(fecha) as dia, estado FROM asistencias WHERE grupo_id = :grupo_id AND MONTH(fecha) = :mes AND YEAR(fecha) = :ano");
            $stmt->execute(['grupo_id' => $grupo_id, 'mes' => $mes, 'ano' => $ano]);
            $asistencias_mes = $stmt->fetchAll();

            $reporte = [];
            foreach ($estudiantes as $est) {
                $reporte[$est['id']] = ['info' => $est, 'asistencias' => []];
            }

            foreach ($asistencias_mes as $asistencia) {
                if (isset($reporte[$asistencia['estudiante_id']])) {
                    $reporte[$asistencia['estudiante_id']]['asistencias'][$asistencia['dia']] = $asistencia['estado'];
                }
            }
            
            foreach ($reporte as $id => &$data) {
                 $data['nota_mes'] = calcularNotaAsistenciaGrupo($pdo, $id, $grupo_id);
            }

            $estructura_semanal = generarSemanasDelMes($mes, $ano);
            return ["estudiantes" => $reporte, "semanas" => $estructura_semanal, "nombre_grupo" => $nombre_grupo];
        } catch (PDOException $e) {
            error_log("Error al generar reporte mensual: " . $e->getMessage());
            return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];
        }
    }
}

// ==================================================================
// INICIO: LÓGICA PARA PROCESAR ACCIONES DE LA PÁGINA
// ==================================================================

// ---- Lógica para la exportación a PDF (del reporte mensual) ----
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require('fpdf/fpdf.php');
    $mes_pdf = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
    $ano_pdf = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT);
    $grupo_id_pdf = filter_input(INPUT_GET, 'grupo_id_reporte', FILTER_VALIDATE_INT);

    if (!$mes_pdf || !$ano_pdf || !$grupo_id_pdf) die("Error: Faltan parámetros para generar el PDF.");
    
    $reporte_pdf = obtenerReporteMensual($pdo, $grupo_id_pdf, $mes_pdf, $ano_pdf);
    if (empty($reporte_pdf['estudiantes'])) die("No hay datos para generar este reporte en PDF.");

    class PDF extends FPDF {
        private $grupoNombre = ''; private $periodo = '';
        function setReportHeader($grupo, $periodo) { $this->grupoNombre = $grupo; $this->periodo = $periodo; }
        function Header() { $this->SetFont('Arial', 'B', 14); $this->Cell(0, 10, 'AttendSync - Reporte Mensual de Asistencia', 0, 1, 'C'); $this->SetFont('Arial', '', 12); $this->Cell(0, 8, utf8_decode('Grupo: ' . $this->grupoNombre), 0, 1, 'C'); $this->Cell(0, 8, utf8_decode('Período: ' . $this->periodo), 0, 1, 'C'); $this->Ln(5); }
        function Footer() { $this->SetY(-15); $this->SetFont('Arial', 'I', 8); $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C'); }
    }
    
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    setlocale(LC_TIME, 'es_ES.UTF-8');
    $nombre_mes = ucfirst(strftime('%B', mktime(0, 0, 0, $mes_pdf, 1)));
    $pdf->setReportHeader($reporte_pdf['nombre_grupo'], $nombre_mes . ' de ' . $ano_pdf);
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 8);

    $pdf->SetFillColor(58, 179, 151); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 8); $pdf->SetLineWidth(0.2);
    $y_pos_inicio = $pdf->GetY();
    $pdf->Cell(20, 10, utf8_decode('Cédula'), 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Primer Apellido', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Segundo Apellido', 1, 0, 'C', true);
    $pdf->Cell(20, 10, 'Nombre', 1, 0, 'C', true);
    $x_pos_semanas = $pdf->GetX();
    foreach ($reporte_pdf['semanas'] as $num_semana => $dias) { if (empty($dias)) continue; $ancho_semana = count($dias) * 8; $pdf->Cell($ancho_semana, 5, 'Semana ' . $num_semana, 1, 0, 'C', true); }
    $x_pos_nota = $pdf->GetX();
    $pdf->SetXY($x_pos_semanas, $y_pos_inicio + 5);
    foreach ($reporte_pdf['semanas'] as $num_semana => $dias) { if (empty($dias)) continue; foreach ($dias as $dia) { $pdf->Cell(8, 5, $dia, 1, 0, 'C', true); } }
    $pdf->SetXY($x_pos_nota, $y_pos_inicio);
    $pdf->Cell(15, 10, 'Nota Final', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(0, 0, 0);
    foreach ($reporte_pdf['estudiantes'] as $data) {
        $pdf->Cell(20, 6, $data['info']['cedula'], 1, 0, 'L');
        $pdf->Cell(25, 6, utf8_decode($data['info']['primer_apellido']), 1, 0, 'L');
        $pdf->Cell(25, 6, utf8_decode($data['info']['segundo_apellido']), 1, 0, 'L');
        $pdf->Cell(20, 6, utf8_decode($data['info']['nombre']), 1, 0, 'L');
        foreach ($reporte_pdf['semanas'] as $dias) {
            if (empty($dias)) continue;
            foreach ($dias as $dia) {
                $estado = $data['asistencias'][$dia] ?? '-'; $fill = false;
                switch ($estado) {
                    case 'P': $pdf->SetFillColor(212, 237, 218); $fill = true; break;
                    case 'A': $pdf->SetFillColor(248, 215, 218); $fill = true; break;
                    case 'T': $pdf->SetFillColor(255, 243, 205); $fill = true; break;
                    case 'J': $pdf->SetFillColor(204, 229, 255); $fill = true; break;
                }
                $pdf->Cell(8, 6, $estado, 1, 0, 'C', $fill);
            }
        }
        $pdf->Cell(15, 6, $data['nota_mes'], 1, 1, 'C');
    }
    
    $nombre_archivo = "Reporte_Asistencia_" . $reporte_pdf['nombre_grupo'] . "_" . $mes_pdf . "-" . $ano_pdf . ".pdf";
    $pdf->Output('D', $nombre_archivo);
    exit();
}


// ---- Lógica para guardar las fechas del período (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_fechas'])) {
    $fechas = [
        'fecha_inicio_semestre_1' => $_POST['fecha_inicio_semestre_1'] ?? null,
        'fecha_fin_semestre_1'    => $_POST['fecha_fin_semestre_1'] ?? null,
        'fecha_inicio_semestre_2' => $_POST['fecha_inicio_semestre_2'] ?? null,
        'fecha_fin_semestre_2'    => $_POST['fecha_fin_semestre_2'] ?? null,
    ];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE configuracion SET setting_value = :valor WHERE setting_key = :clave");
        foreach ($fechas as $clave => $valor) {
            $stmt->execute(['valor' => !empty($valor) ? $valor : null, 'clave' => $clave]);
        }
        $pdo->commit();
        $_SESSION['mensaje'] = "Fechas del período actualizadas correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error al actualizar las fechas: " . $e->getMessage();
    }
    header("Location: ajustes_admin.php");
    exit();
}

// ---- Lógica para obtener datos para mostrar en la página (GET) ----

// Datos para el formulario de Ajustes
$stmt_fechas = $pdo->query("SELECT setting_key, setting_value FROM configuracion");
$settings_raw = $stmt_fechas->fetchAll(PDO::FETCH_KEY_PAIR);
$fechas_actuales = [
    'fecha_inicio_semestre_1' => $settings_raw['fecha_inicio_semestre_1'] ?? '',
    'fecha_fin_semestre_1'    => $settings_raw['fecha_fin_semestre_1'] ?? '',
    'fecha_inicio_semestre_2' => $settings_raw['fecha_inicio_semestre_2'] ?? '',
    'fecha_fin_semestre_2'    => $settings_raw['fecha_fin_semestre_2'] ?? '',
];

// Datos para el reporte de asistencia
$grupos_todos = obtenerTodosLosGrupos($pdo);
$mes_reporte = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?? date('m');
$ano_reporte = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?? date('Y');
$grupo_id_reporte = filter_input(INPUT_GET, 'grupo_id_reporte', FILTER_VALIDATE_INT);
$reporte_mensual = ["estudiantes" => [], "semanas" => []];

if ($grupo_id_reporte) {
    $reporte_mensual = obtenerReporteMensual($pdo, $grupo_id_reporte, $mes_reporte, $ano_reporte);
}

// Datos del usuario administrador
$admin_id = $_SESSION['user_id'];
$stmt_admin = $pdo->prepare("SELECT nombres, apellidos FROM usuarios WHERE id = ?");
$stmt_admin->execute([$admin_id]);
$admin = $stmt_admin->fetch();

// Mensajes de sesión
if(isset($_SESSION['mensaje'])){
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}
if(isset($_SESSION['error'])){
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

setlocale(LC_TIME, 'es_ES.UTF-8', 'Spanish_Spain.1252');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes y Reportes - AttendSync</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Montserrat", sans-serif; }
        :root {
            --primary-color: #3ab397; --secondary-color: #3aa8ad; --background-color: #f0f4f3;
            --text-color: #333; --white: #ffffff; --border-color: #e1e5eb;
            --error-color: #d32f2f; --success-color: #2e7d32; --info-color: #1976d2;
            --presente-bg: #d4edda; --presente-color: #155724;
            --ausente-bg: #f8d7da; --ausente-color: #721c24;
            --tardia-bg: #fff3cd; --tardia-color: #856404;
            --justificada-bg: #cce5ff; --justificada-color: #004085;
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
        .sidebar-header h2 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .sidebar-btn {
            display: flex; align-items: center; gap: 10px; padding: 12px 20px;
            color: #ecf0f1; cursor: pointer; font-size: 16px; transition: all 0.3s;
            border-left: 4px solid transparent; width: 100%; text-decoration: none;
        }
        .sidebar-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-btn.active { background: rgba(255, 255, 255, 0.15); border-left: 4px solid white; font-weight: bold; }
        .sidebar-btn svg { fill: white; width: 20px; height: 20px; }
        .main-content { flex: 1; margin-left: 280px; padding: 20px; }
        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .main-header h1 { color: var(--primary-color); font-size: 1.8rem; margin: 0; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-avatar {
            width: 42px; height: 42px; border-radius: 50%; background-color: var(--secondary-color);
            display: flex; align-items: center; justify-content: center; color: white; font-weight: 500; font-size: 1.1rem;
        }
        .btn-logout {
            background-color: var(--secondary-color); color: white; border: none; padding: 8px 16px;
            border-radius: 4px; cursor: pointer; font-weight: 500; transition: background-color 0.3s;
            text-decoration: none;
        }
        .btn-logout:hover { background-color: #2a8a7a; }
        .container { background:white; border-radius: 8px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 30px;}
        .container h2 { color: var(--primary-color); margin-bottom: 20px; font-size: 1.3rem; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;}
        .form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:25px; }
        .form-group { display:flex; flex-direction:column; gap:5px; margin-bottom: 15px;}
        .form-group label { font-weight:500; color:#555; font-size:14px; }
        .form-group input, .form-group select { padding:10px 12px; border:1px solid var(--border-color); border-radius:5px; font-family:"Montserrat",sans-serif; font-size:15px; }
        .btn { padding:10px 20px; border-radius:5px; cursor:pointer; font-weight:500; transition:all 0.3s; border:none; font-size:1em; background-color:var(--primary-color); color:white; text-decoration:none; height:fit-content; }
        .btn:hover { background-color:var(--primary-dark); }
        .alert { padding:15px; border-radius:5px; margin-bottom:25px; border-left:5px solid; color: white; }
        .alert-success { background-color:var(--success-color); border-color: #1b5e20; }
        .alert-error { background-color:var(--error-color); border-color: #b71c1c; }
        .filters-bar { display: flex; gap: 20px; align-items: flex-end; margin-bottom: 25px; flex-wrap: wrap; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background-color: #f9f9f9; font-weight: 600; color: var(--primary-color); vertical-align: middle; }
        tr:hover { background-color: rgba(58, 179, 151, 0.05); }
        .status-cell { font-weight: bold; text-align: center; }
        .status-cell.P { background-color: var(--presente-bg); color: var(--presente-color); }
        .status-cell.A { background-color: var(--ausente-bg); color: var(--ausente-color); }
        .status-cell.T { background-color: var(--tardia-bg); color: var(--tardia-color); }
        .status-cell.J { background-color: var(--justificada-bg); color: var(--justificada-color); }
        .nota { font-weight: bold; text-align: center; }
        .week-divider { border-right: 2px solid var(--secondary-color) !important; }
    </style>
</head>
<body>
    
    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
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
            <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
            <span>Ajustes y Reportes</span>
        </a>
    </div>

    <div class="main-content">
        <div class="main-header">
            <h1>Panel de Administrador</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo htmlspecialchars(substr($admin['nombres'], 0, 1) . substr($admin['apellidos'], 0, 1)); ?></div>
                <span>Admin: <?php echo htmlspecialchars($admin['nombres'] . ' ' . $admin['apellidos']); ?></span>
                <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>

        <?php if ($mensaje): ?><div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="container">
            <h2>Ajustes de Período Académico</h2>
            <form method="POST" action="ajustes_admin.php">
                <input type="hidden" name="guardar_fechas" value="1">
                <p style="margin-bottom: 20px; color: #555;">Establezca las fechas de inicio y fin para cada semestre.</p>
                <div class="form-grid">
                    <fieldset style="border:1px solid #e1e5eb; padding: 20px; border-radius: 5px;">
                        <legend style="padding: 0 10px; font-weight: 600; color: var(--primary-color);">Primer Semestre</legend>
                        <div class="form-group">
                            <label for="fecha_inicio_semestre_1">Fecha de Inicio</label>
                            <input type="date" id="fecha_inicio_semestre_1" name="fecha_inicio_semestre_1" value="<?php echo htmlspecialchars($fechas_actuales['fecha_inicio_semestre_1']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="fecha_fin_semestre_1">Fecha de Fin</label>
                            <input type="date" id="fecha_fin_semestre_1" name="fecha_fin_semestre_1" value="<?php echo htmlspecialchars($fechas_actuales['fecha_fin_semestre_1']); ?>">
                        </div>
                    </fieldset>
                    <fieldset style="border:1px solid #e1e5eb; padding: 20px; border-radius: 5px;">
                        <legend style="padding: 0 10px; font-weight: 600; color: var(--primary-color);">Segundo Semestre</legend>
                        <div class="form-group">
                            <label for="fecha_inicio_semestre_2">Fecha de Inicio</label>
                            <input type="date" id="fecha_inicio_semestre_2" name="fecha_inicio_semestre_2" value="<?php echo htmlspecialchars($fechas_actuales['fecha_inicio_semestre_2']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="fecha_fin_semestre_2">Fecha de Fin</label>
                            <input type="date" id="fecha_fin_semestre_2" name="fecha_fin_semestre_2" value="<?php echo htmlspecialchars($fechas_actuales['fecha_fin_semestre_2']); ?>">
                        </div>
                    </fieldset>
                </div>
                <div style="text-align: right; margin-top: 25px;">
                    <button type="submit" class="btn">Guardar Cambios de Fechas</button>
                </div>
            </form>
        </div>

        <div class="container">
            <h2>Reporte de Asistencia Mensual por Grupo</h2>
            <form id="form-mensual" method="GET" action="ajustes_admin.php#form-mensual">
                <div class="filters-bar">
                    <div class="form-group">
                        <label for="mes_reporte">Mes</label>
                        <select id="mes_reporte" name="mes"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>" <?php echo ($mes_reporte == $m) ? 'selected' : ''; ?>><?php echo ucfirst(strftime('%B', mktime(0, 0, 0, $m, 1))); ?></option><?php endfor; ?></select>
                    </div>
                    <div class="form-group">
                        <label for="ano_reporte">Año</label>
                        <input type="number" id="ano_reporte" name="ano" value="<?php echo htmlspecialchars($ano_reporte); ?>" min="2020" max="2050">
                    </div>
                    <div class="form-group">
                        <label for="grupo_id_reporte">Grupo/Sección</label>
                        <select id="grupo_id_reporte" name="grupo_id_reporte">
                                <option value="">-- Seleccione un grupo --</option>
                            <?php foreach ($grupos_todos as $grupo): ?>
                                <option value="<?php echo $grupo['id']; ?>" <?php echo ($grupo_id_reporte == $grupo['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grupo['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn">Generar Reporte</button>
                    <?php if (!empty($reporte_mensual['estudiantes'])): ?>
                        <a href="?export=pdf&mes=<?php echo $mes_reporte; ?>&ano=<?php echo $ano_reporte; ?>&grupo_id_reporte=<?php echo $grupo_id_reporte; ?>" class="btn" style="background-color: #c0392b;">Exportar a PDF</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!empty($reporte_mensual['estudiantes'])): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th rowspan="2">Cédula</th>
                                <th rowspan="2">Primer Apellido</th>
                                <th rowspan="2">Segundo Apellido</th>
                                <th rowspan="2">Nombre</th>
                                <?php foreach ($reporte_mensual['semanas'] as $num_semana => $dias): if (empty($dias)) continue; ?>
                                    <th colspan="<?php echo count($dias); ?>" style="text-align: center;">Semana <?php echo $num_semana; ?></th>
                                <?php endforeach; ?>
                                <th rowspan="2" style="text-align: center;">Nota Acumulada</th>
                            </tr>
                            <tr>
                                <?php foreach ($reporte_mensual['semanas'] as $num_semana => $dias): if (empty($dias)) continue;
                                    $ultimo_dia_de_semana = end($dias);
                                    foreach ($dias as $dia):
                                        $clase_divisora = ($dia == $ultimo_dia_de_semana && $num_semana < count($reporte_mensual['semanas'])) ? 'class="week-divider"' : '';
                                ?>
                                    <th <?php echo $clase_divisora; ?> style="min-width: 40px; text-align: center;"><?php echo $dia; ?></th>
                                <?php endforeach; endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporte_mensual['estudiantes'] as $est_id => $data): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($data['info']['cedula']); ?></td>
                                    <td><?php echo htmlspecialchars($data['info']['primer_apellido']); ?></td>
                                    <td><?php echo htmlspecialchars($data['info']['segundo_apellido']); ?></td>
                                    <td><?php echo htmlspecialchars($data['info']['nombre']); ?></td>
                                    <?php foreach ($reporte_mensual['semanas'] as $num_semana => $dias): if (empty($dias)) continue;
                                        $ultimo_dia_de_semana = end($dias);
                                        foreach ($dias as $dia):
                                            $estado = $data['asistencias'][$dia] ?? '-';
                                            $clase_divisora = ($dia == $ultimo_dia_de_semana && $num_semana < count($reporte_mensual['semanas'])) ? 'week-divider' : '';
                                    ?>
                                        <td class="status-cell <?php echo htmlspecialchars($estado); ?> <?php echo $clase_divisora; ?>"><?php echo htmlspecialchars($estado); ?></td>
                                    <?php endforeach; endforeach; ?>
                                    <td class="nota"><?php echo htmlspecialchars($data['nota_mes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif($grupo_id_reporte): ?>
                <p>No se encontraron datos de asistencia para generar el reporte con los filtros seleccionados.</p>
            <?php else: ?>
                <p>Por favor, seleccione un grupo y un período para generar el reporte de asistencia.</p>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>