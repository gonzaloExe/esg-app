<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$estado = [];
$error = null;

try {
    $pdo = db();
    $estado['conexion'] = true;

    $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $estado['tablas'] = $tablas;

    $usuarios = $pdo->query(
        'SELECT id, pc_identificador, nombre_usuario, rol, activo, fecha_creacion, fecha_ultima_conexion
         FROM usuarios ORDER BY id'
    )->fetchAll();

    $superadmin = $pdo->query(
        "SELECT id, pc_identificador, nombre_usuario FROM usuarios WHERE rol = 'superadmin' LIMIT 1"
    )->fetch();

} catch (Throwable $e) {
    $estado['conexion'] = false;
    $error = $e->getMessage();
    $usuarios = [];
    $superadmin = null;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ESG - Verificar BD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/estilo.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="h3 mb-4">Diagnóstico de ESG</h1>

    <?php if ($estado['conexion']): ?>
        <div class="alert alert-success">Conexión a MySQL: correcta.</div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h2 class="h5">Tablas</h2>
                <ul>
                    <?php foreach ($estado['tablas'] as $tabla): ?>
                        <li><?= e((string)$tabla) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="mb-0">
                    <strong>SuperAdmin:</strong>
                    <?= $superadmin ? 'Existe' : 'NO existe' ?>
                </p>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5 mb-3">Usuarios registrados</h2>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>ID</th><th>PC ID</th><th>Usuario</th><th>Rol</th><th>Activo</th><th>Última conexión</th></tr></thead>
                        <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td><?= (int)$u['id'] ?></td>
                                <td><code><?= e($u['pc_identificador']) ?></code></td>
                                <td><?= e($u['nombre_usuario']) ?></td>
                                <td><?= e($u['rol']) ?></td>
                                <td><?= (int)$u['activo'] ? 'Sí' : 'No' ?></td>
                                <td><?= e($u['fecha_ultima_conexion']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <strong>Error de conexión:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
