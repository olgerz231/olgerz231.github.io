<?php
session_start();

// 1. CONFIGURACIÓN DE LA BASE DE DATOS Y CONEXIÓN PDO
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

// ------------------------------------------------------------------
// NUEVO: CONSTANTE PARA LAS LECCIONES DEL DÍA
// ------------------------------------------------------------------
define('LECCIONES_DEL_DIA', range(1, 12));

// ------------------------------------------------------------------
// OBTENER FECHAS DE SEMESTRES CONFIGURADAS POR EL ADMIN
// ------------------------------------------------------------------
$stmt_fechas = $pdo->query("SELECT setting_key, setting_value FROM configuracion");
$settings_raw = $stmt_fechas->fetchAll(PDO::FETCH_KEY_PAIR);
$fechas_semestres = [
    'fecha_inicio_semestre_1' => $settings_raw['fecha_inicio_semestre_1'] ?? null,
    'fecha_fin_semestre_1'    => $settings_raw['fecha_fin_semestre_1'] ?? null,
    'fecha_inicio_semestre_2' => $settings_raw['fecha_inicio_semestre_2'] ?? null,
    'fecha_fin_semestre_2'    => $settings_raw['fecha_fin_semestre_2'] ?? null,
];


// ==================================================================
// BLOQUE DE FUNCIONES
// ==================================================================

