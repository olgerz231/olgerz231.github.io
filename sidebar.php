<?php
// sidebar.php
// Este archivo contiene la barra lateral que se incluye en otras páginas.

// Obtenemos el nombre del archivo actual para resaltar el enlace activo
$current_page = basename($_SERVER['PHP_SELF']);
$apellidos_usuario = $_SESSION['apellidos'] ?? '';
$nombres_usuario = $_SESSION['nombres'] ?? '';
$admin_avatar_iniciales = strtoupper(substr($nombres_usuario, 0, 1) . substr($apellidos_usuario, 0, 1));
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
        <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2 3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
        <span>Ajustes y Reportes</span>
    </a>
</div>