<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();

try {
    $usuario = obtenerUsuarioActual();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) { http_response_code(400); exit('Foto inválida.'); }

    $stmt = db()->prepare('SELECT foto, pc_identificador FROM tickets WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    if (!$ticket || empty($ticket['foto'])) { http_response_code(404); exit('Foto no encontrada.'); }

    // El SuperAdmin puede ver todas. El usuario solo sus propios tickets.
    if (!esSuperAdmin($usuario) && $ticket['pc_identificador'] !== $usuario['pc_identificador']) {
        http_response_code(403); exit('Sin permiso.');
    }

    $archivo = __DIR__ . '/uploads/' . basename((string)$ticket['foto']);
    if (!is_file($archivo) || !is_readable($archivo)) { http_response_code(404); exit('Archivo no encontrado.'); }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) { http_response_code(415); exit('Tipo de imagen no permitido.'); }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($archivo));
    header('Content-Disposition: inline; filename="ticket-' . $id . '.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    exit;
} catch (Throwable $e) {
    error_log('ESG foto.php: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo mostrar la foto.');
}
