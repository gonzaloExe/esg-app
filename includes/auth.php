<?php
/**
 * ESG - autenticación y vinculación con el agente Windows.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const ESG_PC_COOKIE = 'esg_pc_id';
const ESG_AGENT_DOWNLOAD_COOKIE = 'esg_agent_downloaded';

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

function cookieSeguro(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function obtenerIdPC(): ?string
{
    iniciarSesionESG();
    $id = $_COOKIE[ESG_PC_COOKIE] ?? null;
    return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) ? $id : null;
}

function urlBaseESG(): string
{
    $esHttps = cookieSeguro();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim($dir, '/.');
    return ($esHttps ? 'https' : 'http') . '://' . $host . ($dir ? $dir : '');
}

function reclamarAgenteDesdeURL(): void
{
    $token = trim((string)($_GET['agente_claim'] ?? ''));
    if ($token === '' || !preg_match('/^[a-f0-9]{48,128}$/', $token)) {
        return;
    }

    try {
        $pdo = db();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT * FROM agentes
             WHERE claim_token_hash = ?
             AND claim_expires_at > NOW()
             AND claim_used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $agent = $stmt->fetch();
        if (!$agent) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($agent['ip_origen']) && $agent['ip_origen'] !== $ip) {
            return;
        }

        $agentId = (string)$agent['agent_id'];
        $oldId = $_COOKIE[ESG_PC_COOKIE] ?? '';

        $pdo->beginTransaction();
        try {
            // Si ya existía un usuario por cookie, migrar su identidad al agente.
            if (is_string($oldId) && preg_match('/^[a-f0-9]{32}$/', $oldId) && $oldId !== $agentId) {
                $st = $pdo->prepare('SELECT id, rol FROM usuarios WHERE pc_identificador = ? LIMIT 1');
                $st->execute([$oldId]);
                $oldUser = $st->fetch();
                if ($oldUser && $oldUser['rol'] !== 'superadmin') {
                    $pdo->prepare('UPDATE tickets SET pc_identificador = ? WHERE pc_identificador = ?')->execute([$agentId, $oldId]);
                    $pdo->prepare(
                        'UPDATE usuarios SET pc_identificador = ?, nombre_usuario = ?, fecha_ultima_conexion = NOW() WHERE id = ?'
                    )->execute([$agentId, (string)$agent['username'], (int)$oldUser['id']]);
                }
            }

            $st = $pdo->prepare('SELECT id FROM usuarios WHERE pc_identificador = ? LIMIT 1');
            $st->execute([$agentId]);
            if (!$st->fetch()) {
                $st = $pdo->prepare(
                    'INSERT INTO usuarios (pc_identificador, nombre_usuario, rol, activo, fecha_ultima_conexion)
                     VALUES (?, ?, "usuario", 1, NOW())'
                );
                $st->execute([$agentId, (string)$agent['username']]);
            } else {
                $pdo->prepare('UPDATE usuarios SET nombre_usuario = ?, activo = 1, fecha_ultima_conexion = NOW() WHERE pc_identificador = ?')
                    ->execute([(string)$agent['username'], $agentId]);
            }

            $pdo->prepare('UPDATE agentes SET claim_used_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$agent['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        setcookie(ESG_PC_COOKIE, $agentId, [
            'expires'  => time() + (86400 * 365 * 5),
            'path'     => '/',
            'secure'   => cookieSeguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[ESG_PC_COOKIE] = $agentId;

        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        error_log('ESG claim agent: ' . $e->getMessage());
    }
}

function obtenerAgenteActual(): ?array
{
    $idPc = obtenerIdPC();
    if (!$idPc) return null;

    try {
        $stmt = db()->prepare('SELECT * FROM agentes WHERE agent_id = ? LIMIT 1');
        $stmt->execute([$idPc]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function obtenerDatosPC(): array
{
    $agent = obtenerAgenteActual();
    if ($agent) {
        return [
            'pc_nombre' => (string)($agent['hostname'] ?: 'PC'),
            'usuario'   => (string)($agent['username'] ?: 'usuario'),
            'ip'        => (string)($agent['ip_origen'] ?: ($_SERVER['REMOTE_ADDR'] ?? 'No detectada')),
            'fecha'     => date('Y-m-d H:i:s'),
            'pc_id'     => (string)$agent['agent_id'],
        ];
    }

    $id = obtenerIdPC();
    return [
        'pc_nombre' => gethostname() ?: 'servidor',
        'usuario'   => function_exists('get_current_user') ? (get_current_user() ?: 'usuario') : 'usuario',
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'No detectada',
        'fecha'     => date('Y-m-d H:i:s'),
        'pc_id'     => $id ?: '',
    ];
}

function buscarUsuarioActual(): ?array
{
    $idPc = obtenerIdPC();
    if (!$idPc) return null;

    try {
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE pc_identificador = ? LIMIT 1');
        $stmt->execute([$idPc]);
        $usuario = $stmt->fetch();
        if ($usuario) {
            db()->prepare('UPDATE usuarios SET fecha_ultima_conexion = NOW() WHERE id = ?')->execute([(int)$usuario['id']]);
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
        throw new RuntimeException('Equipo no registrado. Instale el agente ESG.');
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
    if (esSuperAdmin($usuario)) {
        header('Location: admin.php');
        exit;
    }
    return $usuario;
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
