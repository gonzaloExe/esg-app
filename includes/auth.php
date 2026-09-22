<?php
/**
 * ESG - Identificación y roles.
 *
 * IMPORTANTE:
 * Un navegador no permite que PHP obtenga directamente el hostname
 * o el usuario de Windows/Linux de la PC cliente.
 *
 * gethostname() en PHP devuelve el hostname DEL SERVIDOR, no el de la PC
 * desde la que visita el sitio.
 *
 * Por eso ESG usa:
 * - un identificador persistente generado por el servidor y guardado en una cookie;
 * - la IP de origen como dato adicional;
 * - gethostname() solamente como dato del servidor.
 *
 * Esto mantiene el sistema sin contraseñas y sin detección de hardware.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const ESG_PC_COOKIE = 'esg_pc_id';
const ESG_ADMIN_COOKIE = 'esg_admin_token';

function iniciarSesionESG(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('ESGSESSID');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }
}

function obtenerIdPC(): string
{
    iniciarSesionESG();

    if (!empty($_COOKIE[ESG_PC_COOKIE]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[ESG_PC_COOKIE])) {
        return $_COOKIE[ESG_PC_COOKIE];
    }

    $id = bin2hex(random_bytes(16));

    setcookie(ESG_PC_COOKIE, $id, [
        'expires'  => time() + (86400 * 365 * 5),
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[ESG_PC_COOKIE] = $id;
    return $id;
}

function obtenerDatosPC(): array
{
    return [
        'pc_nombre' => gethostname() ?: 'servidor',
        'usuario'   => function_exists('get_current_user')
            ? (get_current_user() ?: 'usuario')
            : 'usuario_' . substr(md5(gethostname() ?: 'servidor'), 0, 6),
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'No detectada',
        'fecha'     => date('Y-m-d H:i:s'),
        'pc_id'     => obtenerIdPC(),
    ];
}

function instalarIdentidadInicial(): array
{
    return obtenerDatosPC();
}

function buscarUsuarioActual(): ?array
{
    $idPc = obtenerIdPC();

    try {
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE pc_identificador = ? LIMIT 1');
        $stmt->execute([$idPc]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $upd = db()->prepare('UPDATE usuarios SET fecha_ultima_conexion = NOW() WHERE id = ?');
            $upd->execute([(int)$usuario['id']]);
        }

        return $usuario ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function obtenerUsuarioActual(): array
{
    $usuario = buscarUsuarioActual();

    if (!$usuario) {
        $datos = obtenerDatosPC();

        $stmt = db()->prepare(
            'INSERT INTO usuarios
            (pc_identificador, nombre_usuario, rol, activo, fecha_ultima_conexion)
            VALUES (?, ?, "usuario", 1, NOW())'
        );
        $stmt->execute([$datos['pc_id'], $datos['usuario']]);

        $usuario = buscarUsuarioActual();
    }

    if (!$usuario) {
        throw new RuntimeException('No fue posible identificar la PC actual.');
    }

    return $usuario;
}

function esSuperAdmin(array $usuario): bool
{
    return ($usuario['rol'] ?? '') === 'superadmin' && (int)($usuario['activo'] ?? 0) === 1;
}

function exigirUsuario(): array
{
    $usuario = obtenerUsuarioActual();

    if (!esSuperAdmin($usuario)) {
        return $usuario;
    }

    header('Location: admin.php');
    exit;
}

function exigirSuperAdmin(): array
{
    $usuario = obtenerUsuarioActual();

    if (!esSuperAdmin($usuario)) {
        header('Location: index.php');
        exit;
    }

    return $usuario;
}

function exigirSuperAdminAPI(): array
{
    $usuario = obtenerUsuarioActual();

    if (!esSuperAdmin($usuario)) {
        jsonResponse(['ok' => false, 'error' => 'No autorizado.'], 403);
    }

    return $usuario;
}

function exigirUsuarioAPI(): array
{
    $usuario = obtenerUsuarioActual();

    if (esSuperAdmin($usuario)) {
        jsonResponse(['ok' => false, 'error' => 'Los SuperAdmin no pueden realizar esta acción.'], 403);
    }

    return $usuario;
}

function nombreRol(string $rol): string
{
    return $rol === 'superadmin' ? 'SuperAdmin' : 'Usuario';
}
