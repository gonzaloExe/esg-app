<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();

try {
    $usuario = exigirUsuario();
    $datosPC = obtenerDatosPC();
    $csrf = csrfToken();
} catch (Throwable $e) {
    http_response_code(500);
    exit('ESG no está instalado o no puede conectarse a la base de datos. Ejecuta instalar.php.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ESG - Tickets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="assets/css/estilo.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark esg-navbar">
    <div class="container">
        <span class="navbar-brand fw-bold"><i class="fa-solid fa-shield-halved me-2"></i>ESG</span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Salir</a>
    </div>
</nav>

<main class="container py-4">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><i class="fa-solid fa-ticket me-2"></i>Nuevo ticket</h1>

                    <div class="info-pc mb-4">
                        <div><strong>PC:</strong> <?= e($datosPC['pc_nombre']) ?></div>
                        <div><strong>Usuario:</strong> <?= e($datosPC['usuario']) ?></div>
                        <div><strong>IP:</strong> <?= e($datosPC['ip']) ?></div>
                    </div>

                    <form id="ticketForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="accion" value="crear_ticket">

                        <div class="mb-3">
                            <label class="form-label">Número de identificación de la PC</label>
                            <input type="text" name="numero_identificacion_pc" class="form-control form-control-lg" maxlength="100" required>
                            <div class="form-text">Ingresá el número de identificación que figura en la PC afectada.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Título</label>
                            <input type="text" name="titulo" class="form-control form-control-lg" maxlength="255" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea name="descripcion" class="form-control" rows="6" minlength="10" required></textarea>
                            <div class="form-text">Mínimo 10 caracteres.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Foto <span class="text-muted">(opcional)</span></label>
                            <input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                            <div class="form-text">JPG/JPEG/PNG. Máximo 5 MB.</div>
                        </div>

                        <button class="btn btn-primary btn-lg w-100" type="submit">
                            <i class="fa-solid fa-paper-plane me-2"></i>Enviar ticket
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 mb-0">Mis tickets</h2>
                        <button id="btnActualizar" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-rotate me-1"></i>Actualizar
                        </button>
                    </div>
                    <div id="misTickets" class="ticket-list">
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
window.ESG = {
    csrf: <?= json_encode($csrf) ?>,
    rol: <?= json_encode($usuario['rol']) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
