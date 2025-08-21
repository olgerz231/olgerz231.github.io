<?php
session_start();

// Conexión a la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "attendsync";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['profesor_id'])) {
    header("Location: login.php");
    exit();
}

// Obtener información del profesor
$profesor = [];
$profesor_id = $_SESSION['profesor_id'];
$query = "SELECT nombres AS nombre, apellidos AS apellido FROM usuarios WHERE id = ? AND rol = 'profesor'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $profesor_id);
$stmt->execute();
$result = $stmt->get_result();
$profesor = $result->fetch_assoc();

if (!$profesor) {
    $profesor = ['nombre' => 'Usuario', 'apellido' => ''];
}

// Procesar formulario de guardar reporte
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['guardar_reporte'])) {
    $tipo_reporte = $_POST['report-type'];
    $fecha_reporte = $_POST['report-date'];
    $titulo = $_POST['report-title'];
    $descripcion = $_POST['report-description'];
    $fecha_inicial = $_POST['start-date'];
    $fecha_final = $_POST['end-date'];

    $stmt = $conn->prepare("INSERT INTO reportes_profesor (tipo_reporte, fecha_reporte, titulo, descripcion, fecha_inicial, fecha_final) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $tipo_reporte, $fecha_reporte, $titulo, $descripcion, $fecha_inicial, $fecha_final);
    $stmt->execute();
    $stmt->close();
    
    // Redirigir para evitar reenvío del formulario
    header("Location: reportes_profesor.php");
    exit();
}

// Procesar eliminación de reporte
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $conn->query("DELETE FROM reportes_profesor WHERE id = $id");
    header("Location: reportes_profesor.php");
    exit();
}

// Buscar reportes
$busqueda = "";
$where = "";
if (isset($_GET['buscar']) && !empty($_GET['busqueda'])) {
    $busqueda = $_GET['busqueda'];
    $where = "WHERE titulo LIKE '%$busqueda%' OR descripcion LIKE '%$busqueda%' OR tipo_reporte LIKE '%$busqueda%'";
}

$result = $conn->query("SELECT * FROM reportes_profesor $where ORDER BY creado_en DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Ausentismo - AttendSync</title>
    <style>
        /* === ESTILOS CSS UNIFICADOS === */
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
            --white: #ffffff;
            --border-color: #e1e5eb;
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

        /* BARRA LATERAL */
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
            z-index: 1000;
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

        /* CONTENIDO PRINCIPAL */
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 30px;
            background-color: var(--background-color);
            transition: margin-left 0.3s ease;
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
        
        .logout-btn {
            background-color: var(--secondary-color);
        }
        
        .logout-btn:hover {
            background-color: #2e8e94;
        }


        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.05);
        }

        .card {
            background-color: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            border-top: 4px solid var(--primary-color);
            margin-bottom: 25px;
        }

        .card h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-family: "Montserrat", sans-serif;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(58, 168, 173, 0.15);
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }

        .btn-danger {
            background-color: #f44336;
            color: white;
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        /* Tabla de reportes */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9em;
        }

        th {
            background-color: #f9f9f9;
            font-weight: 600;
            color: var(--primary-color);
        }

        tr:hover {
            background-color: rgba(58, 179, 151, 0.05);
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            text-transform: capitalize;
        }

        .badge-primary {
            background-color: var(--primary-color);
        }

        /* Buscador */
        .search-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-container form {
            display: flex;
            gap: 15px;
            width: 100%;
        }

        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-family: "Montserrat", sans-serif;
            font-size: 14px;
        }

        .search-button {
            padding: 10px 20px;
            background-color: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        .search-button:hover {
            background-color: #2e8e94;
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
            
            .search-container form {
                flex-direction: column;
            }
            
            .search-input, .search-button, .btn-danger {
                width: 100%;
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

            .btn, .logout-btn, .search-button, .btn-danger {
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
            <h1>Reportes</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo isset($profesor['nombre'], $profesor['apellido']) ?
                        strtoupper(substr($profesor['nombre'], 0, 1) . substr($profesor['apellido'], 0, 1)) :
                        'P'; ?>
                </div>
                <span>
                    Prof. <?php echo isset($profesor['nombre'], $profesor['apellido']) ?
                            htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']) :
                            'Usuario'; ?>
                </span>
                <a href="logout.php" class="btn logout-btn">Cerrar Sesión</a>
            </div>
        </div>

        <div class="container">
            <h1>Reportes de Ausentismo</h1>

            <div class="card">
                <h2>Crear Nuevo Reporte</h2>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="report-type">Tipo de Reporte</label>
                            <select id="report-type" name="report-type" class="form-control" required>
                                <option value="">Seleccione un tipo</option>
                                <option value="asistencia">Reporte de Asistencia</option>
                                <option value="ausentismo">Reporte de Ausentismo</option>
                                <option value="general">Reporte General</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="report-date">Fecha del Reporte</label>
                            <input type="date" id="report-date" name="report-date" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="report-title">Título del Reporte</label>
                        <input type="text" id="report-title" name="report-title" class="form-control" placeholder="Ej: Asistencia Mayo 2023" required>
                    </div>

                    <div class="form-group">
                        <label for="report-description">Descripción</label>
                        <textarea id="report-description" name="report-description" class="form-control" placeholder="Describa el propósito y contenido del reporte..." required></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="start-date">Fecha Inicial</label>
                            <input type="date" id="start-date" name="start-date" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="end-date">Fecha Final</label>
                            <input type="date" id="end-date" name="end-date" class="form-control">
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" name="guardar_reporte" class="btn btn-primary">Guardar Reporte</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="search-container">
                    <form method="GET" action="">
                        <input type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" class="search-input" placeholder="Buscar reportes...">
                        <button type="submit" name="buscar" class="search-button">Buscar</button>
                        <?php if (!empty($busqueda)): ?>
                            <a href="reportes_profesor.php" class="btn btn-danger">Limpiar</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card">
                <h2>Reportes Guardados</h2>
                
                <?php if ($result->num_rows > 0): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Título</th>
                                    <th>Tipo</th>
                                    <th>Fecha</th>
                                    <th>Período</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['titulo']); ?></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars(ucfirst($row['tipo_reporte'])); ?></span></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['fecha_reporte'])); ?></td>
                                        <td>
                                            <?php if ($row['fecha_inicial'] && $row['fecha_final']): ?>
                                                <?php echo date('d/m/Y', strtotime($row['fecha_inicial'])) . ' - ' . date('d/m/Y', strtotime($row['fecha_final'])); ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="reportes_profesor.php?eliminar=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('¿Estás seguro de eliminar este reporte?')">
                                                Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No se encontraron reportes guardados.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Establecer la fecha actual como valor predeterminado
            const today = new Date().toISOString().split('T')[0];
            const reportDateInput = document.getElementById('report-date');
            if (reportDateInput) {
                 reportDateInput.value = today;
            }
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>