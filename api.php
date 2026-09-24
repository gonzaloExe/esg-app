<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();
$accion = trim((string)($_GET['accion'] ?? $_POST['accion'] ?? ''));
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function validarTexto(string $valor, int $max): string
{
    $valor = trim($valor);
    if ($valor === '' || mb_strlen($valor) > $max) throw new InvalidArgumentException('Datos inválidos.');
    return $valor;
}

function guardarFoto(array $archivo): ?string
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir la foto.');
    if (($archivo['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('La foto supera los 5 MB.');
    $tmp = $archivo['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Archivo de subida inválido.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($permitidos[$mime]) || @getimagesize($tmp) === false) throw new RuntimeException('Solo se permiten imágenes JPG, JPEG y PNG válidas.');
    $nombre = bin2hex(random_bytes(20)) . '.' . $permitidos[$mime];
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear uploads.');
    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $nombre)) throw new RuntimeException('No se pudo guardar la foto.');
    return $nombre;
}

function obtenerNumero($value): ?int { $id = filter_var($value, FILTER_VALIDATE_INT); return ($id && $id > 0) ? $id : null; }

function extraerInventario(array $i, string $key): string
{
    $value = $i[$key] ?? null;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

try {
    if ($accion === '') jsonResponse(['ok'=>false,'error'=>'Acción no especificada.'],400);

    if ($metodo === 'GET') {
        if ($accion === 'ver_foto') {
            $id = obtenerNumero($_GET['id'] ?? null);
            if (!$id) { http_response_code(422); exit('Foto inválida.'); }
            $usuario = obtenerUsuarioActual();
            $stmt = db()->prepare('SELECT foto, pc_identificador FROM tickets WHERE id = ?'); $stmt->execute([$id]); $ticket = $stmt->fetch();
            if (!$ticket || empty($ticket['foto'])) { http_response_code(404); exit('Foto no encontrada.'); }
            if (!esSuperAdmin($usuario) && $ticket['pc_identificador'] !== $usuario['pc_identificador']) { http_response_code(403); exit('Sin permiso.'); }
            $archivo = __DIR__ . '/uploads/' . basename((string)$ticket['foto']);
            if (!is_file($archivo)) { http_response_code(404); exit('Archivo no encontrado.'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo) ?: 'application/octet-stream';
            if (!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','image/bmp'],true)) { http_response_code(415); exit('Tipo de imagen no permitido.'); }
            header('Content-Type: '.$mime); header('Content-Length: '.(string)filesize($archivo)); header('Cache-Control: private, no-store'); readfile($archivo); exit;
        }

        switch ($accion) {
            case 'obtener_mis_tickets':
                $usuario = exigirUsuarioAPI();
                $stmt = db()->prepare('SELECT id,titulo,descripcion,foto,numero_identificacion_pc,fecha,estado,resuelto_por,fecha_resolucion FROM tickets WHERE pc_identificador = ? ORDER BY fecha DESC');
                $stmt->execute([$usuario['pc_identificador']]); jsonResponse(['ok'=>true,'tickets'=>$stmt->fetchAll()]);
            case 'obtener_todos_tickets':
                exigirSuperAdminAPI();
                $stmt = db()->query('SELECT id,titulo,descripcion,foto,pc_origen,usuario_origen,numero_identificacion_pc,fecha,estado,resuelto_por,fecha_resolucion FROM tickets ORDER BY fecha DESC');
                jsonResponse(['ok'=>true,'tickets'=>$stmt->fetchAll()]);
            case 'obtener_usuarios':
                exigirSuperAdminAPI();
                $stmt = db()->query('SELECT id,pc_identificador,nombre_usuario,rol,activo,fecha_creacion,fecha_ultima_conexion FROM usuarios ORDER BY fecha_creacion DESC');
                jsonResponse(['ok'=>true,'usuarios'=>$stmt->fetchAll()]);
            case 'obtener_estadisticas':
                exigirSuperAdminAPI(); $pdo=db();
                $total=(int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(); $pendientes=(int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado='pendiente'")->fetchColumn(); $resueltos=(int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado='resuelto'")->fetchColumn(); $usuarios=(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='usuario'")->fetchColumn();
                jsonResponse(['ok'=>true,'estadisticas'=>compact('total','pendientes','resueltos','usuarios')]);
            case 'obtener_equipos':
                exigirSuperAdminAPI();
                $stmt=db()->query('SELECT id,agent_id,machine_guid,hostname,username,domain_name,os_name,os_version,os_build,architecture,ip_origen,cpu_json,memory_json,last_seen FROM agentes ORDER BY hostname ASC, id DESC');
                jsonResponse(['ok'=>true,'equipos'=>$stmt->fetchAll()]);
            case 'obtener_equipo':
                exigirSuperAdminAPI(); $id=obtenerNumero($_GET['id'] ?? null); if(!$id) jsonResponse(['ok'=>false,'error'=>'Equipo inválido.'],422);
                $stmt=db()->prepare('SELECT * FROM agentes WHERE id=?'); $stmt->execute([$id]); $a=$stmt->fetch(); if(!$a) jsonResponse(['ok'=>false,'error'=>'Equipo no encontrado.'],404);
                foreach(['cpu_json','memory_json','bios_json','motherboard_json','system_product_json','disks_json','gpus_json','network_json','logical_disks_json','installed_software_json','antivirus_json','full_inventory_json'] as $key){ $a[$key]=json_decode((string)$a[$key],true); }
                unset($a['claim_token_hash']); jsonResponse(['ok'=>true,'equipo'=>$a]);
            default: jsonResponse(['ok'=>false,'error'=>'Acción GET no válida.'],404);
        }
    }

    if ($metodo === 'POST') {
        // Registro del agente: autenticación por token de descarga de un solo uso; no usa CSRF.
        if ($accion === 'registrar_agente') {
            $data=inputJson(); $token=trim((string)($data['token'] ?? '')); $inventory=$data['inventory'] ?? null;
            if (!preg_match('/^[a-f0-9]{64}$/',$token) || !is_array($inventory)) jsonResponse(['ok'=>false,'error'=>'Solicitud de agente inválida.'],422);
            $machineGuid=trim((string)($inventory['machine_guid'] ?? '')); $hostname=trim((string)($inventory['hostname'] ?? '')); $username=trim((string)($inventory['username'] ?? ''));
            if ($machineGuid === '' || $hostname === '' || $username === '') jsonResponse(['ok'=>false,'error'=>'Faltan datos obligatorios del equipo.'],422);
            $pdo=db(); $hash=hash('sha256',$token);
            $stmt=$pdo->prepare('SELECT id FROM agente_descargas WHERE token_hash=? AND downloaded_at IS NOT NULL AND registered_at IS NULL AND created_at > (NOW() - INTERVAL 24 HOUR) LIMIT 1'); $stmt->execute([$hash]); $download=$stmt->fetch();
            if(!$download) jsonResponse(['ok'=>false,'error'=>'Token de registro inválido o vencido.'],403);
            $ip=$_SERVER['REMOTE_ADDR'] ?? null; $agentId=bin2hex(random_bytes(16)); $claimToken=bin2hex(random_bytes(32)); $claimHash=hash('sha256',$claimToken);
            $claimURL=rtrim(urlBaseESG(),'/').'/index.php?agente_claim='.$claimToken;
            $pdo->beginTransaction();
            try {
                $st=$pdo->prepare('SELECT id,agent_id FROM agentes WHERE machine_guid=? LIMIT 1'); $st->execute([$machineGuid]); $existing=$st->fetch();
                if($existing){ $agentDbId=(int)$existing['id']; $agentId=(string)$existing['agent_id'];
                    $sql='UPDATE agentes SET hostname=?,username=?,domain_name=?,os_name=?,os_version=?,os_build=?,architecture=?,ip_origen=?,cpu_json=?,memory_json=?,bios_json=?,motherboard_json=?,system_product_json=?,disks_json=?,gpus_json=?,network_json=?,logical_disks_json=?,installed_software_json=?,antivirus_json=?,full_inventory_json=?,claim_token_hash=?,claim_expires_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),claim_used_at=NULL,last_seen=NOW(),updated_at=NOW() WHERE id=?';
                    $st=$pdo->prepare($sql); $st->execute([$hostname,$username,(string)($inventory['domain']??''),(string)($inventory['os']??''),(string)($inventory['os_version']??''),(string)($inventory['os_build']??''),(string)($inventory['architecture']??''),$ip,extraerInventario($inventory,'cpu'),extraerInventario($inventory,'memory'),extraerInventario($inventory,'bios'),extraerInventario($inventory,'motherboard'),extraerInventario($inventory,'system_product'),extraerInventario($inventory,'disks'),extraerInventario($inventory,'gpus'),extraerInventario($inventory,'network'),extraerInventario($inventory,'logical_disks'),extraerInventario($inventory,'installed_software'),extraerInventario($inventory,'antivirus'),$claimHash,$agentDbId]);
                } else {
                    $sql='INSERT INTO agentes (agent_id,machine_guid,hostname,username,domain_name,os_name,os_version,os_build,architecture,ip_origen,cpu_json,memory_json,bios_json,motherboard_json,system_product_json,disks_json,gpus_json,network_json,logical_disks_json,installed_software_json,antivirus_json,full_inventory_json,claim_token_hash,claim_expires_at,last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NOW())';
                    $st=$pdo->prepare($sql); $st->execute([$agentId,$machineGuid,$hostname,$username,(string)($inventory['domain']??''),(string)($inventory['os']??''),(string)($inventory['os_version']??''),(string)($inventory['os_build']??''),(string)($inventory['architecture']??''),$ip,extraerInventario($inventory,'cpu'),extraerInventario($inventory,'memory'),extraerInventario($inventory,'bios'),extraerInventario($inventory,'motherboard'),extraerInventario($inventory,'system_product'),extraerInventario($inventory,'disks'),extraerInventario($inventory,'gpus'),extraerInventario($inventory,'network'),extraerInventario($inventory,'logical_disks'),extraerInventario($inventory,'installed_software'),extraerInventario($inventory,'antivirus'),json_encode($inventory,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$claimHash]);
                }
                $pdo->prepare('UPDATE agente_descargas SET registered_at=NOW() WHERE id=?')->execute([(int)$download['id']]);
                $pdo->commit();
            } catch(Throwable $e){$pdo->rollBack();throw $e;}
            jsonResponse(['ok'=>true,'agent_id'=>$agentId,'claim_url'=>$claimURL]);
        }

        $csrf=$_POST['csrf'] ?? ''; if(!verificarCsrf($csrf)) jsonResponse(['ok'=>false,'error'=>'Token CSRF inválido.'],419);
        switch($accion){
            case 'crear_ticket':
                $usuario=exigirUsuarioAPI(); $titulo=validarTexto((string)($_POST['titulo']??''),255); $ni=validarTexto((string)($_POST['numero_identificacion_pc']??''),100); $descripcion=trim((string)($_POST['descripcion']??''));
                if(mb_strlen($descripcion)<10||mb_strlen($descripcion)>10000) jsonResponse(['ok'=>false,'error'=>'La descripción debe tener entre 10 y 10.000 caracteres.'],422);
                $foto=guardarFoto($_FILES['foto']??['error'=>UPLOAD_ERR_NO_FILE]); $datos=obtenerDatosPC();
                $stmt=db()->prepare('INSERT INTO tickets (titulo,descripcion,foto,pc_origen,usuario_origen,pc_identificador,numero_identificacion_pc,ip_origen) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$titulo,$descripcion,$foto,$datos['pc_nombre'],$datos['usuario'],$datos['pc_id'],$ni,$datos['ip']]); jsonResponse(['ok'=>true,'mensaje'=>'Ticket creado correctamente.']);
            case 'marcar_hecho':
                $admin=exigirSuperAdminAPI(); $id=obtenerNumero($_POST['id']??null); if(!$id) jsonResponse(['ok'=>false,'error'=>'Ticket inválido.'],422); db()->prepare('UPDATE tickets SET estado="resuelto",resuelto_por=?,fecha_resolucion=NOW() WHERE id=?')->execute([$admin['nombre_usuario'],$id]); jsonResponse(['ok'=>true,'mensaje'=>'Ticket marcado como resuelto.']);
            case 'borrar_ticket':
                exigirSuperAdminAPI(); $id=obtenerNumero($_POST['id']??null); if(!$id) jsonResponse(['ok'=>false,'error'=>'Ticket inválido.'],422); $pdo=db(); $stmt=$pdo->prepare('SELECT foto FROM tickets WHERE id=?'); $stmt->execute([$id]); $t=$stmt->fetch(); $pdo->prepare('DELETE FROM tickets WHERE id=?')->execute([$id]); if($t&&!empty($t['foto'])){$f=__DIR__.'/uploads/'.basename($t['foto']);if(is_file($f))@unlink($f);} jsonResponse(['ok'=>true,'mensaje'=>'Ticket eliminado permanentemente.']);
            case 'desactivar_usuario':
                exigirSuperAdminAPI(); $id=obtenerNumero($_POST['id']??null); if(!$id) jsonResponse(['ok'=>false,'error'=>'Usuario inválido.'],422); $pdo=db(); $st=$pdo->prepare('SELECT id,rol,activo FROM usuarios WHERE id=?'); $st->execute([$id]); $o=$st->fetch(); if(!$o) jsonResponse(['ok'=>false,'error'=>'Usuario no encontrado.'],404); if($o['rol']==='superadmin') jsonResponse(['ok'=>false,'error'=>'El SuperAdmin no puede desactivarse.'],403); $nuevo=(int)$o['activo']===1?0:1; $pdo->prepare('UPDATE usuarios SET activo=? WHERE id=?')->execute([$nuevo,$id]); jsonResponse(['ok'=>true,'mensaje'=>$nuevo?'Usuario activado.':'Usuario desactivado.']);
            default: jsonResponse(['ok'=>false,'error'=>'Acción POST no válida.'],404);
        }
    }
    jsonResponse(['ok'=>false,'error'=>'Método HTTP no permitido.'],405);
} catch(InvalidArgumentException $e){jsonResponse(['ok'=>false,'error'=>$e->getMessage()],422);} catch(Throwable $e){error_log('ESG API: '.$e->getMessage());jsonResponse(['ok'=>false,'error'=>'Error interno del servidor.'],500);}