function obtenerGruposPorProfesor($pdo, $profesor_id)
{
    if (!$profesor_id) return [];
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM grupos WHERE profesor_id = :profesor_id ORDER BY nombre ASC");
        $stmt->execute(['profesor_id' => $profesor_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error al obtener grupos del profesor: " . $e->getMessage());
        return [];
    }
}

function calcularNotaAsistenciaGrupo($pdo, $estudiante_id, $grupo_id, $fechas_semestres)
{
    if (!$estudiante_id || !$grupo_id) return 'N/A';

    $hoy = date('Y-m-d');
    $fecha_inicio = null;
    $fecha_fin = null;

    if ($fechas_semestres['fecha_inicio_semestre_1'] && $fechas_semestres['fecha_fin_semestre_1'] && $hoy >= $fechas_semestres['fecha_inicio_semestre_1'] && $hoy <= $fechas_semestres['fecha_fin_semestre_1']) {
        $fecha_inicio = $fechas_semestres['fecha_inicio_semestre_1'];
        $fecha_fin = $fechas_semestres['fecha_fin_semestre_1'];
    } elseif ($fechas_semestres['fecha_inicio_semestre_2'] && $fechas_semestres['fecha_fin_semestre_2'] && $hoy >= $fechas_semestres['fecha_inicio_semestre_2'] && $hoy <= $fechas_semestres['fecha_fin_semestre_2']) {
        $fecha_inicio = $fechas_semestres['fecha_inicio_semestre_2'];
        $fecha_fin = $fechas_semestres['fecha_fin_semestre_2'];
    }

    try {
        $sql = "SELECT estado FROM asistencias WHERE estudiante_id = :estudiante_id AND grupo_id = :grupo_id";
        $params = ['estudiante_id' => $estudiante_id, 'grupo_id' => $grupo_id];

        if ($fecha_inicio && $fecha_fin) {
            $sql .= " AND fecha BETWEEN :fecha_inicio AND :fecha_fin";
            $params['fecha_inicio'] = $fecha_inicio;
            $params['fecha_fin'] = $fecha_fin;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registros = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $total_clases = count($registros);
        if ($total_clases === 0) return 'N/A';

        $counts = array_count_values($registros);
        $tardias = $counts['T'] ?? 0;
        $ausentes = $counts['A'] ?? 0;

        $ausencias_efectivas = $ausentes + ($tardias / 2);
        $total_dias_contabilizables = $total_clases;

        if ($total_dias_contabilizables === 0) return '10.0';

        $porcentaje = (($total_dias_contabilizables - $ausencias_efectivas) / $total_dias_contabilizables) * 100;
        $nota = max(0, $porcentaje / 10);

        return number_format($nota, 1);
    } catch (PDOException $e) {
        error_log("Error al calcular nota de asistencia: " . $e->getMessage());
        return 'Error';
    }
}

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

function obtenerAsistenciasDiarias($pdo, $grupo_id, $fecha, $hora_bloque)
{
    if (!$grupo_id || !$hora_bloque) return [];
    try {
        $stmt = $pdo->prepare("SELECT estudiante_id, estado, justificacion FROM asistencias WHERE grupo_id = :grupo_id AND fecha = :fecha AND hora_bloque = :hora_bloque");
        $stmt->execute(['grupo_id' => $grupo_id, 'fecha' => $fecha, 'hora_bloque' => $hora_bloque]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $asistencias = [];
        foreach ($resultados as $row) {
            $asistencias[$row['estudiante_id']] = [
                'estado' => $row['estado'],
                'justificacion' => $row['justificacion']
            ];
        }
        return $asistencias;
    } catch (PDOException $e) {
        error_log("Error al obtener asistencias diarias: " . $e->getMessage());
        return [];
    }
}

function generarSemanasDelMes($mes, $ano)
{
    $semanas = [];
    $dias_en_mes = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);

    for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
        $timestamp = mktime(0, 0, 0, $mes, $dia, $ano);
        $dia_semana_num = (int)date('N', $timestamp);
        if ($dia_semana_num < 6) { // Solo Lunes a Viernes
            $semana_del_mes = (int)date('W', $timestamp) - (int)date('W', strtotime("$ano-$mes-01")) + 1;
             if ($semana_del_mes < 1) { // Corrección para semanas de fin/inicio de año
                $semana_del_mes += 52;
            }
            if (!isset($semanas[$semana_del_mes])) {
                $semanas[$semana_del_mes] = [];
            }
            $semanas[$semana_del_mes][] = $dia;
        }
    }
    ksort($semanas);
    return $semanas;
}

function obtenerReporteMensual($pdo, $grupo_id, $mes, $ano, $fechas_semestres)
{
    if (!$grupo_id) return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];

    $estudiantes = obtenerEstudiantesPorGrupo($pdo, $grupo_id);
    if (empty($estudiantes)) return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];

    $stmt_grupo = $pdo->prepare("SELECT nombre FROM grupos WHERE id = :id");
    $stmt_grupo->execute(['id' => $grupo_id]);
    $nombre_grupo = $stmt_grupo->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT estudiante_id, DAY(fecha) as dia, hora_bloque, estado FROM asistencias WHERE grupo_id = :grupo_id AND MONTH(fecha) = :mes AND YEAR(fecha) = :ano");
        $stmt->execute(['grupo_id' => $grupo_id, 'mes' => $mes, 'ano' => $ano]);
        $asistencias_mes = $stmt->fetchAll();

        $reporte = [];
        foreach ($estudiantes as $est) {
            $reporte[$est['id']] = ['info' => $est, 'asistencias' => []];
        }

        foreach ($asistencias_mes as $asistencia) {
            if (isset($reporte[$asistencia['estudiante_id']])) {
                if (!isset($reporte[$asistencia['estudiante_id']]['asistencias'][$asistencia['dia']])) {
                    $reporte[$asistencia['estudiante_id']]['asistencias'][$asistencia['dia']] = [];
                }
                $reporte[$asistencia['estudiante_id']]['asistencias'][$asistencia['dia']][$asistencia['hora_bloque']] = $asistencia['estado'];
            }
        }

        foreach ($reporte as $id => &$data) {
            $data['nota_mes'] = calcularNotaAsistenciaGrupo($pdo, $id, $grupo_id, $fechas_semestres);
        }

        $estructura_semanal = generarSemanasDelMes($mes, $ano);
        return ["estudiantes" => $reporte, "semanas" => $estructura_semanal, "nombre_grupo" => $nombre_grupo];
    } catch (PDOException $e) {
        error_log("Error al generar reporte mensual: " . $e->getMessage());
        return ["estudiantes" => [], "semanas" => [], "nombre_grupo" => ""];
    }
}

