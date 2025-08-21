<?php
// get_attendance.php - VERSIÓN CORREGIDA

// Iniciar sesión
session_start();

// Configuración de la base de datos
$host = 'localhost';
$dbname = 'attendsync';
$username = 'root';
$password = '';

// Establecer la cabecera para que la respuesta sea JSON
header('Content-Type: application/json');

// Verificar si se recibió el ID del estudiante
if (!isset($_GET['id_estudiante']) || !is_numeric($_GET['id_estudiante'])) {
    echo json_encode(['error' => 'ID de estudiante no válido.']);
    exit();
}

$estudiante_id = intval($_GET['id_estudiante']);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Obtener el nombre del estudiante
    $stmt_estudiante = $pdo->prepare("SELECT nombre, primer_apellido, segundo_apellido FROM estudiantes WHERE id = ?");
    $stmt_estudiante->execute([$estudiante_id]);
    $estudiante_info = $stmt_estudiante->fetch(PDO::FETCH_ASSOC);

    if (!$estudiante_info) {
        echo json_encode(['error' => 'Estudiante no encontrado.']);
        exit();
    }
    
    // CORRECCIÓN 1: Se consulta la tabla 'asistencias' (plural), que es la correcta.
    $stmt_asistencia = $pdo->prepare("SELECT fecha, estado FROM asistencias WHERE estudiante_id = ? ORDER BY fecha DESC");
    $stmt_asistencia->execute([$estudiante_id]);
    $registros_raw = $stmt_asistencia->fetchAll(PDO::FETCH_ASSOC);

    $registros_finales = [];

    // CORRECCIÓN 2: Se traducen los códigos ('P', 'A', etc.) a texto completo para que el JavaScript los entienda.
    foreach ($registros_raw as $registro) {
        $estado_nombre = '';
        switch ($registro['estado']) {
            case 'P': $estado_nombre = 'Presente'; break;
            case 'A': $estado_nombre = 'Ausente'; break;
            case 'T': $estado_nombre = 'Tardía'; break;
            case 'J': $estado_nombre = 'Justificado'; break;
            default:  $estado_nombre = 'Sin estado'; break;
        }

        $registros_finales[] = [
            'fecha' => $registro['fecha'],
            'estado' => $estado_nombre,
            'estado_code' => $registro['estado'] // Se mantiene el código para la lógica de cálculo en JS
        ];
    }

    // Preparar la respuesta JSON final
    $response = [
        'nombre_completo' => htmlspecialchars(trim($estudiante_info['nombre'] . ' ' . $estudiante_info['primer_apellido'] . ' ' . $estudiante_info['segundo_apellido'])),
        'registros' => $registros_finales,
    ];

    echo json_encode($response);

} catch(PDOException $e) {
    // Si hay un error de base de datos, lo mostramos en la respuesta JSON
    echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    exit();
}
?>