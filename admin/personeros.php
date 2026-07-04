<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/webp.php';
require_once __DIR__ . '/../includes/config/mail.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';

require_login();
require_rol('editor');
require_modulo($pdo, 'personeros');

$page_title = 'Personeros';

// ── Mensajeria (correo masivo / plantillas WhatsApp) ──────────
function ensure_personero_mensajes_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS personero_mensajes (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            canal           VARCHAR(20) NOT NULL DEFAULT 'correo',
            asunto          VARCHAR(200) NOT NULL,
            mensaje         TEXT NOT NULL,
            alcance         VARCHAR(20) NOT NULL DEFAULT 'grupo',
            creado_por      INT NULL,
            adjunto_nombre  VARCHAR(180) NULL,
            adjunto_ruta    VARCHAR(255) NULL,
            adjunto_tipo    VARCHAR(120) NULL,
            adjunto_tamanio INT NULL,
            creado_en       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS personero_mensaje_canales (
            mensaje_id INT NOT NULL,
            canal      VARCHAR(20) NOT NULL,
            PRIMARY KEY (mensaje_id, canal),
            CONSTRAINT fk_pmc_mensaje FOREIGN KEY (mensaje_id) REFERENCES personero_mensajes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS personero_mensaje_destinatarios (
            mensaje_id  INT NOT NULL,
            personero_id INT NOT NULL,
            estado      VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            enviado_en  DATETIME NULL,
            error       TEXT NULL,
            PRIMARY KEY (mensaje_id, personero_id),
            CONSTRAINT fk_pmd_mensaje FOREIGN KEY (mensaje_id) REFERENCES personero_mensajes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS personero_wa_plantillas (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            nombre     VARCHAR(200) NOT NULL,
            contenido  TEXT NOT NULL,
            creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
ensure_personero_mensajes_tables($pdo);

function upload_email_attachment_personero(): array {
    if (empty($_FILES['adjunto']['name']) || ($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['name' => null, 'path' => null, 'mime' => null, 'size' => null];
    }

    if (($_FILES['adjunto']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo leer el archivo adjunto.');
    }

    $max_size = 8 * 1024 * 1024;
    $size = (int)($_FILES['adjunto']['size'] ?? 0);
    if ($size <= 0 || $size > $max_size) {
        throw new RuntimeException('El adjunto debe pesar como maximo 8 MB.');
    }

    $original = basename((string)$_FILES['adjunto']['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowed_ext, true)) {
        throw new RuntimeException('Solo se permiten imagenes, PDF, DOC o DOCX.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $_FILES['adjunto']['tmp_name']) : false;
        if ($finfo) finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }

    $allowed_mimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ];
    if (!in_array($mime, $allowed_mimes, true)) {
        throw new RuntimeException('El tipo de archivo adjunto no esta permitido.');
    }
    if ($mime === 'application/zip' && $ext !== 'docx') {
        throw new RuntimeException('El archivo ZIP solo se permite cuando corresponde a un DOCX.');
    }

    $dir = __DIR__ . '/../uploads/personeros-correo';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de adjuntos.');
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['adjunto']['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar el archivo adjunto.');
    }

    return [
        'name' => $original,
        'path' => 'uploads/personeros-correo/' . $filename,
        'mime' => $mime,
        'size' => $size,
    ];
}

// ── Helpers ───────────────────────────────────────────────────
function json_resp(array $d, int $s = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($s);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Migración automática ──────────────────────────────────────
function ensure_personeros_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS personeros (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        nombres             VARCHAR(120) NOT NULL,
        apellidos           VARCHAR(120) NOT NULL,
        dni                 CHAR(8)      NULL,
        carnet_extranjeria  VARCHAR(9)   NULL,
        edad                TINYINT UNSIGNED NULL,
        correo              VARCHAR(150) NULL,
        nacionalidad        VARCHAR(80)  NOT NULL DEFAULT 'Peruana',
        cargo               ENUM('titular','alterno') NOT NULL DEFAULT 'titular',
        celular             VARCHAR(20)  NULL,
        whatsapp            VARCHAR(20)  NULL,
        direccion           TEXT         NULL,
        local_votacion      VARCHAR(200) NULL,
        numero_mesa         VARCHAR(30)  NULL,
        foto                VARCHAR(300) NULL,
        origen              ENUM('manual','militante','simpatizante') NOT NULL DEFAULT 'manual',
        origen_id           INT          NULL,
        estado              ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
        creado_en           DATETIME     DEFAULT CURRENT_TIMESTAMP,
        actualizado_en      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

ensure_personeros_table($pdo);

// ── Endpoint JSON para refresh tras guardar ───────────────────
if (isset($_GET['json'])) {
    while (ob_get_level() > 0) ob_end_clean();
    $list = $pdo->query("SELECT * FROM personeros ORDER BY cargo ASC, apellidos ASC, nombres ASC")->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['personeros' => array_values($list)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
$json_msg_actions = ['prepare_email', 'send_email_recipient', 'save_wa_plantilla', 'delete_wa_plantilla'];
$allowed_actions = array_merge(['save_personero','delete_personero','toggle_estado'], $json_msg_actions);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, $allowed_actions, true)) csrf_verify(in_array($action, $json_msg_actions, true));
}

// ── Upload foto ───────────────────────────────────────────────
function upload_foto_personero(array $file, ?string $old_foto): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) return null;

    $max = 4 * 1024 * 1024;
    if ($file['size'] > $max) throw new RuntimeException('La foto debe pesar máximo 4 MB.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) throw new RuntimeException('Solo JPG, PNG o WEBP.');

    $dir = dirname(__DIR__) . '/uploads/personeros/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $prefix = 'per_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    $webp_bytes = img_to_webp($file['tmp_name'], $ext);
    if ($webp_bytes !== false) {
        $nombre = $prefix . '.webp';
        file_put_contents($dir . $nombre, $webp_bytes);
    } else {
        $nombre = $prefix . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $dir . $nombre);
    }

    if ($old_foto) {
        $old_path = dirname(__DIR__) . '/' . $old_foto;
        if (is_file($old_path)) @unlink($old_path);
    }

    return 'uploads/personeros/' . $nombre;
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Guardar (crear / editar) ──────────────────────────────
    if ($action === 'save_personero') {
        $id        = (int)($_POST['id'] ?? 0);
        $nombres   = trim($_POST['nombres']   ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $dni       = trim($_POST['dni']       ?? '');
        $carnet    = trim($_POST['carnet_extranjeria'] ?? '');
        $edad      = (($_POST['edad'] ?? '') !== '') ? (int)$_POST['edad'] : null;
        $correo    = trim($_POST['correo']       ?? '') ?: null;
        $nacion    = trim($_POST['nacionalidad'] ?? 'Peruana') ?: 'Peruana';
        $cargo     = in_array($_POST['cargo'] ?? '', ['titular','alterno']) ? $_POST['cargo'] : 'titular';
        $celular   = trim($_POST['celular']   ?? '') ?: null;
        $whatsapp  = trim($_POST['whatsapp']  ?? '') ?: null;
        $direccion = trim($_POST['direccion'] ?? '') ?: null;
        $local     = trim($_POST['local_votacion'] ?? '') ?: null;
        $mesa      = trim($_POST['numero_mesa']    ?? '') ?: null;
        $origen    = in_array($_POST['origen'] ?? '', ['manual','militante','simpatizante']) ? $_POST['origen'] : 'manual';
        $origen_id = (int)($_POST['origen_id'] ?? 0) ?: null;

        if ($nombres === '' || $apellidos === '') json_resp(['ok'=>false,'msg'=>'Nombres y apellidos son obligatorios.']);
        if ($dni !== '' && !preg_match('/^\d{8}$/', $dni)) json_resp(['ok'=>false,'msg'=>'El DNI debe tener exactamente 8 dígitos.']);
        if ($carnet !== '' && !preg_match('/^\d{9}$/', $carnet)) json_resp(['ok'=>false,'msg'=>'El carnet debe tener exactamente 9 dígitos.']);

        $old_foto = null;
        if ($id > 0) {
            $r = $pdo->prepare("SELECT foto FROM personeros WHERE id=?");
            $r->execute([$id]);
            $old_foto = $r->fetchColumn() ?: null;
        }

        try {
            $foto_path = upload_foto_personero($_FILES['foto'] ?? [], $old_foto);
        } catch (RuntimeException $e) {
            json_resp(['ok'=>false,'msg'=>$e->getMessage()]);
        }

        $foto_final = $foto_path ?? ($id > 0 ? $old_foto : null);

        if ($id > 0) {
            $pdo->prepare("UPDATE personeros SET
                nombres=?,apellidos=?,dni=?,carnet_extranjeria=?,edad=?,correo=?,
                nacionalidad=?,cargo=?,celular=?,whatsapp=?,direccion=?,
                local_votacion=?,numero_mesa=?,foto=?,origen=?,origen_id=?,
                actualizado_en=NOW()
                WHERE id=?")->execute([
                $nombres,$apellidos,$dni?:null,$carnet?:null,$edad,$correo,
                $nacion,$cargo,$celular,$whatsapp,$direccion,
                $local,$mesa,$foto_final,$origen,$origen_id,$id
            ]);
            json_resp(['ok'=>true,'msg'=>'Personero actualizado.','id'=>$id]);
        } else {
            $pdo->prepare("INSERT INTO personeros
                (nombres,apellidos,dni,carnet_extranjeria,edad,correo,
                 nacionalidad,cargo,celular,whatsapp,direccion,
                 local_votacion,numero_mesa,foto,origen,origen_id)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $nombres,$apellidos,$dni?:null,$carnet?:null,$edad,$correo,
                $nacion,$cargo,$celular,$whatsapp,$direccion,
                $local,$mesa,$foto_final,$origen,$origen_id
            ]);
            $nuevo_id = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Registró personero: '.$nombres.' '.$apellidos, 'personeros');
            json_resp(['ok'=>true,'msg'=>'Personero registrado.','id'=>$nuevo_id]);
        }
    }

    // ── Eliminar ──────────────────────────────────────────────
    if ($action === 'delete_personero') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
        $r = $pdo->prepare("SELECT foto,nombres,apellidos FROM personeros WHERE id=?");
        $r->execute([$id]);
        $row = $r->fetch();
        if (!$row) json_resp(['ok'=>false,'msg'=>'No encontrado.']);
        if ($row['foto']) {
            $p = dirname(__DIR__) . '/' . $row['foto'];
            if (is_file($p)) @unlink($p);
        }
        $pdo->prepare("DELETE FROM personeros WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Eliminó personero: '.$row['nombres'].' '.$row['apellidos'], 'personeros');
        json_resp(['ok'=>true,'msg'=>'Personero eliminado.']);
    }

    // ── Toggle estado ─────────────────────────────────────────
    if ($action === 'toggle_estado') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
        $pdo->prepare("UPDATE personeros SET estado = IF(estado='activo','inactivo','activo') WHERE id=?")->execute([$id]);
        $nuevo = $pdo->prepare("SELECT estado FROM personeros WHERE id=?");
        $nuevo->execute([$id]);
        json_resp(['ok'=>true,'estado'=>$nuevo->fetchColumn()]);
    }

    // ── Preparar correo masivo ─────────────────────────────────
    if ($action === 'prepare_email') {
        $asunto = trim($_POST['asunto'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        $alcance = $_POST['alcance'] ?? 'grupo';
        $ids_raw = trim($_POST['selected_ids'] ?? '');

        if ($asunto === '' || $mensaje === '') {
            json_resp(['ok' => false, 'error' => 'Completa asunto y cuerpo del correo.']);
        }

        if ($alcance === 'masivo') {
            $ids = $pdo->query("SELECT id FROM personeros WHERE estado='activo'")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_raw)))));
            $alcance = count($ids) === 1 ? 'individual' : 'grupo';
        }

        if (empty($ids)) {
            json_resp(['ok' => false, 'error' => 'Selecciona al menos un personero activo.']);
        }

        try {
            $attachment = upload_email_attachment_personero();
        } catch (RuntimeException $e) {
            json_resp(['ok' => false, 'error' => $e->getMessage()]);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO personero_mensajes
                 (canal, asunto, mensaje, alcance, creado_por, adjunto_nombre, adjunto_ruta, adjunto_tipo, adjunto_tamanio)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                'correo',
                $asunto,
                $mensaje,
                $alcance,
                $_SESSION['admin_id'] ?? null,
                $attachment['name'],
                $attachment['path'],
                $attachment['mime'],
                $attachment['size'],
            ]);
            $mensaje_id = (int)$pdo->lastInsertId();

            $canal_stmt = $pdo->prepare(
                "INSERT IGNORE INTO personero_mensaje_canales (mensaje_id, canal) VALUES (?, ?)"
            );
            $canal_stmt->execute([$mensaje_id, 'correo']);

            $dest = $pdo->prepare(
                "INSERT IGNORE INTO personero_mensaje_destinatarios (mensaje_id, personero_id) VALUES (?, ?)"
            );
            foreach ($ids as $pid) {
                $dest->execute([$mensaje_id, $pid]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_resp(['ok' => false, 'error' => 'No se pudo preparar el envio.']);
        }

        $recipients_stmt = $pdo->prepare(
            "SELECT id, nombres, apellidos, correo
             FROM personeros
             WHERE estado='activo' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
        );
        $recipients_stmt->execute($ids);
        $recipients_to_send = $recipients_stmt->fetchAll(PDO::FETCH_ASSOC);

        log_activity($pdo, 'Preparo correo masivo a personeros: mensaje_id=' . $mensaje_id, 'personeros');
        json_resp([
            'ok' => true,
            'mensaje_id' => $mensaje_id,
            'total' => count($recipients_to_send),
            'recipients' => array_map(fn($r) => [
                'id' => (int)$r['id'],
                'nombre' => trim($r['apellidos'] . ' ' . $r['nombres']),
                'correo' => $r['correo'],
            ], $recipients_to_send),
        ]);
    }

    // ── Enviar correo a un destinatario individual ────────────
    if ($action === 'send_email_recipient') {
        $mensaje_id = (int)($_POST['mensaje_id'] ?? 0);
        $personero_id = (int)($_POST['personero_id'] ?? 0);

        if ($mensaje_id <= 0 || $personero_id <= 0) {
            json_resp(['ok' => false, 'error' => 'Datos de envio incompletos.']);
        }

        $row_stmt = $pdo->prepare(
            "SELECT pm.asunto, pm.mensaje, pm.adjunto_nombre, pm.adjunto_ruta, pm.adjunto_tipo,
                    p.id AS personero_id, p.nombres, p.apellidos, p.correo, d.estado
             FROM personero_mensaje_destinatarios d
             INNER JOIN personero_mensajes pm ON pm.id = d.mensaje_id
             INNER JOIN personeros p ON p.id = d.personero_id
             WHERE d.mensaje_id = ? AND d.personero_id = ?
             LIMIT 1"
        );
        $row_stmt->execute([$mensaje_id, $personero_id]);
        $row = $row_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_resp(['ok' => false, 'error' => 'Destinatario no encontrado.']);
        }

        $nombre_completo = trim($row['apellidos'] . ' ' . $row['nombres']);

        $status_stmt = $pdo->prepare(
            "UPDATE personero_mensaje_destinatarios
             SET estado=?, enviado_en=?, error=?
             WHERE mensaje_id=? AND personero_id=?"
        );

        $attachments = [];
        if (!empty($row['adjunto_ruta'])) {
            $path = realpath(__DIR__ . '/../' . $row['adjunto_ruta']);
            $uploads_root = realpath(__DIR__ . '/../uploads/personeros-correo');
            if ($path && $uploads_root && str_starts_with($path, $uploads_root) && is_file($path)) {
                $attachments[] = [
                    'path' => $path,
                    'name' => $row['adjunto_nombre'] ?: basename($path),
                    'mime' => $row['adjunto_tipo'] ?: 'application/octet-stream',
                ];
            }
        }

        $send_error = null;
        $sent = smtp_send_mail(
            trim((string)$row['correo']),
            $nombre_completo,
            (string)$row['asunto'],
            (string)$row['mensaje'],
            $send_error,
            $attachments
        );

        $status_stmt->execute([
            $sent ? 'enviado' : 'fallido',
            $sent ? date('Y-m-d H:i:s') : null,
            $sent ? null : $send_error,
            $mensaje_id,
            $personero_id,
        ]);

        json_resp([
            'ok' => true,
            'sent' => $sent,
            'estado' => $sent ? 'enviado' : 'fallido',
            'error' => $sent ? null : $send_error,
            'recipient' => [
                'id' => $personero_id,
                'nombre' => $nombre_completo,
                'correo' => $row['correo'],
            ],
        ]);
    }

    // ── Plantillas de WhatsApp ─────────────────────────────────
    if ($action === 'save_wa_plantilla') {
        $pid = (int)($_POST['pid'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        if ($nombre === '' || $contenido === '') {
            json_resp(['ok' => false, 'error' => 'Nombre y contenido son obligatorios.']);
        }
        if ($pid > 0) {
            $pdo->prepare("UPDATE personero_wa_plantillas SET nombre=?, contenido=? WHERE id=?")
                ->execute([$nombre, $contenido, $pid]);
            json_resp(['ok' => true, 'id' => $pid, 'nombre' => $nombre, 'contenido' => $contenido]);
        } else {
            $pdo->prepare("INSERT INTO personero_wa_plantillas (nombre, contenido) VALUES (?, ?)")
                ->execute([$nombre, $contenido]);
            $new_id = (int)$pdo->lastInsertId();
            json_resp(['ok' => true, 'id' => $new_id, 'nombre' => $nombre, 'contenido' => $contenido]);
        }
    }

    if ($action === 'delete_wa_plantilla') {
        $pid = (int)($_POST['pid'] ?? 0);
        if ($pid <= 0) json_resp(['ok' => false, 'error' => 'ID invalido.']);
        $pdo->prepare("DELETE FROM personero_wa_plantillas WHERE id=?")->execute([$pid]);
        json_resp(['ok' => true]);
    }
}

// ── Cargar datos ──────────────────────────────────────────────
$personeros = $pdo->query(
    "SELECT * FROM personeros ORDER BY cargo ASC, apellidos ASC, nombres ASC"
)->fetchAll();

$personeros_activos_email = $pdo->query(
    "SELECT id, nombres, apellidos, dni, correo, cargo
     FROM personeros
     WHERE estado='activo'
     ORDER BY apellidos ASC, nombres ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$wa_plantillas_personeros = $pdo->query(
    "SELECT id, nombre, contenido FROM personero_wa_plantillas ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$total     = count($personeros);
$titulares = count(array_filter($personeros, fn($p) => $p['cargo'] === 'titular'));
$alternos  = $total - $titulares;
$activos   = count(array_filter($personeros, fn($p) => $p['estado'] === 'activo'));

// ── Pre-llenado desde simpatizante ─────────────────────────────
// (No existe modulo "militantes" en credenciales-app, se omite ese origen)
$prefill = [];
if (isset($_GET['from_simpatizante'])) {
    $sid = (int)$_GET['from_simpatizante'];
    $s = $pdo->prepare("SELECT * FROM simpatizantes WHERE id=?");
    $s->execute([$sid]);
    $row = $s->fetch();
    if ($row) $prefill = [
        'nombres'   => $row['nombre'] ?? '',
        'apellidos' => '',
        'dni'       => $row['dni'] ?? '',
        'celular'   => $row['celular'] ?? $row['telefono'] ?? '',
        'whatsapp'  => $row['whatsapp'] ?? '',
        'correo'    => $row['correo'] ?? '',
        'origen'    => 'simpatizante',
        'origen_id' => $sid,
    ];
}

// ── URLs para PDF ─────────────────────────────────────────────
$pdf_embed_url    = 'exportar-personeros-pdf.php?embed=1';
$pdf_download_url = 'exportar-personeros-pdf.php?download=1';
$pdf_open_url     = 'exportar-personeros-pdf.php';

include __DIR__ . '/layout.php';
?>

<style>
.cargo-titular { background:#1E3A8A; color:#fff; }
.cargo-alterno { background:#059669; color:#fff; }
.origen-badge-manual      { background:#F3F4F6; color:#374151; }
.origen-badge-militante   { background:#EDE9FE; color:#6D28D9; }
.origen-badge-simpatizante{ background:#FEF3C7; color:#92400E; }
</style>

<div class="space-y-5"
     x-data="personeroApp()"
     x-init="init()"
     @keydown.escape.window="closeModal()">

  <!-- ── Header ────────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-xl font-black text-gray-800">Personeros</h2>
      <p class="text-xs text-gray-400 mt-0.5">Gestión de personeros electorales del partido</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      <button @click="openPdf('')"
              class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white
                     text-sm font-bold px-4 py-2.5 rounded-xl shadow transition-all">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
        </svg>
        Vista PDF
      </button>
      <button @click="openMensaje()"
              class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 text-white
                     text-sm font-bold px-4 py-2.5 rounded-xl shadow transition-all">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 7l8 6 8-6"/>
        </svg>
        Crear Mensaje
      </button>
      <button @click="openWaPlantillas()"
              class="inline-flex items-center gap-2 text-white text-sm font-black px-4 py-2.5 rounded-xl shadow transition-all"
              style="background:linear-gradient(135deg,#128C7E,#25D366);border:1px solid #075E54">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
        Crear SMS
      </button>
      <button @click="openModal()"
              class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 text-white
                     text-sm font-bold px-4 py-2.5 rounded-xl shadow transition-all">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nuevo Personero
      </button>
    </div>
  </div>

  <!-- ── Stats ─────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <?php foreach([
      ['Total',     $total,     '#1E3A8A','M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
      ['Titulares', $titulares, '#1E3A8A','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
      ['Alternos',  $alternos,  '#059669','M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
      ['Activos',   $activos,   '#0369A1','M5 13l4 4L19 7'],
    ] as [$label,$val,$color,$path]): ?>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
           style="background:<?= $color ?>1a">
        <svg class="w-5 h-5" style="color:<?= $color ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $path ?>"/>
        </svg>
      </div>
      <div>
        <div class="text-xl font-black text-gray-800"><?= $val ?></div>
        <div class="text-xs text-gray-400"><?= $label ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── App Alpine ─────────────────────────────────────────── -->
  <div>

    <!-- Filtros -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-4">
      <div class="flex flex-wrap gap-3 items-center">
        <div class="relative flex-1 min-w-[200px]">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
          </svg>
          <input x-model="filtro.busqueda" type="text" placeholder="Buscar por nombre, DNI..."
                 class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-xl
                        focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
        </div>
        <select x-model="filtro.cargo"
                class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-[#1E3A8A]">
          <option value="">Todos los cargos</option>
          <option value="titular">Titular</option>
          <option value="alterno">Alterno</option>
        </select>
        <select x-model="filtro.origen"
                class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-[#1E3A8A]">
          <option value="">Todos los orígenes</option>
          <option value="manual">Manual</option>
          <option value="militante">Militante</option>
          <option value="simpatizante">Simpatizante</option>
        </select>
        <select x-model="filtro.estado"
                class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-[#1E3A8A]">
          <option value="">Todos</option>
          <option value="activo">Activos</option>
          <option value="inactivo">Inactivos</option>
        </select>
        <button @click="filtro={busqueda:'',cargo:'',origen:'',estado:''}"
                class="text-xs text-gray-400 hover:text-gray-600 px-2">Limpiar</button>
      </div>
    </div>

    <!-- ── Tabla PRO ─────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

      <!-- Counter row -->
      <div class="px-5 py-3 border-b border-gray-50 flex items-center justify-between">
        <span class="text-xs text-gray-400">
          Mostrando <span class="font-bold text-gray-600" x-text="filtrados.length"></span>
          de <span class="font-bold text-gray-600" x-text="personeros.length"></span> personeros
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide w-10">#</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Personero</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Documento</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Cargo</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Contacto</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Local / Mesa</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Origen</th>
              <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Estado</th>
              <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase tracking-wide">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">

            <!-- Empty state -->
            <template x-if="filtrados.length === 0">
              <tr>
                <td colspan="9" class="px-4 py-14 text-center">
                  <div class="flex flex-col items-center gap-2">
                    <svg class="w-10 h-10 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <p class="text-gray-400 font-semibold text-sm">No hay personeros que coincidan</p>
                    <p class="text-gray-300 text-xs">Ajusta los filtros o agrega un nuevo personero</p>
                  </div>
                </td>
              </tr>
            </template>

            <!-- Filas -->
            <template x-for="(p, idx) in filtrados" :key="p.id">
              <tr class="hover:bg-blue-50/30 transition-colors"
                  :class="p.estado === 'inactivo' ? 'opacity-60' : ''">

                <!-- # -->
                <td class="px-4 py-3 text-gray-400 text-xs font-mono" x-text="idx + 1"></td>

                <!-- Personero (foto + nombre) -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-3">
                    <!-- Avatar -->
                    <div class="w-9 h-9 rounded-xl overflow-hidden flex-shrink-0 bg-[#1E3A8A] flex items-center justify-center">
                      <template x-if="p.foto">
                        <img :src="'<?= BASE_URL ?>/' + p.foto" :alt="p.nombres"
                             class="w-9 h-9 object-cover">
                      </template>
                      <template x-if="!p.foto">
                        <span class="text-white font-black text-sm leading-none"
                              x-text="(p.apellidos[0]||'').toUpperCase()+(p.nombres[0]||'').toUpperCase()"></span>
                      </template>
                    </div>
                    <!-- Nombre -->
                    <div>
                      <div class="font-bold text-gray-800 text-sm leading-tight"
                           x-text="p.apellidos + ', ' + p.nombres"></div>
                      <div x-show="p.correo" class="text-xs text-gray-400 truncate max-w-[160px]"
                           x-text="p.correo"></div>
                    </div>
                  </div>
                </td>

                <!-- Documento -->
                <td class="px-4 py-3">
                  <span x-show="p.dni" class="font-mono text-gray-700 text-xs" x-text="p.dni"></span>
                  <span x-show="!p.dni && p.carnet_extranjeria" class="text-xs text-gray-500">
                    <span class="text-gray-400 text-[10px]">CE</span>
                    <span class="font-mono text-gray-700" x-text="p.carnet_extranjeria"></span>
                  </span>
                  <span x-show="!p.dni && !p.carnet_extranjeria" class="text-gray-300 text-xs">—</span>
                </td>

                <!-- Cargo -->
                <td class="px-4 py-3">
                  <span class="inline-flex items-center text-[11px] font-black px-2.5 py-1 rounded-full uppercase tracking-wide"
                        :class="p.cargo === 'titular'
                          ? 'bg-[#1E3A8A] text-white'
                          : 'bg-emerald-100 text-emerald-800'"
                        x-text="p.cargo"></span>
                </td>

                <!-- Contacto -->
                <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                  <div x-show="p.celular" class="flex items-center gap-1">
                    <svg class="w-3 h-3 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    <span x-text="p.celular"></span>
                  </div>
                  <span x-show="!p.celular && !p.whatsapp" class="text-gray-300">—</span>
                </td>

                <!-- Local / Mesa -->
                <td class="px-4 py-3 text-xs text-gray-600 max-w-[160px]">
                  <div x-show="p.local_votacion" class="truncate font-medium" x-text="p.local_votacion"></div>
                  <div x-show="p.numero_mesa" class="text-gray-400 text-[11px]"
                       x-text="'Mesa ' + p.numero_mesa"></div>
                  <span x-show="!p.local_votacion && !p.numero_mesa" class="text-gray-300">—</span>
                </td>

                <!-- Origen -->
                <td class="px-4 py-3">
                  <span class="inline-block text-[11px] font-bold px-2.5 py-1 rounded-full"
                        :class="{
                          'bg-gray-100 text-gray-600':         p.origen === 'manual',
                          'bg-violet-100 text-violet-700':     p.origen === 'militante',
                          'bg-amber-100 text-amber-700':       p.origen === 'simpatizante'
                        }"
                        x-text="p.origen.charAt(0).toUpperCase() + p.origen.slice(1)"></span>
                </td>

                <!-- Estado -->
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full"
                        :class="p.estado === 'activo'
                          ? 'bg-green-100 text-green-700'
                          : 'bg-red-100 text-red-600'">
                    <span class="w-1.5 h-1.5 rounded-full"
                          :class="p.estado === 'activo' ? 'bg-green-500' : 'bg-red-400'"></span>
                    <span x-text="p.estado === 'activo' ? 'Activo' : 'Inactivo'"></span>
                  </span>
                </td>

                <!-- Acciones -->
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-1.5">
                    <!-- Editar -->
                    <button @click="openModal(p)" title="Editar"
                            class="w-8 h-8 flex items-center justify-center rounded-xl
                                   bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>
                    <!-- WhatsApp -->
                    <button @click="openWaEnviar(p)" x-show="p.whatsapp || p.celular" title="Enviar WhatsApp"
                            class="w-8 h-8 flex items-center justify-center rounded-xl
                                   bg-green-50 text-green-600 hover:bg-green-100 transition-colors">
                      <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                      </svg>
                    </button>
                    <!-- Toggle estado -->
                    <button @click="toggleEstado(p)"
                            :title="p.estado === 'activo' ? 'Inactivar' : 'Activar'"
                            class="w-8 h-8 flex items-center justify-center rounded-xl transition-colors"
                            :class="p.estado === 'activo'
                              ? 'bg-amber-50 text-amber-600 hover:bg-amber-100'
                              : 'bg-green-50 text-green-600 hover:bg-green-100'">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              :d="p.estado === 'activo'
                                ? 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636'
                                : 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'"/>
                      </svg>
                    </button>
                    <!-- Eliminar -->
                    <button @click="confirmarEliminar(p)" title="Eliminar"
                            class="w-8 h-8 flex items-center justify-center rounded-xl
                                   bg-red-50 text-red-500 hover:bg-red-100 transition-colors">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </td>

              </tr>
            </template>

          </tbody>
        </table>
      </div>
    </div>

    <!-- ══ MODAL ADD / EDIT ══════════════════════════════════ -->
    <div x-show="modal.open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
      <div @click.outside="closeModal()"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 scale-95"
           x-transition:enter-end="opacity-100 scale-100"
           class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">

        <!-- Header modal -->
        <div class="bg-[#1E3A8A] px-6 py-4 flex items-center justify-between sticky top-0 z-10 rounded-t-2xl">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
              <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <h3 class="text-white font-black text-sm"
                x-text="modal.id ? 'Editar Personero' : 'Nuevo Personero'"></h3>
          </div>
          <button @click="closeModal()" class="text-white/70 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Form -->
        <form @submit.prevent="guardar()" class="p-6 space-y-5" enctype="multipart/form-data" id="form-personero">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_personero">
          <input type="hidden" name="id" x-model="modal.id">
          <input type="hidden" name="origen" x-model="modal.origen">
          <input type="hidden" name="origen_id" x-model="modal.origen_id">

          <!-- Cargo -->
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">
              Cargo <span class="text-red-500">*</span>
            </label>
            <div class="grid grid-cols-2 gap-3">
              <label class="relative cursor-pointer">
                <input type="radio" name="cargo" value="titular" x-model="modal.cargo" class="sr-only peer">
                <div class="flex items-center gap-2 px-4 py-3 rounded-xl border-2 transition-all
                            peer-checked:border-[#1E3A8A] peer-checked:bg-blue-50 border-gray-200">
                  <div class="w-3 h-3 rounded-full border-2 border-current peer-checked:bg-[#1E3A8A]
                              flex items-center justify-center" :class="modal.cargo==='titular'?'border-[#1E3A8A]':'border-gray-300'">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#1E3A8A]" x-show="modal.cargo==='titular'"></div>
                  </div>
                  <span class="text-sm font-bold" :class="modal.cargo==='titular'?'text-[#1E3A8A]':'text-gray-600'">
                    🏅 Titular
                  </span>
                </div>
              </label>
              <label class="relative cursor-pointer">
                <input type="radio" name="cargo" value="alterno" x-model="modal.cargo" class="sr-only peer">
                <div class="flex items-center gap-2 px-4 py-3 rounded-xl border-2 transition-all
                            peer-checked:border-emerald-600 peer-checked:bg-emerald-50 border-gray-200">
                  <div class="w-3 h-3 rounded-full border-2 flex items-center justify-center"
                       :class="modal.cargo==='alterno'?'border-emerald-600':'border-gray-300'">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-600" x-show="modal.cargo==='alterno'"></div>
                  </div>
                  <span class="text-sm font-bold" :class="modal.cargo==='alterno'?'text-emerald-700':'text-gray-600'">
                    🔄 Alterno
                  </span>
                </div>
              </label>
            </div>
          </div>

          <!-- DNI / Carnet -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">DNI (8 dígitos)</label>
              <div class="relative">
                <input type="text" name="dni" x-model="modal.dni" maxlength="8" pattern="\d{8}"
                       @input="if(modal.dni.replace(/\D/g,'').length===8) buscarDniPersonero()"
                       placeholder="12345678"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                              focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none pr-8">
                <span x-show="reniecLoading" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[#1E3A8A] text-xs animate-spin">⟳</span>
              </div>
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Carnet Extranjería (9 dig.)</label>
              <input type="text" name="carnet_extranjeria" x-model="modal.carnet_extranjeria" maxlength="9" pattern="\d{9}"
                     placeholder="123456789"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
          </div>

          <!-- Nombres y Apellidos -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">
                Nombres <span class="text-red-500">*</span>
              </label>
              <input type="text" name="nombres" x-model="modal.nombres" required
                     placeholder="Ej: Juan Carlos"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">
                Apellidos <span class="text-red-500">*</span>
              </label>
              <input type="text" name="apellidos" x-model="modal.apellidos" required
                     placeholder="Ej: Pérez López"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
          </div>

          <!-- Edad / Nacionalidad -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Edad</label>
              <input type="number" name="edad" x-model="modal.edad" min="18" max="99"
                     placeholder="Ej: 35"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Nacionalidad</label>
              <input type="text" name="nacionalidad" x-model="modal.nacionalidad"
                     placeholder="Peruana"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
          </div>

          <!-- Contacto -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Celular</label>
              <input type="text" name="celular" x-model="modal.celular" maxlength="20"
                     placeholder="987654321"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">WhatsApp</label>
              <input type="text" name="whatsapp" x-model="modal.whatsapp" maxlength="20"
                     placeholder="987654321"
                     class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                            focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
            </div>
          </div>

          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Correo electrónico</label>
            <input type="email" name="correo" x-model="modal.correo"
                   placeholder="correo@ejemplo.com"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                          focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
          </div>

          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Dirección de domicilio</label>
            <input type="text" name="direccion" x-model="modal.direccion"
                   placeholder="Av. Principal 123, Satipo"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm
                          focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
          </div>

          <!-- Sección electoral (opcional) -->
          <div class="bg-blue-50 rounded-xl p-4 space-y-4 border border-blue-100">
            <p class="text-xs font-black text-blue-700 uppercase tracking-widest">
              📍 Asignación Electoral <span class="font-normal text-blue-400">(se completa después)</span>
            </p>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Local de Votación</label>
                <input type="text" name="local_votacion" x-model="modal.local_votacion"
                       placeholder="IE N° 30005 - Satipo"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white
                              focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
              </div>
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">N° de Mesa Asignada</label>
                <input type="text" name="numero_mesa" x-model="modal.numero_mesa"
                       placeholder="Ej: 024"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white
                              focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
              </div>
            </div>
          </div>

          <!-- Foto -->
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Fotografía</label>
            <div class="flex items-center gap-4">
              <div class="w-16 h-16 rounded-full bg-gray-100 border-2 border-dashed border-gray-300
                          flex items-center justify-center overflow-hidden flex-shrink-0" id="foto-preview-wrap">
                <template x-if="modal.foto_preview">
                  <img :src="modal.foto_preview" class="w-full h-full object-cover rounded-full" id="foto-preview-img">
                </template>
                <template x-if="!modal.foto_preview">
                  <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                </template>
              </div>
              <div class="flex-1">
                <input type="file" name="foto" accept="image/*" id="foto-input"
                       @change="previewFoto($event)"
                       class="text-sm text-gray-500 file:mr-3 file:py-2 file:px-4
                              file:rounded-xl file:border-0 file:text-xs file:font-bold
                              file:bg-[#1E3A8A] file:text-white hover:file:bg-blue-900
                              cursor-pointer w-full">
                <p class="text-xs text-gray-400 mt-1">JPG, PNG o WEBP · Máx 4MB</p>
              </div>
            </div>
          </div>

          <!-- Error -->
          <div x-show="modal.error" x-cloak
               class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700"
               x-text="modal.error"></div>

          <!-- Botones -->
          <div class="flex gap-3 pt-2">
            <button type="button" @click="closeModal()"
                    class="flex-1 border border-gray-200 text-gray-600 font-semibold py-3
                           rounded-xl text-sm hover:bg-gray-50 transition-colors">
              Cancelar
            </button>
            <button type="submit" :disabled="modal.saving"
                    class="flex-1 bg-[#1E3A8A] hover:bg-blue-900 disabled:opacity-60
                           text-white font-black py-3 rounded-xl text-sm transition-all
                           flex items-center justify-center gap-2">
              <svg x-show="modal.saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <span x-text="modal.saving ? 'Guardando...' : (modal.id ? 'Actualizar' : 'Registrar')"></span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ══ MODAL VISTA PDF ══════════════════════════════════════ -->
    <div x-show="pdfModal" x-cloak
         class="fixed inset-0 z-[95] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-950/60 backdrop-blur-sm" @click="closePdf()"></div>
      <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100
                  w-full max-w-6xl h-[90vh] overflow-hidden flex flex-col">

        <!-- Cabecera del modal -->
        <div class="bg-[#1E3A8A] px-5 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 flex-shrink-0">
          <div>
            <h2 class="text-white font-black text-lg leading-tight">Vista previa — Personeros</h2>
            <p class="text-blue-200 text-xs mt-0.5">Previsualización del listado para imprimir o descargar como PDF</p>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <!-- Filtro rápido cargo -->
            <select id="pdf-cargo-filter"
                    class="text-xs border border-white/30 bg-white/10 text-white rounded-lg px-2.5 py-1.5
                           focus:outline-none focus:ring-2 focus:ring-white/30"
                    @change="openPdf($event.target.value)"
              <option value="">Todos los cargos</option>
              <option value="titular">Solo Titulares</option>
              <option value="alterno">Solo Alternos</option>
            </select>
            <!-- Botón Imprimir -->
            <button type="button" @click="printPdf()"
                    class="inline-flex items-center gap-1.5 bg-yellow-400 hover:bg-yellow-300
                           text-[#1E3A8A] text-xs font-black px-3.5 py-2 rounded-xl transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
              </svg>
              Imprimir
            </button>
            <!-- Botón Descargar -->
            <a :href="pdfDownloadUrl"
               class="inline-flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-400
                      text-white text-xs font-black px-3.5 py-2 rounded-xl transition-colors no-underline">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
              </svg>
              Descargar PDF
            </a>
            <!-- Botón Abrir en nueva pestaña -->
            <a :href="pdfOpenUrl" target="_blank"
               class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20
                      text-white text-xs font-bold px-3.5 py-2 rounded-xl transition-colors no-underline">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
              </svg>
              Abrir
            </a>
            <!-- Cerrar -->
            <button type="button" @click="closePdf()"
                    class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20
                           text-white text-xs font-bold px-3.5 py-2 rounded-xl transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
              Cerrar
            </button>
          </div>
        </div>

        <!-- Área iframe -->
        <div class="bg-gray-100 p-3 flex-1 min-h-0">
          <iframe x-ref="pdfFrame"
                  :src="pdfEmbedUrl"
                  class="w-full h-full bg-white rounded-xl border border-gray-200"
                  title="Vista previa personeros"></iframe>
        </div>
      </div>
    </div>

    <!-- ══ MODAL CONFIRMAR ELIMINAR ════════════════════════════ -->
    <div x-show="confirmDel.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
        <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-7 h-7 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
        </div>
        <h3 class="text-lg font-black text-gray-800 mb-1">¿Eliminar personero?</h3>
        <p class="text-sm text-gray-500 mb-5" x-text="'Se eliminará a ' + confirmDel.nombre + ' de forma permanente.'"></p>
        <div class="flex gap-3">
          <button @click="confirmDel.open=false"
                  class="flex-1 border border-gray-200 text-gray-600 font-semibold py-2.5 rounded-xl text-sm hover:bg-gray-50">
            Cancelar
          </button>
          <button @click="eliminar()"
                  class="flex-1 bg-red-500 hover:bg-red-600 text-white font-black py-2.5 rounded-xl text-sm transition-colors">
            Eliminar
          </button>
        </div>
      </div>
    </div>

  <!-- ── Modal: Crear correo masivo ──────────────────────────── -->
  <div x-show="mensajeModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="!sending && (mensajeModal = false)"></div>
    <form method="POST" enctype="multipart/form-data" @submit.prevent="sendCampaign($event)"
          class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-4xl max-h-[92vh] overflow-hidden flex flex-col">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="action" value="prepare_email">
      <input type="hidden" name="alcance" :value="mensaje.alcance">
      <input type="hidden" name="selected_ids" :value="selected.join(',')">

      <div class="bg-[#1E3A8A] px-6 py-4">
        <h2 class="text-white font-black text-lg">Crear correo masivo</h2>
        <p class="text-blue-100 text-sm mt-1">Redacta el correo, selecciona destinatarios y envia.</p>
      </div>

      <div class="p-6 space-y-5 overflow-y-auto">
        <template x-if="formError">
          <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm font-bold" x-text="formError"></div>
        </template>

        <div class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 flex items-center justify-between gap-3">
          <div>
            <p class="text-xs font-black text-[#1E3A8A] uppercase">Canal disponible</p>
            <p class="text-sm font-bold text-gray-800">Correo electronico</p>
          </div>
          <span class="bg-white text-[#1E3A8A] text-xs font-black px-3 py-1.5 rounded-full border border-blue-100">SMTP</span>
        </div>

        <div>
          <p class="text-xs font-black text-[#1E3A8A] uppercase mb-2">Paso 1: redactar correo</p>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Asunto</label>
          <input name="asunto" required placeholder="Ej. Reunion de coordinacion electoral"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none mb-3">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cuerpo del correo</label>
          <textarea name="mensaje" rows="5" required
                    placeholder="Escribe el contenido que recibiran los personeros..."
                    class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none resize-y"></textarea>
        </div>

        <div>
          <p class="text-xs font-black text-[#1E3A8A] uppercase mb-2">Adjunto opcional</p>
          <label class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border border-dashed border-gray-300 rounded-xl px-4 py-4 bg-gray-50 hover:bg-gray-100 transition-colors cursor-pointer">
            <span class="min-w-0">
              <span class="block text-sm font-black text-gray-800">Adjuntar documento</span>
              <span class="block text-xs text-gray-400 mt-1">Imagen, PDF, DOC o DOCX. Maximo 8 MB.</span>
              <span class="block text-xs text-[#1E3A8A] font-bold mt-2 truncate" x-text="attachmentName || 'Sin archivo seleccionado'"></span>
            </span>
            <span class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-white border border-gray-200 text-[#1E3A8A] text-xs font-black">
              Seleccionar archivo
            </span>
            <input type="file" name="adjunto" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
                   @change="attachmentName = $event.target.files[0]?.name || ''"
                   class="hidden">
          </label>
        </div>

        <div>
          <p class="text-xs font-black text-[#1E3A8A] uppercase mb-2">Paso 2: destinatarios</p>
          <div class="flex flex-wrap items-center gap-2 mb-3">
            <button type="button" @click="mensaje.alcance='grupo'; selected=[]"
                    class="px-3 py-2 rounded-lg text-xs font-bold border"
                    :class="mensaje.alcance === 'grupo' ? 'bg-[#1E3A8A] text-white border-[#1E3A8A]' : 'bg-white text-gray-600 border-gray-200'">
              Seleccionar personeros
            </button>
            <button type="button" @click="mensaje.alcance='masivo'; selected=[...allIds]"
                    class="px-3 py-2 rounded-lg text-xs font-bold border"
                    :class="mensaje.alcance === 'masivo' ? 'bg-[#1E3A8A] text-white border-[#1E3A8A]' : 'bg-white text-gray-600 border-gray-200'">
              Todos los activos
            </button>
            <span class="text-xs text-gray-400" x-text="selected.length + ' destinatario(s)'"></span>
          </div>
          <div x-show="mensaje.alcance !== 'masivo'" class="space-y-3">
            <input type="search" x-model="recipientSearch" placeholder="Buscar por nombre, DNI, correo o cargo..."
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
            <div class="border border-gray-100 rounded-xl max-h-64 overflow-y-auto divide-y divide-gray-50">
            <template x-for="p in filteredRecipients()" :key="p.id">
              <label class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                <input type="checkbox" :value="String(p.id)" x-model="selected" class="rounded border-gray-300 text-[#1E3A8A]">
                <span class="min-w-0">
                  <span class="block text-sm font-bold text-gray-800 truncate" x-text="p.nombre"></span>
                  <span class="block text-xs text-gray-400 truncate" x-text="(p.correo || 'Sin correo') + ' - ' + (p.cargo || 'Sin cargo')"></span>
                </span>
              </label>
            </template>
            <div x-show="filteredRecipients().length === 0" class="px-4 py-6 text-sm text-gray-400 text-center">
              Sin coincidencias.
            </div>
            </div>
          </div>
        </div>

        <div class="bg-blue-50 text-blue-800 rounded-xl p-4 text-xs leading-relaxed">
          El sistema intentara enviar el mismo correo a cada destinatario seleccionado y guardara el resultado individual
          como enviado o fallido.
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3 shrink-0 border-t border-gray-100">
        <button type="button" @click="mensajeModal = false"
                :disabled="sending"
                class="btn-pro px-5 py-2.5 text-sm bg-white text-gray-600 border-gray-200 hover:text-[#1E3A8A]">
          Cancelar
        </button>
        <button type="submit"
                :disabled="selected.length === 0 || sending"
                :class="selected.length === 0 || sending ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-900'"
                class="btn-pro btn-primary-pro px-5 py-2.5 text-sm">
          <span x-text="sending ? 'Preparando...' : 'Enviar correo'"></span>
        </button>
      </div>
    </form>
  </div>

  <!-- ── Modal: Progreso de envio de correo ──────────────────── -->
  <div x-show="progressModal" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-950/60 backdrop-blur-sm"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-3xl overflow-hidden">
      <div class="bg-[#1E3A8A] px-6 py-4">
        <h2 class="text-white font-black text-lg">Enviando correos</h2>
        <p class="text-blue-100 text-sm mt-1" x-text="progress.finished ? 'Proceso finalizado' : 'No cierres esta ventana mientras se completa el envio.'"></p>
      </div>
      <div class="p-6 space-y-5">
        <div>
          <div class="flex items-center justify-between text-sm font-bold text-gray-700 mb-2">
            <span x-text="progress.sent + progress.failed + ' de ' + progress.total"></span>
            <span x-text="progressPercent() + '%'"></span>
          </div>
          <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-[#1E3A8A] transition-all" :style="'width:' + progressPercent() + '%'"></div>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
            <p class="text-xs text-gray-400 font-black uppercase">Total</p>
            <p class="text-2xl font-black text-gray-800" x-text="progress.total"></p>
          </div>
          <div class="rounded-xl bg-green-50 border border-green-100 p-4">
            <p class="text-xs text-green-600 font-black uppercase">Enviados</p>
            <p class="text-2xl font-black text-green-700" x-text="progress.sent"></p>
          </div>
          <div class="rounded-xl bg-red-50 border border-red-100 p-4">
            <p class="text-xs text-red-600 font-black uppercase">Fallidos</p>
            <p class="text-2xl font-black text-red-600" x-text="progress.failed"></p>
          </div>
        </div>

        <div class="border border-gray-100 rounded-xl max-h-72 overflow-y-auto divide-y divide-gray-50">
          <template x-for="item in progress.items" :key="item.id">
            <div class="px-4 py-3 flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-bold text-gray-800 truncate" x-text="item.nombre"></p>
                <p class="text-xs text-gray-400 truncate" x-text="item.correo || 'Sin correo'"></p>
                <p x-show="item.error" class="text-xs text-red-500 mt-1" x-text="item.error"></p>
              </div>
              <span class="text-xs font-black px-3 py-1.5 rounded-full whitespace-nowrap"
                    :class="item.estado === 'enviado' ? 'bg-green-50 text-green-700' : (item.estado === 'fallido' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700')"
                    x-text="item.estado"></span>
            </div>
          </template>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3 border-t border-gray-100">
        <button type="button" @click="closeProgress()"
                :disabled="!progress.finished"
                :class="!progress.finished ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-900'"
                class="btn-pro btn-primary-pro px-5 py-2.5 text-sm">
          Cerrar
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Gestión de plantillas WhatsApp ──────────────── -->
  <div x-show="waPlantillaModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="waPlantillaModal = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col">

      <div class="px-6 py-4 flex items-center gap-3" style="background:linear-gradient(135deg,#075E54,#128C7E)">
        <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
          </svg>
        </div>
        <div>
          <h2 class="text-white font-black text-lg">Plantillas de WhatsApp</h2>
          <p class="text-white/60 text-xs mt-0.5">Crea y gestiona los mensajes guardados.</p>
        </div>
      </div>

      <div class="p-6 space-y-5 overflow-y-auto flex-1">
        <template x-if="waError">
          <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm font-bold" x-text="waError"></div>
        </template>

        <div class="bg-gray-50 rounded-2xl border border-gray-100 p-5 space-y-4">
          <p class="text-xs font-black text-gray-500 uppercase" x-text="waForm.id ? 'Editar plantilla' : 'Nueva plantilla'"></p>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Nombre / asunto</label>
            <input x-model="waForm.nombre" placeholder="Ej. Invitación a reunión"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400 outline-none">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Contenido del mensaje</label>
            <textarea x-model="waForm.contenido" rows="4"
                      placeholder="Escribe aquí el mensaje que se enviará..."
                      class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400 outline-none resize-y"></textarea>
          </div>
          <div x-show="waForm.contenido.trim() !== ''" class="rounded-xl border border-green-100 bg-green-50 p-4">
            <p class="text-xs font-black text-green-700 uppercase mb-2">Vista previa del mensaje</p>
            <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed"
               x-text="(window.BRAND_NAME || 'El equipo') + ' te está enviando el siguiente mensaje:\n\n' + waForm.contenido.trim()"></p>
          </div>
          <div class="flex gap-2 justify-end">
            <button type="button" x-show="waForm.id" @click="waResetForm()"
                    class="btn-pro px-4 py-2.5 text-sm bg-white text-gray-500 border-gray-200">
              Cancelar edición
            </button>
            <button type="button" @click="waSavePlantilla()"
                    :disabled="waForm.nombre.trim()==='' || waForm.contenido.trim()==='' || waSaving"
                    :class="waForm.nombre.trim()==='' || waForm.contenido.trim()==='' || waSaving ? 'opacity-50 cursor-not-allowed' : ''"
                    class="btn-pro px-5 py-2.5 text-sm text-white font-black"
                    style="background:linear-gradient(135deg,#128C7E,#25D366);border-color:#075E54">
              <span x-text="waSaving ? 'Guardando...' : (waForm.id ? 'Actualizar' : 'Guardar plantilla')"></span>
            </button>
          </div>
        </div>

        <div>
          <p class="text-xs font-black text-gray-500 uppercase mb-3">Plantillas guardadas (<span x-text="waPlantillas.length"></span>)</p>
          <div x-show="waPlantillas.length === 0" class="text-center py-8 text-gray-400 text-sm">
            No hay plantillas aún. Crea la primera arriba.
          </div>
          <div class="space-y-3">
            <template x-for="p in waPlantillas" :key="p.id">
              <div class="border border-gray-100 rounded-2xl p-4 bg-white hover:shadow-sm transition-shadow">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0 flex-1">
                    <p class="font-black text-gray-800 text-sm truncate" x-text="p.nombre"></p>
                    <p class="text-xs text-gray-400 mt-1 line-clamp-2" x-text="p.contenido"></p>
                  </div>
                  <div class="flex gap-1.5 flex-shrink-0">
                    <button type="button" @click="waEditPlantilla(p)"
                            class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 flex items-center justify-center transition-colors" title="Editar">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>
                    <button type="button" @click="waDeletePlantilla(p.id)"
                            class="w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 flex items-center justify-center transition-colors" title="Eliminar">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
        <button type="button" @click="waPlantillaModal = false"
                class="btn-pro px-5 py-2.5 text-sm bg-white text-gray-600 border-gray-200 hover:text-[#1E3A8A]">
          Cerrar
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Enviar WA a personero ─────────────────────────── -->
  <div x-show="waEnviarModal" x-cloak class="fixed inset-0 z-[85] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="waEnviarModal = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col max-h-[92vh]">

      <div class="px-6 py-4" style="background:linear-gradient(135deg,#075E54,#128C7E)">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
            <span class="text-white font-black text-sm" x-text="waCurrent.nombre ? waCurrent.nombre.charAt(0).toUpperCase() : 'P'"></span>
          </div>
          <div>
            <h2 class="text-white font-black text-base" x-text="waCurrent.nombre"></h2>
            <p class="text-white/60 text-xs" x-text="'WhatsApp: ' + waCurrent.whatsapp"></p>
          </div>
        </div>
      </div>

      <div class="p-6 space-y-4 overflow-y-auto flex-1">
        <div x-show="waPlantillas.length === 0" class="text-center py-8">
          <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
          </div>
          <p class="font-bold text-gray-500 text-sm">No hay plantillas guardadas.</p>
          <p class="text-xs text-gray-400 mt-1">Crea una usando el botón "Crear SMS".</p>
        </div>

        <div x-show="waPlantillas.length > 0">
          <p class="text-xs font-black text-gray-500 uppercase mb-3">Selecciona el mensaje a enviar</p>
          <div class="space-y-2">
            <template x-for="p in waPlantillas" :key="p.id">
              <label class="flex items-start gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all"
                     :class="waSelectedId === p.id
                       ? 'border-green-400 bg-green-50'
                       : 'border-gray-100 hover:border-gray-200 bg-white'">
                <div class="mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 transition-colors"
                     :class="waSelectedId === p.id ? 'border-green-500 bg-green-500' : 'border-gray-300'">
                  <svg x-show="waSelectedId === p.id" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                  </svg>
                </div>
                <div class="min-w-0 flex-1" @click="waSelectedId = p.id">
                  <p class="font-black text-gray-800 text-sm" x-text="p.nombre"></p>
                  <p class="text-xs text-gray-400 mt-1 line-clamp-2" x-text="p.contenido"></p>
                </div>
              </label>
            </template>
          </div>
        </div>

        <div x-show="waSelectedId !== null" class="rounded-2xl overflow-hidden border border-green-200">
          <div class="px-4 py-2 text-xs font-black text-green-700 uppercase" style="background:#DCF8C6">
            Vista previa del mensaje
          </div>
          <div class="p-4 bg-[#ECE5DD]">
            <div class="bg-white rounded-xl rounded-tl-none p-3 shadow-sm max-w-xs">
              <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed"
                 x-text="waPreviewText()"></p>
              <p class="text-right text-[10px] text-gray-400 mt-1">Ahora ✓✓</p>
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center gap-3">
        <button type="button" @click="waEnviarModal = false"
                class="flex-1 btn-pro px-4 py-2.5 text-sm bg-white text-gray-600 border-gray-200">
          Cancelar
        </button>
        <button type="button"
                @click="waEnviar()"
                :disabled="waSelectedId === null || waPlantillas.length === 0"
                :class="waSelectedId === null || waPlantillas.length === 0 ? 'opacity-40 cursor-not-allowed' : ''"
                class="flex-1 btn-pro px-4 py-2.5 text-sm text-white font-black flex items-center justify-center gap-2"
                style="background:linear-gradient(135deg,#128C7E,#25D366);border-color:#075E54">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
          </svg>
          Enviar por WhatsApp
        </button>
      </div>
    </div>
  </div>

  </div><!-- /x-data -->
</div>

<script>
const PERSONEROS_DATA = <?= json_encode(array_values($personeros), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const PREFILL_DATA    = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE) ?>;
const BASE_URL_JS     = '<?= BASE_URL ?>';
const CSRF_TOKEN      = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
const WA_PLANTILLAS_PERSONEROS = <?= json_encode(array_values($wa_plantillas_personeros), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
const PERSONEROS_EMAIL_DATA = <?= json_encode(array_values(array_map(
    fn($p) => [
        'id' => (string)$p['id'],
        'nombre' => trim($p['apellidos'] . ' ' . $p['nombres']),
        'dni' => $p['dni'] ?? '',
        'correo' => $p['correo'] ?? '',
        'cargo' => $p['cargo'] ?? '',
    ],
    $personeros_activos_email
)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
const PERSONEROS_EMAIL_IDS = <?= json_encode(array_values(array_map(
    fn($p) => (string)$p['id'],
    $personeros_activos_email
))) ?>;

function personeroApp() {
  return {
    personeros: [],
    filtro: { busqueda: '', cargo: '', origen: '', estado: '' },
    modal: {
      open: false, saving: false, error: '',
      id: 0, nombres: '', apellidos: '', dni: '', carnet_extranjeria: '',
      edad: '', correo: '', nacionalidad: 'Peruana', cargo: 'titular',
      celular: '', whatsapp: '', direccion: '', local_votacion: '',
      numero_mesa: '', foto_preview: '', foto_actual: '',
      origen: 'manual', origen_id: 0,
    },
    confirmDel: { open: false, id: 0, nombre: '' },
    reniecLoading: false,
    pdfModal:       false,
    pdfEmbedUrl:    '',
    pdfDownloadUrl: '<?= htmlspecialchars($pdf_download_url, ENT_QUOTES) ?>',
    pdfOpenUrl:     '<?= htmlspecialchars($pdf_open_url,     ENT_QUOTES) ?>',
    _pdfBase:       '<?= htmlspecialchars($pdf_embed_url,    ENT_QUOTES) ?>',

    // ── Mensajeria (correo masivo / WhatsApp) ─────────────────
    mensajeModal: false,
    progressModal: false,
    waPlantillaModal: false,
    waEnviarModal: false,
    waSaving: false,
    waError: '',
    waPlantillas: WA_PLANTILLAS_PERSONEROS,
    waForm: { id: null, nombre: '', contenido: '' },
    waCurrent: { id: 0, nombre: '', whatsapp: '' },
    waSelectedId: null,
    sending: false,
    formError: '',
    recipientSearch: '',
    attachmentName: '',
    selected: [],
    csrf: CSRF_TOKEN,
    recipients: PERSONEROS_EMAIL_DATA,
    allIds: PERSONEROS_EMAIL_IDS,
    mensaje: { alcance: 'grupo' },
    progress: { total: 0, sent: 0, failed: 0, finished: false, items: [] },

    init() {
      this.personeros = PERSONEROS_DATA;
      if (Object.keys(PREFILL_DATA).length > 0) {
        this.$nextTick(() => this.openModal(null, PREFILL_DATA));
      }
    },

    get filtrados() {
      const b = this.filtro.busqueda.toLowerCase();
      return this.personeros.filter(p => {
        const nombre = (p.apellidos + ' ' + p.nombres).toLowerCase();
        const doc    = (p.dni || p.carnet_extranjeria || '').toLowerCase();
        if (b && !nombre.includes(b) && !doc.includes(b)) return false;
        if (this.filtro.cargo   && p.cargo   !== this.filtro.cargo)   return false;
        if (this.filtro.origen  && p.origen  !== this.filtro.origen)  return false;
        if (this.filtro.estado  && p.estado  !== this.filtro.estado)  return false;
        return true;
      });
    },

    openModal(p = null, pre = null) {
      const src = p || pre || {};
      this.modal = {
        open: true, saving: false, error: '',
        id:                p ? p.id : 0,
        nombres:           src.nombres           || '',
        apellidos:         src.apellidos         || '',
        dni:               src.dni               || '',
        carnet_extranjeria:src.carnet_extranjeria || '',
        edad:              src.edad              || '',
        correo:            src.correo            || '',
        nacionalidad:      src.nacionalidad      || 'Peruana',
        cargo:             src.cargo             || 'titular',
        celular:           src.celular           || '',
        whatsapp:          src.whatsapp          || '',
        direccion:         src.direccion         || '',
        local_votacion:    src.local_votacion    || '',
        numero_mesa:       src.numero_mesa       || '',
        foto_preview:      p && p.foto ? BASE_URL_JS + '/' + p.foto : '',
        foto_actual:       p ? (p.foto || '') : '',
        origen:            src.origen   || 'manual',
        origen_id:         src.origen_id || 0,
      };
    },

    closeModal() {
      this.modal.open = false;
    },

    async buscarDniPersonero() {
      const dni = (this.modal.dni || '').replace(/\D/g, '');
      if (dni.length !== 8) return;
      this.reniecLoading = true;
      try {
        const res = await fetch('ajax/reniec-lookup.php?dni=' + dni);
        const data = await res.json();
        if (data.ok) {
          this.modal.nombres   = data.data.nombres;
          this.modal.apellidos = data.data.apellido_paterno + ' ' + data.data.apellido_materno;
        }
      } catch(_) {}
      this.reniecLoading = false;
    },

    previewFoto(e) {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = ev => { this.modal.foto_preview = ev.target.result; };
      reader.readAsDataURL(file);
    },

    async guardar() {
      this.modal.saving = true;
      this.modal.error  = '';
      const form = document.getElementById('form-personero');
      const fd   = new FormData(form);

      // ── 1. Guardar registro ───────────────────────────────────
      let data;
      try {
        const res = await fetch('personeros.php', { method: 'POST', body: fd });
        data = await res.json();
      } catch(e) {
        this.modal.error = 'Error de red al guardar. Verifica tu conexión.';
        this.modal.saving = false;
        return;
      }
      if (!data.ok) { this.modal.error = data.msg; this.modal.saving = false; return; }

      // ── 2. Refrescar lista (si falla, recarga la página) ──────
      try {
        const r2 = await fetch('personeros.php?json=1&_=' + Date.now());
        if (r2.ok) {
          const d2 = await r2.json();
          if (d2.personeros) this.personeros = d2.personeros;
        } else {
          location.reload(); return;
        }
      } catch(_) {
        location.reload(); return;
      }

      this.closeModal();
    },

    confirmarEliminar(p) {
      this.confirmDel = { open: true, id: p.id, nombre: p.apellidos + ', ' + p.nombres };
    },

    async eliminar() {
      const fd = new FormData();
      fd.append('action', 'delete_personero');
      fd.append('_csrf', CSRF_TOKEN);
      fd.append('id', this.confirmDel.id);
      const res = await fetch('personeros.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        this.personeros = this.personeros.filter(p => p.id !== this.confirmDel.id);
        this.confirmDel.open = false;
      }
    },

    async toggleEstado(p) {
      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('_csrf', CSRF_TOKEN);
      fd.append('id', p.id);
      const res  = await fetch('personeros.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        const idx = this.personeros.findIndex(x => x.id === p.id);
        if (idx !== -1) this.personeros[idx].estado = data.estado;
      }
    },

    openPdf(cargo = '') {
      // Construir URL fresca con timestamp para evitar caché
      const ts  = Date.now();
      const qs  = cargo ? `?cargo=${cargo}&embed=1&_=${ts}` : `?embed=1&_=${ts}`;
      const dqs = cargo ? `?cargo=${cargo}&download=1`      : `?download=1`;
      const oqs = cargo ? `?cargo=${cargo}`                 : '';
      this.pdfEmbedUrl    = 'exportar-personeros-pdf.php' + qs;
      this.pdfDownloadUrl = 'exportar-personeros-pdf.php' + dqs;
      this.pdfOpenUrl     = 'exportar-personeros-pdf.php' + oqs;
      // Resetear el select de filtro cargo
      this.$nextTick(() => {
        const sel = document.getElementById('pdf-cargo-filter');
        if (sel) sel.value = cargo;
        if (this.$refs.pdfFrame) this.$refs.pdfFrame.src = this.pdfEmbedUrl;
      });
      this.pdfModal = true;
    },
    closePdf() {
      this.pdfModal = false;
      // Detener carga del iframe al cerrar
      this.$nextTick(() => {
        if (this.$refs.pdfFrame) this.$refs.pdfFrame.src = '';
      });
    },
    printPdf() {
      const frame = this.$refs.pdfFrame;
      if (frame && frame.contentWindow) frame.contentWindow.print();
    },

    // ── Crear Mensaje (correo masivo) ─────────────────────────
    openMensaje() {
      this.selected = [];
      this.formError = '';
      this.recipientSearch = '';
      this.attachmentName = '';
      this.mensaje = { alcance: 'grupo' };
      this.mensajeModal = true;
    },
    filteredRecipients() {
      const q = this.recipientSearch.trim().toLowerCase();
      if (!q) return this.recipients;
      return this.recipients.filter((p) => {
        return [p.nombre, p.dni, p.correo, p.cargo]
          .join(' ')
          .toLowerCase()
          .includes(q);
      });
    },
    progressPercent() {
      if (!this.progress.total) return 0;
      return Math.round(((this.progress.sent + this.progress.failed) / this.progress.total) * 100);
    },
    closeProgress() {
      if (!this.progress.finished) return;
      this.progressModal = false;
    },
    async sendCampaign(event) {
      if (this.sending || this.selected.length === 0) return;
      this.formError = '';
      this.sending = true;

      const form = event.target;
      const data = new FormData(form);
      data.set('action', 'prepare_email');
      data.set('selected_ids', this.selected.join(','));

      try {
        const prepared = await this.postForm(data);
        if (!prepared.ok) throw new Error(prepared.error || 'No se pudo preparar el envio.');

        this.mensajeModal = false;
        this.progress = {
          total: prepared.total || 0,
          sent: 0,
          failed: 0,
          finished: false,
          items: (prepared.recipients || []).map((r) => ({
            id: String(r.id),
            nombre: r.nombre,
            correo: r.correo,
            estado: 'pendiente',
            error: ''
          }))
        };
        this.progressModal = true;

        for (const item of this.progress.items) {
          const sendData = new FormData();
          sendData.append('_csrf', this.csrf);
          sendData.append('action', 'send_email_recipient');
          sendData.append('mensaje_id', prepared.mensaje_id);
          sendData.append('personero_id', item.id);

          try {
            const result = await this.postForm(sendData);
            item.estado = result.estado || (result.sent ? 'enviado' : 'fallido');
            item.error = result.error || '';
          } catch (error) {
            item.estado = 'fallido';
            item.error = error.message || 'Error inesperado durante el envio.';
          }

          if (item.estado === 'enviado') {
            this.progress.sent++;
          } else {
            this.progress.failed++;
          }
        }

        this.progress.finished = true;
      } catch (error) {
        this.formError = error.message || 'No se pudo enviar el correo.';
      } finally {
        this.sending = false;
      }
    },

    // ── Crear SMS (plantillas y envio por WhatsApp) ───────────
    openWaPlantillas() {
      this.waResetForm();
      this.waError = '';
      this.waPlantillaModal = true;
    },
    waResetForm() {
      this.waForm = { id: null, nombre: '', contenido: '' };
    },
    waEditPlantilla(p) {
      this.waForm = { id: p.id, nombre: p.nombre, contenido: p.contenido };
    },
    async waSavePlantilla() {
      if (this.waSaving) return;
      this.waError = '';
      this.waSaving = true;
      const data = new FormData();
      data.append('_csrf', this.csrf);
      data.append('action', 'save_wa_plantilla');
      data.append('pid', this.waForm.id || 0);
      data.append('nombre', this.waForm.nombre.trim());
      data.append('contenido', this.waForm.contenido.trim());
      try {
        const res = await this.postForm(data);
        if (!res.ok) throw new Error(res.error || 'Error al guardar.');
        const item = { id: res.id, nombre: res.nombre, contenido: res.contenido };
        if (this.waForm.id) {
          const idx = this.waPlantillas.findIndex(p => p.id === res.id);
          if (idx !== -1) this.waPlantillas[idx] = item;
          else this.waPlantillas.unshift(item);
        } else {
          this.waPlantillas.unshift(item);
        }
        this.waResetForm();
      } catch (e) {
        this.waError = e.message || 'No se pudo guardar.';
      } finally {
        this.waSaving = false;
      }
    },
    async waDeletePlantilla(pid) {
      if (!confirm('¿Eliminar esta plantilla? No se puede deshacer.')) return;
      const data = new FormData();
      data.append('_csrf', this.csrf);
      data.append('action', 'delete_wa_plantilla');
      data.append('pid', pid);
      try {
        const res = await this.postForm(data);
        if (!res.ok) throw new Error(res.error || 'Error al eliminar.');
        this.waPlantillas = this.waPlantillas.filter(p => p.id !== pid);
        if (this.waForm.id === pid) this.waResetForm();
      } catch (e) {
        this.waError = e.message || 'No se pudo eliminar.';
      }
    },
    openWaEnviar(p) {
      this.waCurrent = {
        id: p.id,
        nombre: (p.apellidos + ' ' + p.nombres).trim(),
        whatsapp: p.whatsapp || p.celular || '',
      };
      this.waSelectedId = null;
      this.waEnviarModal = true;
    },
    waPreviewText() {
      const p = this.waPlantillas.find(p => p.id === this.waSelectedId);
      if (!p) return '';
      return (window.BRAND_NAME || 'El equipo') + ' te está enviando el siguiente mensaje:\n\n' + p.contenido;
    },
    waFormatPhone(raw) {
      const digits = raw.replace(/\D/g, '');
      if (digits.startsWith('51') && digits.length >= 11) return digits;
      if (digits.startsWith('9') && digits.length === 9) return '51' + digits;
      return '51' + digits;
    },
    waEnviar() {
      if (this.waSelectedId === null) return;
      const text = this.waPreviewText();
      const phone = this.waFormatPhone(this.waCurrent.whatsapp || '');
      const url = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);
      window.open(url, '_blank', 'noopener,noreferrer');
      this.waEnviarModal = false;
    },
    async postForm(data) {
      const response = await fetch('personeros.php', {
        method: 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload) {
        throw new Error(payload?.error || 'Respuesta invalida del servidor.');
      }
      return payload;
    },
  };
}
</script>