// ==================================================================
// BLOQUE DE GENERACIÓN DE PDF CON PAGINACIÓN POR SEMANA
// ==================================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require('fpdf/fpdf.php');

    $mes_pdf = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
    $ano_pdf = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT);
    $grupo_id_pdf = filter_input(INPUT_GET, 'grupo_id_reporte', FILTER_VALIDATE_INT);

    if (!$mes_pdf || !$ano_pdf || !$grupo_id_pdf) {
        die("Error: Faltan parámetros para generar el PDF.");
    }

    $reporte_pdf = obtenerReporteMensual($pdo, $grupo_id_pdf, $mes_pdf, $ano_pdf, $fechas_semestres);

    if (empty($reporte_pdf['estudiantes'])) {
        die("No hay datos para generar este reporte en PDF.");
    }

    class PDF extends FPDF
    {
        private $grupoNombre = '';
        private $periodo = '';
        private $semanaActual = '';

        function setReportHeader($grupo, $periodo) {
            $this->grupoNombre = $grupo;
            $this->periodo = $periodo;
        }

        function setSemanaActual($semana) {
            $this->semanaActual = $semana;
        }

        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 8, utf8_decode('AttendSync - Reporte Mensual de Asistencia'), 0, 1, 'C');
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 7, utf8_decode('Grupo: ' . $this->grupoNombre), 0, 1, 'C');
            $this->Cell(0, 7, utf8_decode('Período: ' . $this->periodo), 0, 1, 'C');
            if ($this->semanaActual) {
                 $this->SetFont('Arial', 'B', 12);
                 $this->Cell(0, 7, utf8_decode('Semana ' . $this->semanaActual), 0, 1, 'C');
            }
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }
    }

    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    setlocale(LC_TIME, 'es_ES.UTF-8');
    $nombre_mes = ucfirst(strftime('%B', mktime(0, 0, 0, $mes_pdf, 1)));
    $pdf->setReportHeader($reporte_pdf['nombre_grupo'], $nombre_mes . ' de ' . $ano_pdf);

    foreach ($reporte_pdf['semanas'] as $num_semana => $dias_en_semana) {
        if (empty($dias_en_semana)) continue;

        $pdf->setSemanaActual($num_semana);
        $pdf->AddPage();
        
        $pdf->SetFillColor(58, 179, 151);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetLineWidth(0.2);
        
        $ancho_cedula = 22;
        $ancho_estudiante = 50;
        $ancho_leccion = 3;
        
        $ancho_dia = count(LECCIONES_DEL_DIA) * $ancho_leccion;
        
        $pdf->Cell($ancho_cedula, 10, utf8_decode('Cédula'), 1, 0, 'C', true);
        $pdf->Cell($ancho_estudiante, 10, 'Estudiante', 1, 0, 'C', true);
        $x_pos_dias = $pdf->GetX();
        foreach ($dias_en_semana as $dia) {
            $pdf->Cell($ancho_dia, 5, $dia, 1, 0, 'C', true);
        }
        $pdf->Ln(5);

        $pdf->SetX($x_pos_dias);
        $pdf->SetFont('Arial', 'B', 6);
        foreach ($dias_en_semana as $dia) {
            foreach (LECCIONES_DEL_DIA as $leccion) {
                $pdf->Cell($ancho_leccion, 5, $leccion, 1, 0, 'C', true);
            }
        }
        $pdf->Ln(5);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        foreach ($reporte_pdf['estudiantes'] as $data) {
            $pdf->Cell($ancho_cedula, 6, $data['info']['cedula'], 1, 0, 'L');
            $nombre_completo = $data['info']['primer_apellido'] . ' ' . $data['info']['nombre'];
            $pdf->Cell($ancho_estudiante, 6, utf8_decode($nombre_completo), 1, 0, 'L');
            
            foreach ($dias_en_semana as $dia) {
                foreach (LECCIONES_DEL_DIA as $leccion) {
                    $estado = $data['asistencias'][$dia]['L'.$leccion] ?? '-';
                    $fill = false;
                    switch ($estado) {
                        case 'P': $pdf->SetFillColor(212, 237, 218); $fill = true; break;
                        case 'A': $pdf->SetFillColor(248, 215, 218); $fill = true; break;
                        case 'T': $pdf->SetFillColor(255, 243, 205); $fill = true; break;
                        case 'J': $pdf->SetFillColor(204, 229, 255); $fill = true; break;
                    }
                    $pdf->Cell($ancho_leccion, 6, $estado, 1, 0, 'C', $fill);
                }
            }
            $pdf->Ln();
        }
    }

    $nombre_archivo = "Reporte_Asistencia_" . str_replace(' ', '_', $reporte_pdf['nombre_grupo']) . "_" . $mes_pdf . "-" . $ano_pdf . ".pdf";
    $pdf->Output('D', $nombre_archivo);
    exit();
}

// ==================================================================
// LÓGICA DE LA PÁGINA (POST Y GET)
// ==================================================================

