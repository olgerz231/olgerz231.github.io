<?php
// Define una variable $currentPage en la página principal antes de incluir este archivo.
// Ejemplo: $currentPage = 'inicio';
if (!isset($currentPage)) {
    $currentPage = ''; // Valor por defecto para evitar errores
}
?>

<div class="sidebar">
    <div class="sidebar-header">
        <h2><svg viewBox="0 0 24 24" width="24" height="24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z" />
            </svg> <span>AttendSync</span></h2>
    </div>

    <a href="panel_profesor.php" class="sidebar-btn <?php echo ($currentPage === 'inicio') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />
        </svg> <span>Inicio</span>
    </a>

    <a href="registros_profesor.php" class="sidebar-btn <?php echo ($currentPage === 'registro') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
        </svg> <span>Registro</span>
    </a>

    <a href="consultas_profesor.php" class="sidebar-btn <?php echo ($currentPage === 'consulta') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 0 0 1.48-5.34c-.47-2.78-2.79-5-5.59-5.34a6.505 6.505 0 0 0-7.27 7.27c.34 2.8 2.56 5.12 5.34 5.59a6.5 6.5 0 0 0 5.34-1.48l.27.28v.79l4.25 4.25c.41.41 1.08.41 1.49 0 .41-.41.41-1.08 0-1.49L15.5 14zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
        </svg> <span>Consulta</span>
    </a>

    <a href="reportes_profesor.php" class="sidebar-btn <?php echo ($currentPage === 'reportes') ? 'active' : ''; ?>">
        <svg viewBox="0 0 24 24">
            <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" />
        </svg> <span>Reportes</span>
    </a>
</div>