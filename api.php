<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

iniciarSesionESG();

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

function validarTexto(string $valor, int $max): string
{
    $valor = trim($valor);
    if ($valor === '' || mb_strlen($valor) > $max) {
        throw new InvalidArgumentException('Datos inválidos.');
    }
    return $valor;
}

function guardarFoto(array $archivo): ?string
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la foto.');
    }

    if (($archivo['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('La foto supera los 5 MB.');
    }

    $tmp = $archivo['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Archivo de subida inválido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    $permitidos = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    if (!isset($permitidos[$mime])) {
        throw new RuntimeException('Solo se permiten JPG, JPEG y PNG.');
    }

    if (@getimagesize($tmp) === false) {
        throw new RuntimeException('El archivo no es una imagen válida.');
    }

    $nombre = bin2hex(random_bytes(20)) . '.' . $permitidos[$mime];
    $dir = __DIR__ . '/uploads';

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('No se pudo crear uploads.');
    }

    $destino = $dir . DIRECTORY_SEPARATOR . $nombre;

    if (!move_uploaded_file($tmp, $destino)) {
        throw new RuntimeException('No se pudo guardar la foto.');
    }

    return $nombre;
}

try {
    if ($accion === '') {
        jsonResponse(['ok' => false, 'error' => 'Acción no especificada.'], 400);
    }

    if ($metodo === 'GET') {
        if ($accion === 'ver_foto') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                http_response_code(422);
                exit('Foto inválida.');
            }

            $usuario = exigirUsuarioAPI();
            $stmt = db()->prepare('SELECT foto, pc_identificador FROM tickets WHERE id = ?');
            $stmt->execute([$id]);
            $ticket = $stmt->fetch();

            if (!$ticket || empty($ticket['foto'])) {
                http_response_code(404);
                exit('Foto no encontrada.');
            }

            if ($usuario['rol'] !== 'superadmin' && $ticket['pc_identificador'] !== $usuario['pc_identificador']) {
                http_response_code(403);
                exit('Sin permiso.');
            }

            $archivo = __DIR__ . '/uploads/' . basename($ticket['foto']);
            if (!is_file($archivo)) {
                http_response_code(404);
                exit('Archivo no encontrado.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($archivo) ?: 'application/octet-stream';
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                http_response_code(415);
                exit('Tipo de archivo no permitido.');
            }

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string)filesize($archivo));
            header('X-Content-Type-Options: nosniff');
            readfile($archivo);
            exit;
        }

        switch ($accion) {
            case 'obtener_mis_tickets':
                $usuario = exigirUsuarioAPI();

                $stmt = db()->prepare(
                    'SELECT id, titulo, descripcion, foto, numero_identificacion_pc, fecha, estado, resuelto_por, fecha_resolucion
                     FROM tickets
                     WHERE pc_identificador = ?
                     ORDER BY fecha DESC'
                );
                $stmt->execute([$usuario['pc_identificador']]);

                jsonResponse(['ok' => true, 'tickets' => $stmt->fetchAll()]);

            case 'obtener_todos_tickets':
                exigirSuperAdminAPI();

                $stmt = db()->query(
                    'SELECT id, titulo, descripcion, foto, pc_origen, usuario_origen,
                            numero_identificacion_pc, fecha, estado, resuelto_por, fecha_resolucion
                     FROM tickets ORDER BY fecha DESC'
                );

                jsonResponse(['ok' => true, 'tickets' => $stmt->fetchAll()]);

            case 'obtener_usuarios':
                exigirSuperAdminAPI();

                $stmt = db()->query(
                    'SELECT id, pc_identificador, nombre_usuario, rol, activo,
                            fecha_creacion, fecha_ultima_conexion
                     FROM usuarios ORDER BY fecha_creacion DESC'
                );

                jsonResponse(['ok' => true, 'usuarios' => $stmt->fetchAll()]);

            case 'obtener_estadisticas':
                exigirSuperAdminAPI();

                $pdo = db();
                $total = (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
                $pendientes = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado = 'pendiente'")->fetchColumn();
                $resueltos = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado = 'resuelto'")->fetchColumn();
                $usuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE rol = "usuario"')->fetchColumn();

                jsonResponse([
                    'ok' => true,
                    'estadisticas' => compact('total', 'pendientes', 'resueltos', 'usuarios')
                ]);

            default:
                jsonResponse(['ok' => false, 'error' => 'Acción GET no válida.'], 404);
        }
    }

    if ($metodo === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!verificarCsrf($csrf)) {
            jsonResponse(['ok' => false, 'error' => 'Token CSRF inválido.'], 419);
        }

        switch ($accion) {
            case 'crear_ticket':
                $usuario = exigirUsuarioAPI();

                $titulo = validarTexto((string)($_POST['titulo'] ?? ''), 255);
                $numeroIdentificacionPC = validarTexto((string)($_POST['numero_identificacion_pc'] ?? ''), 100);
                $descripcion = trim((string)($_POST['descripcion'] ?? ''));

                if (mb_strlen($descripcion) < 10 || mb_strlen($descripcion) > 10000) {
                    jsonResponse(['ok' => false, 'error' => 'La descripción debe tener entre 10 y 10.000 caracteres.'], 422);
                }

                $foto = guardarFoto($_FILES['foto'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
                $datos = obtenerDatosPC();

                $stmt = db()->prepare(
                    'INSERT INTO tickets
                    (titulo, descripcion, foto, pc_origen, usuario_origen, pc_identificador, numero_identificacion_pc, ip_origen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $titulo,
                    $descripcion,
                    $foto,
                    $datos['pc_nombre'],
                    $datos['usuario'],
                    $datos['pc_id'],
                    $numeroIdentificacionPC,
                    $datos['ip']
                ]);

                jsonResponse(['ok' => true, 'mensaje' => 'Ticket creado correctamente.']);

            case 'marcar_hecho':
                $admin = exigirSuperAdminAPI();

                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) {
                    jsonResponse(['ok' => false, 'error' => 'Ticket inválido.'], 422);
                }

                $stmt = db()->prepare(
                    'UPDATE tickets
                     SET estado = "resuelto", resuelto_por = ?, fecha_resolucion = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([$admin['nombre_usuario'], $id]);

                jsonResponse(['ok' => true, 'mensaje' => 'Ticket marcado como resuelto.']);

            case 'borrar_ticket':
                exigirSuperAdminAPI();

                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) {
                    jsonResponse(['ok' => false, 'error' => 'Ticket inválido.'], 422);
                }

                $pdo = db();
                $stmt = $pdo->prepare('SELECT foto FROM tickets WHERE id = ?');
                $stmt->execute([$id]);
                $ticket = $stmt->fetch();

                $stmt = $pdo->prepare('DELETE FROM tickets WHERE id = ?');
                $stmt->execute([$id]);

                if ($ticket && !empty($ticket['foto'])) {
                    $archivo = __DIR__ . '/uploads/' . basename($ticket['foto']);
                    if (is_file($archivo)) {
                        @unlink($archivo);
                    }
                }

                jsonResponse(['ok' => true, 'mensaje' => 'Ticket eliminado permanentemente.']);

            case 'desactivar_usuario':
                $admin = exigirSuperAdminAPI();

                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) {
                    jsonResponse(['ok' => false, 'error' => 'Usuario inválido.'], 422);
                }

                $stmt = db()->prepare('SELECT id, rol, activo FROM usuarios WHERE id = ?');
                $stmt->execute([$id]);
                $objetivo = $stmt->fetch();

                if (!$objetivo) {
                    jsonResponse(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                }

                if ($objetivo['rol'] === 'superadmin') {
                    jsonResponse(['ok' => false, 'error' => 'El SuperAdmin no puede desactivarse desde este panel.'], 403);
                }

                $nuevoEstado = (int)$objetivo['activo'] === 1 ? 0 : 1;
                $stmt = db()->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
                $stmt->execute([$nuevoEstado, $id]);

                jsonResponse([
                    'ok' => true,
                    'mensaje' => $nuevoEstado ? 'Usuario activado.' : 'Usuario desactivado.'
                ]);

            default:
                jsonResponse(['ok' => false, 'error' => 'Acción POST no válida.'], 404);
        }
    }

    jsonResponse(['ok' => false, 'error' => 'Método HTTP no permitido.'], 405);

} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('ESG API: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Error interno del servidor.'], 500);
}
