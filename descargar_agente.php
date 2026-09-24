<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();

if (isset($_COOKIE[ESG_AGENT_DOWNLOAD_COOKIE])) {
    http_response_code(409);
    exit('El agente ya fue descargado desde este navegador.');
}

$template = __DIR__ . '/agente/ESG-Agent-template.exe';
if (!is_file($template)) {
    http_response_code(500);
    exit('Agente no disponible.');
}

try {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = db()->prepare('INSERT INTO agente_descargas (token_hash, ip_origen, downloaded_at) VALUES (?, ?, NOW())');
    $stmt->execute([$hash, $ip]);

    $bin = file_get_contents($template);
    if ($bin === false) throw new RuntimeException('No se pudo leer el agente.');

    $server = urlBaseESG();
    $serverPlaceholder = 'ESG_SERVER_URL_00000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';
    $tokenPlaceholder = 'ESG_ENROLL_TOKEN_000000000000000000000000000000000000000000000000000000';

    if (strlen($server) > strlen($serverPlaceholder) || strlen($token) > strlen($tokenPlaceholder)) {
        throw new RuntimeException('Configuración del agente demasiado larga.');
    }

    $bin = str_replace($serverPlaceholder, str_pad($server, strlen($serverPlaceholder), "\0"), $bin);
    $bin = str_replace($tokenPlaceholder, str_pad($token, strlen($tokenPlaceholder), "\0"), $bin);

    setcookie(ESG_AGENT_DOWNLOAD_COOKIE, '1', [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'secure' => cookieSeguro(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="ESG-Agent.exe"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $bin;
} catch (Throwable $e) {
    error_log('ESG descargar agente: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo generar el agente.');
}
