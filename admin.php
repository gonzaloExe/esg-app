<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();
try { $usuario = exigirSuperAdmin(); $csrf = csrfToken(); }
catch (Throwable $e) { http_response_code(500); exit('ESG no está instalado o no puede conectarse a la base de datos.'); }
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ESG - Administración</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link href="assets/css/estilo.css?v=20260924-agente" rel="stylesheet">
</head><body>
<nav class="navbar navbar-dark esg-navbar"><div class="container"><span class="navbar-brand fw-bold"><i class="fa-solid fa-shield-halved me-2"></i>ESG <span class="badge bg-success ms-2">SuperAdmin</span></span><a href="logout.php" class="btn btn-outline-light btn-sm">Salir</a></div></nav>
<main class="container-fluid py-4"><div class="container-fluid">
<h1 class="h3 mb-4">Panel de administración</h1>
<div id="estadisticas" class="row g-3 mb-4"><div class="col-md-3"><div class="stat-card"><span>Total tickets</span><strong id="statTotal">-</strong></div></div><div class="col-md-3"><div class="stat-card"><span>Pendientes</span><strong id="statPendientes">-</strong></div></div><div class="col-md-3"><div class="stat-card"><span>Resueltos</span><strong id="statResueltos">-</strong></div></div><div class="col-md-3"><div class="stat-card"><span>Usuarios</span><strong id="statUsuarios">-</strong></div></div></div>

<section class="card shadow-sm border-0 mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h2 class="h5 mb-0"><i class="fa-solid fa-ticket me-2"></i>Todos los tickets</h2><button id="btnActualizarAdmin" class="btn btn-outline-secondary"><i class="fa-solid fa-rotate me-1"></i>Actualizar</button></div>
<div class="row g-2 mb-3 align-items-end"><div class="col-md-4"><label for="filtroFecha" class="form-label mb-1">Buscar por fecha</label><input type="date" id="filtroFecha" class="form-control"></div><div class="col-md-4"><label for="filtroNI" class="form-label mb-1">Buscar por NI de PC</label><input type="text" id="filtroNI" class="form-control" placeholder="Ej.: PC-047"></div><div class="col-md-4 d-flex gap-2"><button type="button" id="btnAplicarFiltros" class="btn btn-primary flex-fill"><i class="fa-solid fa-magnifying-glass me-1"></i>Buscar</button><button type="button" id="btnLimpiarFiltros" class="btn btn-outline-secondary flex-fill"><i class="fa-solid fa-eraser me-1"></i>Limpiar</button></div></div>
<div id="resultadoFiltros" class="small text-muted mb-2"></div>
<div class="table-responsive"><table class="table align-middle" id="tablaTickets"><thead><tr><th>ID</th><th>Ticket</th><th>Origen</th><th>NI PC</th><th>Fotos</th><th>Fecha</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody></tbody></table></div>
</div></section>

<section class="card shadow-sm border-0 mb-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="fa-solid fa-desktop me-2"></i>Equipos registrados</h2><button id="btnActualizarEquipos" class="btn btn-outline-secondary"><i class="fa-solid fa-rotate me-1"></i>Actualizar</button></div><div class="table-responsive"><table class="table align-middle" id="tablaEquipos"><thead><tr><th>PC</th><th>Usuario</th><th>Dominio</th><th>Sistema</th><th>CPU / RAM</th><th>IP</th><th>Último reporte</th><th class="text-end">Acción</th></tr></thead><tbody></tbody></table></div></div></section>

<section class="card shadow-sm border-0"><div class="card-body"><h2 class="h5 mb-3"><i class="fa-solid fa-users me-2"></i>Usuarios registrados</h2><div class="table-responsive"><table class="table align-middle" id="tablaUsuarios"><thead><tr><th>PC / ID</th><th>Usuario</th><th>Rol</th><th>Activo</th><th>Última conexión</th><th class="text-end">Acción</th></tr></thead><tbody></tbody></table></div></div></section>
</div></main>
<div class="modal fade" id="modalEquipo" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-desktop me-2"></i>Información del equipo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="modalEquipoBody"></div></div></div></div>
<script>window.ESG={csrf:<?=json_encode($csrf)?>,rol:<?=json_encode($usuario['rol'])?>};</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><script src="assets/js/app.js?v=20260924-agente"></script>
</body></html>