if (!isset($_SESSION['profesor_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: login.php");
    exit();
}
$profesor_id = $_SESSION['profesor_id'];
$stmt_profesor = $pdo->prepare("SELECT nombres AS nombre, apellidos AS apellido FROM usuarios WHERE id = ? AND rol = 'profesor'");
$stmt_profesor->execute([$profesor_id]);
$profesor = $stmt_profesor->fetch();
if (!$profesor) {
    session_destroy();
    header("Location: login.php");
    exit();
}
$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_asistencia_diaria'])) {
        $fecha = filter_input(INPUT_POST, 'fecha', FILTER_SANITIZE_STRING);
        $grupo_id = filter_input(INPUT_POST, 'grupo_id', FILTER_VALIDATE_INT);
        $leccion_num = filter_input(INPUT_POST, 'leccion', FILTER_VALIDATE_INT);
        $asistencias = $_POST['asistencia'] ?? [];
        $justificaciones = $_POST['justificacion'] ?? [];

        try {
            if (!$grupo_id) throw new Exception("No se ha seleccionado un grupo válido.");
            if (!$leccion_num) throw new Exception("No se ha seleccionado una lección válida.");
            
            $hora_bloque_db = 'L' . $leccion_num;

            $pdo->beginTransaction();

            $stmt_delete = $pdo->prepare("DELETE FROM asistencias WHERE grupo_id = :grupo_id AND fecha = :fecha AND hora_bloque = :hora_bloque");
            $stmt_delete->execute(['grupo_id' => $grupo_id, 'fecha' => $fecha, 'hora_bloque' => $hora_bloque_db]);

            $stmt_insert = $pdo->prepare(
                "INSERT INTO asistencias (estudiante_id, grupo_id, fecha, hora_bloque, estado, justificacion) 
                 VALUES (:estudiante_id, :grupo_id, :fecha, :hora_bloque, :estado, :justificacion)"
            );

            foreach ($asistencias as $estudiante_id => $estado) {
                $estado_san = strtoupper(trim(filter_var($estado, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)));

                if (!empty($estado_san) && in_array($estado_san, ['P', 'A', 'T', 'J'])) {
                    $estudiante_id_san = filter_var($estudiante_id, FILTER_VALIDATE_INT);
                    if ($estudiante_id_san) {
                        $justificacion_comentario = null;
                        if ($estado_san === 'J') {
                            $justificacion_comentario = trim(filter_var($justificaciones[$estudiante_id] ?? '', FILTER_SANITIZE_STRING));
                        }
                        $stmt_insert->execute([
                            'estudiante_id' => $estudiante_id_san,
                            'grupo_id' => $grupo_id,
                            'fecha' => $fecha,
                            'hora_bloque' => $hora_bloque_db,
                            'estado' => $estado_san,
                            'justificacion' => $justificacion_comentario
                        ]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['mensaje'] = "Asistencia guardada correctamente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error al guardar: " . $e->getMessage();
        }

        header("Location: registros_profesor.php?tab=diaria&grupo_id=$grupo_id&fecha=$fecha&leccion=$leccion_num");
        exit();
    }
}

$tab_activa = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_STRING) ?? 'diaria';
$grupos_del_profesor = obtenerGruposPorProfesor($pdo, $profesor_id);
$fecha_diaria = filter_input(INPUT_GET, 'fecha', FILTER_SANITIZE_STRING) ?? date('Y-m-d');
$grupo_id_diario = filter_input(INPUT_GET, 'grupo_id', FILTER_VALIDATE_INT);
$leccion_diaria_num = filter_input(INPUT_GET, 'leccion', FILTER_VALIDATE_INT) ?? 1;
$hora_bloque_diario_db = 'L' . $leccion_diaria_num;
$estudiantes_diarios = [];
$asistencias_guardadas = [];

$semestre_actual_texto = "Fuera de período lectivo";
if ($fechas_semestres['fecha_inicio_semestre_1'] && $fechas_semestres['fecha_fin_semestre_1'] && $fecha_diaria >= $fechas_semestres['fecha_inicio_semestre_1'] && $fecha_diaria <= $fechas_semestres['fecha_fin_semestre_1']) {
    $semestre_actual_texto = "Primer Semestre";
} elseif ($fechas_semestres['fecha_inicio_semestre_2'] && $fechas_semestres['fecha_fin_semestre_2'] && $fecha_diaria >= $fechas_semestres['fecha_inicio_semestre_2'] && $fecha_diaria <= $fechas_semestres['fecha_fin_semestre_2']) {
    $semestre_actual_texto = "Segundo Semestre";
}

if ($grupo_id_diario) {
    $es_su_grupo = false;
    foreach ($grupos_del_profesor as $g) {
        if ($g['id'] == $grupo_id_diario) $es_su_grupo = true;
    }
    if ($es_su_grupo) {
        $estudiantes_diarios = obtenerEstudiantesPorGrupo($pdo, $grupo_id_diario);
        $asistencias_guardadas = obtenerAsistenciasDiarias($pdo, $grupo_id_diario, $fecha_diaria, $hora_bloque_diario_db);
    } else {
        $error = "Acceso no autorizado al grupo seleccionado.";
        $grupo_id_diario = null;
    }
}

$mes_reporte = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?? date('m');
$ano_reporte = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?? date('Y');
$grupo_id_reporte = filter_input(INPUT_GET, 'grupo_id_reporte', FILTER_VALIDATE_INT);
$reporte_mensual = ["estudiantes" => [], "semanas" => []];
if ($tab_activa === 'mensual' && $grupo_id_reporte) {
    $reporte_mensual = obtenerReporteMensual($pdo, $grupo_id_reporte, $mes_reporte, $ano_reporte, $fechas_semestres);
}

setlocale(LC_TIME, 'es_ES.UTF-8', 'Spanish_Spain.1252');
$currentPage = 'registro';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro y Consulta - AttendSync</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap");
        :root {
            --primary-color: #3ab397;
            --secondary-color: #3aa8ad;
            --background-color: #f0f4f3;
            --text-color: #333;
            --card-shadow: 0 4px 10px rgba(0,0,0,0.08);
            --border-radius: 8px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Montserrat", sans-serif; background-color: var(--background-color); display: flex; min-height: 100vh; color: var(--text-color); line-height: 1.6; }
        .sidebar { width: 220px; background-color: var(--primary-color); padding: 20px 0; height: 100vh; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-header { padding: 0 25px 25px; border-bottom: 1px solid rgba(255,255,255,0.15); margin-bottom: 25px; }
        .sidebar-header h2 { color: white; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; margin: 0; font-weight: 600; }
        .sidebar-header svg { fill: white; }
        .sidebar-btn { padding: 12px 25px; background: none; border: none; color: white; text-align: left; cursor: pointer; font-size: 15px; transition: all 0.3s; border-left: 4px solid transparent; text-decoration: none; display: flex; align-items: center; gap: 12px; margin: 2px 0; }
        .sidebar-btn:hover { background: rgba(255,255,255,0.1); }
        .sidebar-btn.active { background: rgba(255,255,255,0.15); border-left: 4px solid white; font-weight: 500; }
        .sidebar-btn svg { width: 20px; height: 20px; fill: white; }
        .main-content { flex: 1; margin-left: 220px; padding: 30px; }
        @media (max-width: 768px) {
            .sidebar { width: 80px; }
            .sidebar-header h2 span, .sidebar-btn span { display: none; }
            .sidebar-btn { justify-content: center; padding: 12px 5px; }
            .main-content { margin-left: 80px; padding: 20px; }
        }
        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; }
        .main-header h1 { color: var(--primary-color); font-size: 1.8rem; margin: 0; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 42px; height: 42px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: 500; }
        .btn-logout { text-decoration: none; padding: 8px 16px; background-color: var(--primary-color); color: white; border-radius: 5px; transition: background-color 0.3s; margin-left: 15px; }
        .container { background: white; border-radius: 8px; padding: 25px; box-shadow: var(--card-shadow); }
        .tabs { display: flex; border-bottom: 2px solid #e1e5eb; margin-bottom: 25px; }
        .tab-link { padding: 10px 20px; cursor: pointer; background: none; border: none; font-family: "Montserrat", sans-serif; font-size: 16px; font-weight: 500; color: #666; position: relative; transition: color 0.3s; }
        .tab-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 100%; height: 2px; background-color: var(--primary-color); transform: scaleX(0); transition: transform 0.3s; }
        .tab-link.active { color: var(--primary-color); }
        .tab-link.active::after { transform: scaleX(1); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .filters-bar { display: flex; gap: 20px; align-items: flex-end; margin-bottom: 25px; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-weight: 500; color: #555; font-size: 14px; }
        .form-group input, .form-group select { padding: 8px 12px; border: 1px solid #e1e5eb; border-radius: 5px; font-family: "Montserrat", sans-serif; }
        .btn { padding: 10px 16px; border-radius: 5px; cursor: pointer; font-weight: 500; transition: all 0.3s; border: none; font-size: 0.9em; background-color: var(--primary-color); color: white; text-decoration: none; height: fit-content; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e1e5eb; vertical-align: middle; }
        th { background-color: var(--primary-color); color: white; font-weight: 600; text-transform: uppercase; font-size: 11px; position: sticky; top: 0; z-index: 10; text-align: center;}
        .status-cell { text-align: center; font-weight: bold; font-size: 12px; }
        .status-cell.P { background-color: #d4edda; color: #155724; }
        .status-cell.A { background-color: #f8d7da; color: #721c24; }
        .status-cell.T { background-color: #fff3cd; color: #856404; }
        .status-cell.J { background-color: #cce5ff; color: #004085; }
        .attendance-input { width: 50px; padding: 6px; border: 2px solid #e1e5eb; border-radius: 20px; text-align: center; text-transform: uppercase; font-weight: bold; }
        .attendance-input.P { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .attendance-input.A { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .attendance-input.T { background-color: #fff3cd; color: #856404; border-color: #ffeeba; }
        .attendance-input.J { background-color: #cce5ff; color: #004085; border-color: #b8daff; }
        .alert { padding: 15px; border-radius: 5px; margin-bottom: 25px; border-left: 5px solid; }
        .alert-success { background-color: #d4edda; color: #155724; border-color: #28a745; }
        .alert-error { background-color: #f8d7da; color: #721c24; border-color: #dc3545; }
        .nota { font-weight: bold; text-align: center; }
        .info-badge { display: inline-block; padding: 8px 12px; border-radius: 5px; background-color: #e2f2ff; color: #0c5460; border: 1px solid #b8daff; font-weight: 500; font-size: 14px; text-align: center; }
        .justificacion-comentario { width: 100%; min-width: 180px; padding: 6px 10px; border: 1px solid #e1e5eb; border-radius: 5px; font-family: "Montserrat", sans-serif; }
        th.dia { background-color: #4a5c6a; font-size: 10px; }
        th.leccion { background-color: #6a7c8a; font-size: 9px; font-weight: normal; }
    </style>
</head>
<?php require_once 'sidebar_profesor.php'; ?>
    
    <div class="main-content">
        <div class="main-header">
            <h1>Registro y Consulta de Asistencia</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo htmlspecialchars(substr($profesor['nombre'], 0, 1) . substr($profesor['apellido'], 0, 1)); ?></div>
                <span>Prof. <?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></span>
                <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
        <div class="container">
            <?php if ($mensaje) : ?><div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>
            <?php if ($error) : ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <div class="tabs">
                <button class="tab-link <?php echo $tab_activa === 'diaria' ? 'active' : ''; ?>" data-tab="diaria">Tomar Asistencia Diaria</button>
                <button class="tab-link <?php echo $tab_activa === 'mensual' ? 'active' : ''; ?>" data-tab="mensual">Ver Reporte Mensual</button>
            </div>
            <div id="diaria" class="tab-content <?php echo $tab_activa === 'diaria' ? 'active' : ''; ?>">
                <form id="form-diaria" method="GET">
                    <input type="hidden" name="tab" value="diaria">
                    <div class="filters-bar">
                        <div class="form-group">
                            <label for="fecha_diaria">Fecha</label>
                            <input type="date" id="fecha_diaria" name="fecha" value="<?php echo htmlspecialchars($fecha_diaria); ?>">
                        </div>
                        <div class="form-group">
                            <label for="leccion_diaria">Lección</label>
                            <select id="leccion_diaria" name="leccion">
                                <?php foreach (LECCIONES_DEL_DIA as $leccion) : ?>
                                    <option value="<?php echo $leccion; ?>" <?php echo ($leccion_diaria_num == $leccion) ? 'selected' : ''; ?>>
                                        Lección <?php echo $leccion; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Período Lectivo</label>
                            <span class="info-badge"><?php echo htmlspecialchars($semestre_actual_texto); ?></span>
                        </div>
                        <div class="form-group">
                            <label for="grupo_id_diario">Grupo/Sección</label>
                            <select id="grupo_id_diario" name="grupo_id">
                                <option value="">-- Seleccione un grupo --</option>
                                <?php foreach ($grupos_del_profesor as $grupo) : ?>
                                    <option value="<?php echo $grupo['id']; ?>" <?php echo ($grupo_id_diario == $grupo['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grupo['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn">Cargar Grupo</button>
                    </div>
                </form>
                <?php if (!empty($estudiantes_diarios)) : ?>
                    <form method="POST">
                        <input type="hidden" name="guardar_asistencia_diaria" value="1">
                        <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fecha_diaria); ?>">
                        <input type="hidden" name="grupo_id" value="<?php echo htmlspecialchars($grupo_id_diario); ?>">
                        <input type="hidden" name="leccion" value="<?php echo htmlspecialchars($leccion_diaria_num); ?>">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cédula</th>
                                        <th>Primer Apellido</th>
                                        <th>Segundo Apellido</th>
                                        <th>Nombre</th>
                                        <th>Asistencia (P, A, T, J)</th>
                                        <th>Justificación</th>
                                        <th>Nota Semestral Acumulada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes_diarios as $estudiante) :
                                        $estado_actual = $asistencias_guardadas[$estudiante['id']]['estado'] ?? '';
                                        $justificacion_actual = $asistencias_guardadas[$estudiante['id']]['justificacion'] ?? '';
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($estudiante['cedula']); ?></td>
                                            <td><?php echo htmlspecialchars($estudiante['primer_apellido']); ?></td>
                                            <td><?php echo htmlspecialchars($estudiante['segundo_apellido']); ?></td>
                                            <td><?php echo htmlspecialchars($estudiante['nombre']); ?></td>
                                            <td><input type="text" name="asistencia[<?php echo $estudiante['id']; ?>]" class="attendance-input <?php echo htmlspecialchars($estado_actual); ?>" value="<?php echo htmlspecialchars($estado_actual); ?>" maxlength="1" pattern="[PAJTpajt]" placeholder="-"></td>
                                            <td><input type="text" name="justificacion[<?php echo $estudiante['id']; ?>]" class="justificacion-comentario" placeholder="Motivo de la justificación..." value="<?php echo htmlspecialchars($justificacion_actual); ?>" style="display: <?php echo ($estado_actual === 'J') ? 'block' : 'none'; ?>;"></td>
                                            <td class="nota"><?php echo calcularNotaAsistenciaGrupo($pdo, $estudiante['id'], $grupo_id_diario, $fechas_semestres); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align: right; margin-top: 20px;"><button type="submit" class="btn">Guardar Asistencia</button></div>
                    </form>
                <?php elseif ($grupo_id_diario) : ?>
                    <p>El grupo seleccionado no tiene estudiantes asignados.</p>
                <?php else : ?>
                    <p>Por favor, seleccione un grupo para tomar asistencia.</p>
                <?php endif; ?>
            </div>
            <div id="mensual" class="tab-content <?php echo $tab_activa === 'mensual' ? 'active' : ''; ?>">
                <form id="form-mensual" method="GET">
                    <input type="hidden" name="tab" value="mensual">
                    <div class="filters-bar">
                        <div class="form-group"><label for="mes_reporte">Mes</label><select id="mes_reporte" name="mes"><?php for ($m = 1; $m <= 12; $m++) : ?><option value="<?php echo $m; ?>" <?php echo ($mes_reporte == $m) ? 'selected' : ''; ?>><?php echo ucfirst(strftime('%B', mktime(0, 0, 0, $m, 1))); ?></option><?php endfor; ?></select></div>
                        <div class="form-group"><label for="ano_reporte">Año</label><input type="number" id="ano_reporte" name="ano" value="<?php echo htmlspecialchars($ano_reporte); ?>" min="2020" max="2050"></div>
                        <div class="form-group">
                            <label for="grupo_id_reporte">Grupo/Sección</label>
                            <select id="grupo_id_reporte" name="grupo_id_reporte">
                                <option value="">-- Seleccione un grupo --</option>
                                <?php foreach ($grupos_del_profesor as $grupo) : ?>
                                    <option value="<?php echo $grupo['id']; ?>" <?php echo ($grupo_id_reporte == $grupo['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($grupo['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn">Generar Reporte</button>
                        <?php if (!empty($reporte_mensual['estudiantes'])) : ?>
                            <a href="?export=pdf&mes=<?php echo $mes_reporte; ?>&ano=<?php echo $ano_reporte; ?>&grupo_id_reporte=<?php echo $grupo_id_reporte; ?>" class="btn" style="background-color: #c0392b;">Exportar a PDF</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (!empty($reporte_mensual['estudiantes'])) : ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th rowspan="3" style="min-width: 80px;">Cédula</th>
                                    <th rowspan="3" style="min-width: 200px; text-align: left;">Nombre del Estudiante</th>
                                    <?php foreach ($reporte_mensual['semanas'] as $num_semana => $dias) : if (empty($dias)) continue; ?>
                                        <th colspan="<?php echo count($dias) * count(LECCIONES_DEL_DIA); ?>">Semana <?php echo $num_semana; ?></th>
                                    <?php endforeach; ?>
                                    <th rowspan="3">Nota Sem.</th>
                                </tr>
                                <tr>
                                    <?php foreach ($reporte_mensual['semanas'] as $dias) : if (empty($dias)) continue;
                                        foreach ($dias as $dia) : ?>
                                            <th colspan="<?php echo count(LECCIONES_DEL_DIA); ?>" class="dia"><?php echo $dia; ?></th>
                                    <?php endforeach;
                                    endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($reporte_mensual['semanas'] as $dias) : if (empty($dias)) continue;
                                        foreach ($dias as $dia) :
                                            foreach (LECCIONES_DEL_DIA as $leccion) : ?>
                                                <th class="leccion"><?php echo $leccion; ?></th>
                                            <?php endforeach;
                                        endforeach;
                                    endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reporte_mensual['estudiantes'] as $data) : ?>
                                    <tr>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($data['info']['cedula']); ?></td>
                                        <td style="text-align: left;"><?php echo htmlspecialchars($data['info']['primer_apellido'] . ' ' . $data['info']['segundo_apellido'] . ' ' . $data['info']['nombre']); ?></td>
                                        <?php foreach ($reporte_mensual['semanas'] as $dias) : if (empty($dias)) continue;
                                            foreach ($dias as $dia) :
                                                foreach (LECCIONES_DEL_DIA as $leccion) :
                                                    $estado = $data['asistencias'][$dia]['L'.$leccion] ?? '-';
                                        ?>
                                                    <td class="status-cell <?php echo htmlspecialchars($estado); ?>"><?php echo htmlspecialchars($estado); ?></td>
                                            <?php endforeach;
                                            endforeach;
                                        endforeach; ?>
                                        <td class="nota"><?php echo htmlspecialchars($data['nota_mes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($grupo_id_reporte) : ?>
                    <p>No se encontraron datos de asistencia para generar el reporte con los filtros seleccionados.</p>
                <?php else : ?>
                    <p>Por favor, seleccione un grupo y un período para generar el reporte.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab-link');
            const contents = document.querySelectorAll('.tab-content');
            function switchTab(tabName) {
                const url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                if (tabName === 'diaria') {
                    url.searchParams.delete('mes');
                    url.searchParams.delete('ano');
                    url.searchParams.delete('grupo_id_reporte');
                } else {
                    url.searchParams.delete('fecha');
                    url.searchParams.delete('grupo_id');
                    url.searchParams.delete('leccion');
                }
                window.history.pushState({}, '', url);
                tabs.forEach(item => item.classList.remove('active'));
                contents.forEach(content => content.classList.remove('active'));
                const activeTab = document.querySelector(`.tab-link[data-tab="${tabName}"]`);
                const activeContent = document.getElementById(tabName);
                if (activeTab) activeTab.classList.add('active');
                if (activeContent) activeContent.classList.add('active');
            }
            tabs.forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    const tabName = tab.getAttribute('data-tab');
                    switchTab(tabName);
                });
            });
            document.querySelectorAll('.attendance-input').forEach(input => {
                input.addEventListener('input', function() {
                    const value = this.value.toUpperCase();
                    this.className = 'attendance-input';
                    if (['P', 'A', 'J', 'T'].includes(value)) {
                        this.value = value;
                        this.classList.add(value);
                    } else if (value !== '') {
                        this.value = '';
                    }
                    const tr = this.closest('tr');
                    const comentarioInput = tr.querySelector('.justificacion-comentario');
                    if (this.value === 'J') {
                        comentarioInput.style.display = 'block';
                    } else {
                        comentarioInput.style.display = 'none';
                        comentarioInput.value = '';
                    }
                });
            });
        });
    </script>
</body>
</html>