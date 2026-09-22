<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$mensaje = '';
$error = '';
$instalado = false;

function crearArchivoConfig(array $datos): void
{
    $contenido = "<?php\n";
    $contenido .= "return " . var_export($datos, true) . ";\n";

    $archivo = __DIR__ . '/includes/config.local.php';

    if (file_put_contents($archivo, $contenido, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir includes/config.local.php. Revisa permisos.');
    }

    @chmod($archivo, 0640);
}

function bdExisteSuperAdmin(array $cfg): bool
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['database']);
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
    if (!$stmt->fetchColumn()) {
        return false;
    }

    return (bool)$pdo->query("SELECT id FROM usuarios WHERE rol = 'superadmin' LIMIT 1")->fetch();
}

function instalarBD(array $cfg): void
{
    $dsnSinBD = sprintf('mysql:host=%s;charset=utf8mb4', $cfg['host']);
    $pdo = new PDO($dsnSinBD, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $bd = str_replace('`', '``', $cfg['database']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$bd}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['database']),
        $cfg['username'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = file_get_contents(__DIR__ . '/database.sql');
    if ($sql === false) {
        throw new RuntimeException('No se encontró database.sql.');
    }

    // database.sql no contiene procedimientos; se puede ejecutar directamente.
    $pdo->exec($sql);

    $pcId = obtenerIdInstalacion();
    $nombre = function_exists('get_current_user') ? (get_current_user() ?: 'administrador') : 'administrador';
    $hostnameServidor = gethostname() ?: 'servidor';

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios
        (pc_identificador, nombre_usuario, rol, activo, fecha_ultima_conexion)
        VALUES (?, ?, "superadmin", 1, NOW())'
    );
    $stmt->execute([$pcId, $nombre]);

    $stmt = $pdo->prepare(
        'INSERT INTO configuracion (clave, valor)
         VALUES ("pc_superadmin_servidor", ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    $stmt->execute([$hostnameServidor]);

    $uploads = __DIR__ . '/uploads';
    if (!is_dir($uploads) && !mkdir($uploads, 0755, true)) {
        throw new RuntimeException('No se pudo crear la carpeta uploads.');
    }

    if (!is_writable($uploads)) {
        throw new RuntimeException('La carpeta uploads no tiene permisos de escritura.');
    }
}

function obtenerIdInstalacion(): string
{
    if (!empty($_COOKIE['esg_pc_id']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['esg_pc_id'])) {
        return $_COOKIE['esg_pc_id'];
    }

    $id = bin2hex(random_bytes(16));
    setcookie('esg_pc_id', $id, [
        'expires' => time() + (86400 * 365 * 5),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['esg_pc_id'] = $id;

    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cfg = [
            'host'     => trim((string)($_POST['host'] ?? '')),
            'username' => trim((string)($_POST['username'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
            'database' => trim((string)($_POST['database'] ?? '')),
        ];

        if ($cfg['host'] === '' || $cfg['username'] === '' || $cfg['database'] === '') {
            throw new RuntimeException('Completa host, usuario y nombre de base de datos.');
        }

        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $cfg['database'])) {
            throw new RuntimeException('El nombre de la base de datos contiene caracteres no permitidos.');
        }

        // Escribir configuración temporal para poder reutilizar las funciones.
        crearArchivoConfig($cfg);

        if (bdExisteSuperAdmin($cfg)) {
            throw new RuntimeException('El sistema ya está instalado. Elimina o renombra instalar.php por seguridad.');
        }

        instalarBD($cfg);

        $mensaje = 'Instalación completada correctamente. Esta PC/navegador quedó registrado como SuperAdmin.';
        $instalado = true;

    } catch (Throwable $e) {
        $error = $e->getMessage();

        // Si falló antes de completar la instalación, no dejamos credenciales parciales.
        if (!$instalado && isset($cfg)) {
            @unlink(__DIR__ . '/includes/config.local.php');
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ESG - Instalación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="assets/css/estilo.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 mb-2"><i class="fa-solid fa-shield-halved me-2"></i>Instalación de ESG</h1>
                    <p class="text-muted mb-4">Configuración inicial de la aplicación.</p>

                    <?php if ($mensaje): ?>
                        <div class="alert alert-success">
                            <i class="fa-solid fa-circle-check me-2"></i><?= e($mensaje) ?>
                        </div>
                        <div class="alert alert-warning">
                            <strong>Importante:</strong> elimina <code>instalar.php</code> del servidor después de terminar.
                        </div>
                        <a class="btn btn-success btn-lg" href="admin.php">Entrar al panel SuperAdmin</a>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= e($error) ?>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-info">
                            <strong>Nota técnica:</strong> el navegador no entrega a PHP el nombre real de la PC cliente.
                            ESG utiliza un identificador persistente del navegador sin detectar hardware.
                        </div>

                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Host MySQL</label>
                                <input class="form-control form-control-lg" name="host" value="<?= e($_POST['host'] ?? 'localhost') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Usuario MySQL</label>
                                <input class="form-control form-control-lg" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña MySQL</label>
                                <input class="form-control form-control-lg" type="password" name="password">
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Nombre de la base de datos</label>
                                <input class="form-control form-control-lg" name="database" value="<?= e($_POST['database'] ?? 'esg') ?>" required>
                            </div>

                            <button class="btn btn-primary btn-lg w-100" type="submit">
                                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Instalar ESG
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
