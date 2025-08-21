<?php
session_start();

// =============================================
// CONEXIÓN DIRECTA A LA BASE DE DATOS
// =============================================
class Database
{
    private $host = 'localhost';
    private $db_name = 'attendsync';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch (PDOException $exception) {
            echo "Error de conexión: " . $exception->getMessage();
            die();
        }
        return $this->conn;
    }
}

// Crear instancia de conexión
$db = new Database();
$conn = $db->getConnection();

// Verificar autenticación
if (!isset($_SESSION['profesor_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: login.php");
    exit();
}

// Obtener información del profesor
$profesor_id = $_SESSION['profesor_id'];
$query = "SELECT nombres AS nombre, apellidos AS apellido FROM usuarios WHERE id = ? AND rol = 'profesor'";
$stmt = $conn->prepare($query);
$stmt->execute([$profesor_id]);
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);


// Obtener grupos del profesor
$query_grupos = "SELECT id, nombre FROM grupos WHERE profesor_id = ?";
$stmt_grupos = $conn->prepare($query_grupos);
$stmt_grupos->execute([$profesor_id]);
$grupos = $stmt_grupos->fetchAll(PDO::FETCH_ASSOC);

// --- Consultas para las tarjetas del Dashboard ---

// Obtener estadísticas generales (total de estudiantes y grupos)
$query_estadisticas = "SELECT
    COUNT(DISTINCT ge.estudiante_id) as total_estudiantes,
    COUNT(DISTINCT g.id) as total_grupos
FROM grupos g
JOIN grupo_estudiante ge ON g.id = ge.grupo_id
WHERE g.profesor_id = ?";
$stmt_estadisticas = $conn->prepare($query_estadisticas);
$stmt_estadisticas->execute([$profesor_id]);
$estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

// --- Consulta para la sección "Actividad Reciente" ---
$query_actividad_reciente = "SELECT
    a.fecha,
    g.nombre as grupo_nombre,
    COUNT(a.id) as registros
FROM asistencias a
JOIN grupos g ON a.grupo_id = g.id
WHERE g.profesor_id = ?
GROUP BY a.fecha, g.nombre
ORDER BY a.fecha DESC
LIMIT 4";
$stmt_actividad = $conn->prepare($query_actividad_reciente);
$stmt_actividad->execute([$profesor_id]);
$actividades_recientes = $stmt_actividad->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del Profesor - AttendSync</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap");

        :root {
            --primary-color: #3ab397;
            --secondary-color: #3aa8ad;
            --background-color: #f0f4f3;
            --text-color: #333;
            --card-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            --border-radius: 8px;
            --input-border: #ccc;
            --input-focus-border: var(--primary-color);
            --success-color: #4CAF50;
            --error-color: #f44336;
            --info-color: #2196F3;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Montserrat", sans-serif;
            background-color: var(--background-color);
            display: flex;
            min-height: 100vh;
            color: var(--text-color);
            line-height: 1.6;
        }

        .sidebar {
            width: 220px;
            background-color: var(--primary-color);
            padding: 20px 0;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 25px;
        }


        .sidebar-header h2 {
            color: white;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-weight: 600;
        }

        .sidebar-header svg {
            fill: white;
        }

        .sidebar-btn {
            padding: 12px 25px;
            background: none;
            border: none;
            color: white;
            text-align: left;
            cursor: pointer;
            font-size: 15px;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 2px 0;
        }

        .sidebar-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-btn.active {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid white;
            font-weight: 500;
        }

        .sidebar-btn svg {
            width: 20px;
            height: 20px;
            fill: white;
        }

        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 30px;
            background-color: var(--background-color);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        h1 {
            color: var(--primary-color);
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background-color: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .user-info span {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1.1em;
        }

        .btn {
            padding: 10px 18px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            font-size: 1em;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
        }

        .btn-primary:hover {
            background-color: #2e9e87;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .main-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .card,
        .action-btn {
            background-color: white;
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            padding: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--card-shadow);
            text-decoration: none;
            color: var(--text-color);
            text-align: center;
        }

        /* El hover genérico se quita para dar paso a los colores específicos */
        /* .card:hover, .action-btn:hover { ... } */

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            /* Color por defecto */
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            color: white;
            font-size: 28px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .action-icon svg {
            width: 60%;
            height: 60%;
            fill: currentColor;
        }

        .action-text {
            font-size: 1.1em;
            font-weight: 600;
            line-height: 1.3;
        }

        /* --- COLORES PARA LAS CARTAS DE ACCIONES --- */

        /* Azul */
        .card-blue .action-icon {
            background-color: #2196F3;
        }

        .card-blue:hover {
            background-color: #2196F3;
            color: white;
        }

        .card-blue:hover .action-icon {
            background-color: white;
            color: #2196F3;
        }

        /* Verde */
        .card-green .action-icon {
            background-color: #4CAF50;
        }

        .card-green:hover {
            background-color: #4CAF50;
            color: white;
        }

        .card-green:hover .action-icon {
            background-color: white;
            color: #4CAF50;
        }

        /* Amarillo / Ámbar */
        .card-yellow .action-icon {
            background-color: #ffc107;
        }

        .card-yellow:hover {
            background-color: #ffc107;
            color: white;
        }

        .card-yellow:hover .action-icon {
            background-color: white;
            color: #ffc107;
        }

        /* Morado */
        .card-purple .action-icon {
            background-color: #673ab7;
        }

        .card-purple:hover {
            background-color: #673ab7;
            color: white;
        }

        .card-purple:hover .action-icon {
            background-color: white;
            color: #673ab7;
        }

        .card-purple:hover .card-value {
            color: white;
        }

        .card-purple:hover .card-description {
            color: rgba(255, 255, 255, 0.9);
        }


        /* Estilos específicos para el contenido de la tarjeta 'Mis Estudiantes' */
        .card .card-value {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--text-color);
            /* Color de texto normal */
            line-height: 1;
            margin-top: 10px;
            margin-bottom: 5px;
            transition: color 0.3s ease;
        }

        .card .card-description {
            font-size: 0.9em;
            color: #777;
            transition: color 0.3s ease;
        }

        .section-title {
            font-size: 1.6rem;
            color: var(--primary-color);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .section-title svg {
            fill: var(--primary-color);
            width: 28px;
            height: 28px;
        }

        .recent-activities {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            border: 1px solid #e0e0e0;
        }

        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
            gap: 15px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            flex-shrink: 0;
        }

        .activity-item .activity-icon svg {
            width: 60%;
            height: 60%;
            fill: var(--primary-color);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            margin-bottom: 3px;
            color: var(--text-color);
            font-size: 1em;
        }

        .activity-date {
            font-size: 0.85em;
            color: #777;
        }

        .content-section {
            display: none;
            animation: fadeIn 0.6s ease-out;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .user-info {
                margin-top: 20px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
                padding: 15px 0;
            }

            .sidebar-header h2 span,
            .sidebar-btn span {
                display: none;
            }

            .sidebar-btn {
                justify-content: center;
                padding: 12px 5px;
                border-left: none;
            }

            .sidebar-btn.active {
                border-left: none;
                background: rgba(255, 255, 255, 0.2);
            }

            .main-content {
                margin-left: 80px;
                padding: 20px;
            }

            h1 {
                font-size: 1.6rem;
            }

            .section-title {
                font-size: 1.4rem;
            }

            .card .card-value {
                font-size: 2.2em;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }

            .admin-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .user-info {
                flex-direction: column;
                gap: 10px;
                margin-top: 15px;
            }

            .user-info span {
                font-size: 1em;
            }

            .btn-primary {
                width: 100%;
                margin-left: 0 !important;
                margin-top: 10px;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'sidebar_profesor.php'; ?>

    <div class="main-content">
        <div class="admin-header">
            <h1>Panel del Profesor</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo substr($profesor['nombre'], 0, 1) . substr($profesor['apellido'], 0, 1); ?>
                </div>
                <span>Prof. <?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></span>
                <a href="logout.php" class="btn btn-primary" style="margin-left: 15px;">Cerrar Sesión</a>
            </div>
        </div>

        <div class="container">
            <div class="content-section active" id="dashboardSection">

                <h2 class="section-title">
                    <svg viewBox="0 0 24 24" width="24" height="24">
                        <path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z" />
                    </svg>
                    Acciones Rápidas
                </h2>

                <div class="main-actions-grid">

                    <a href="registros_profesor.php" class="action-btn card-blue">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
                            </svg>
                        </div>
                        <span class="action-text">Registrar Asistencia</span>
                    </a>

                    <a href="consultas_profesor.php" class="action-btn card-green">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 0 0 1.48-5.34c-.47-2.78-2.79-5-5.59-5.34a6.505 6.505 0 0 0-7.27 7.27c.34 2.8 2.56 5.12 5.34 5.59a6.5 6.5 0 0 0 5.34-1.48l.27.28v.79l4.25 4.25c.41.41 1.08.41 1.49 0 .41-.41.41-1.08 0-1.49L15.5 14zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                            </svg>
                        </div>
                        <span class="action-text">Consultar Asistencia</span>
                    </a>

                    <a href="reportes_profesor.php" class="action-btn card-yellow">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" />
                            </svg>
                        </div>
                        <span class="action-text">Generar Reportes</span>
                    </a>

                    <div class="card card-purple">
                        <div class="action-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V18h14v-1.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V18h6v-1.5c0-2.33-4.67-3.5-7-3.5z" />
                            </svg>
                        </div>
                        <span class="action-text">Mis Estudiantes</span>
                        <div class="card-value"><?php echo $estadisticas['total_estudiantes'] ?? 0; ?></div>
                        <p class="card-description">En <?php echo $estadisticas['total_grupos'] ?? 0; ?> grupos</p>
                    </div>

                </div>

                <div class="recent-activities">
                    <h2 class="section-title">
                        <svg viewBox="0 0 24 24" width="24" height="24">
                            <path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z" />
                        </svg>
                        Actividad Reciente
                    </h2>
                    <ul class="activity-list">
                        <?php if (empty($actividades_recientes)): ?>
                            <li class="activity-item">
                                <div class="activity-icon"></div>
                                <div class="activity-content">
                                    <p class="activity-title">No hay actividades recientes</p>
                                </div>
                            </li>
                        <?php else: ?>
                            <?php foreach ($actividades_recientes as $actividad): ?>
                                <li class="activity-item">
                                    <div class="activity-icon">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
                                        </svg>
                                    </div>
                                    <div class="activity-content">
                                        <p class="activity-title">Registro de asistencia para <?php echo htmlspecialchars($actividad['grupo_nombre']); ?></p>
                                        <p class="activity-date"><?php echo date('d/m/Y', strtotime($actividad['fecha'])); ?> - <?php echo $actividad['registros']; ?> registros</p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>

</html>