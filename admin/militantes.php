<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/../includes/config/mail.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_modulo($pdo, 'militantes');

$page_title = 'Militantes';

function redirect_militantes(string $msg): void {
    header('Location: militantes.php?msg=' . urlencode($msg));
    exit;
}

function cargo_id_from_post(PDO $pdo): ?int {
    $cargo_id = (int)($_POST['cargo_id'] ?? 0);
    $cargo_nuevo = trim($_POST['cargo_nuevo'] ?? '');

    if ($cargo_nuevo !== '') {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO militante_cargos (nombre, orden, activo)
             VALUES (?, 100, 1)"
        );
        $stmt->execute([$cargo_nuevo]);

        $find = $pdo->prepare("SELECT id FROM militante_cargos WHERE nombre = ? LIMIT 1");
        $find->execute([$cargo_nuevo]);
        $cargo_id = (int)$find->fetchColumn();
    }

    return $cargo_id > 0 ? $cargo_id : null;
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_militante_email_columns(PDO $pdo): void {
    $needed = [
        'adjunto_nombre' => "ALTER TABLE militante_mensajes ADD adjunto_nombre VARCHAR(180) NULL",
        'adjunto_ruta' => "ALTER TABLE militante_mensajes ADD adjunto_ruta VARCHAR(255) NULL",
        'adjunto_tipo' => "ALTER TABLE militante_mensajes ADD adjunto_tipo VARCHAR(120) NULL",
        'adjunto_tamanio' => "ALTER TABLE militante_mensajes ADD adjunto_tamanio INT NULL",
    ];

    $cols = $pdo->query("SHOW COLUMNS FROM militante_mensajes")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($needed as $column => $sql) {
        if (!in_array($column, $cols, true)) {
            $pdo->exec($sql);
        }
    }
}

function ensure_wa_plantillas_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS militante_wa_plantillas (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            nombre     VARCHAR(200) NOT NULL,
            contenido  TEXT NOT NULL,
            creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_militante_estado_inactivo(PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM militantes LIKE 'estado'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = $column['Type'] ?? '';

    if (str_contains($type, 'bloqueado')) {
        $pdo->exec("ALTER TABLE militantes MODIFY estado ENUM('activo','bloqueado','inactivo') NOT NULL DEFAULT 'activo'");
        $pdo->exec("UPDATE militantes SET estado='inactivo' WHERE estado='bloqueado'");
        $pdo->exec("ALTER TABLE militantes MODIFY estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo'");
    }
}

function upload_email_attachment(): array {
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

    $dir = __DIR__ . '/../uploads/militantes-correo';
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
        'path' => 'uploads/militantes-correo/' . $filename,
        'mime' => $mime,
        'size' => $size,
    ];
}

function militantes_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    unset($params['msg']);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'militantes.php' . ($params ? '?' . http_build_query($params) : '');
}

$db_ready = true;
$error_msg = '';

try {
    $pdo->query("SELECT 1 FROM militantes LIMIT 1");
    $pdo->query("SELECT 1 FROM militante_cargos LIMIT 1");
    ensure_militante_email_columns($pdo);
    ensure_militante_estado_inactivo($pdo);
    ensure_wa_plantillas_table($pdo);
} catch (Exception $e) {
    $db_ready = false;
    $error_msg = $e->getMessage();
}

if ($db_ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $json_actions = ['prepare_email', 'send_email_recipient', 'save_wa_plantilla', 'delete_wa_plantilla'];
    csrf_verify(in_array($action, $json_actions, true));

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $dni = preg_replace('/\D+/', '', $_POST['dni'] ?? '');
            $celular = trim($_POST['celular'] ?? '');
            $whatsapp = trim($_POST['whatsapp'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $fecha_ingreso = trim($_POST['fecha_ingreso'] ?? date('Y-m-d'));
            $cargo_id = cargo_id_from_post($pdo);

            if ($nombre === '' || strlen($dni) !== 8 || $fecha_ingreso === '') {
                redirect_militantes('datos_invalidos');
            }

            $dup_sql = $id > 0
                ? "SELECT id FROM militantes WHERE dni = ? AND id != ? LIMIT 1"
                : "SELECT id FROM militantes WHERE dni = ? LIMIT 1";
            $dup = $pdo->prepare($dup_sql);
            $dup->execute($id > 0 ? [$dni, $id] : [$dni]);
            if ($dup->fetchColumn()) {
                redirect_militantes('dni_duplicado');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE militantes
                     SET nombre=?, dni=?, celular=?, whatsapp=?, correo=?, cargo_id=?, fecha_ingreso=?
                     WHERE id=?"
                );
                $stmt->execute([$nombre, $dni, $celular, $whatsapp, $correo, $cargo_id, $fecha_ingreso, $id]);
                log_activity($pdo, 'Actualizo militante: ' . $nombre, 'militantes');
                redirect_militantes('actualizado');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO militantes
                 (nombre, dni, celular, whatsapp, correo, cargo_id, fecha_ingreso, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'activo')"
            );
            $stmt->execute([$nombre, $dni, $celular, $whatsapp, $correo, $cargo_id, $fecha_ingreso]);
            log_activity($pdo, 'Creo militante directo: ' . $nombre, 'militantes');
            redirect_militantes('creado');
        }

        if ($action === 'prepare_email') {
            $asunto = trim($_POST['asunto'] ?? '');
            $mensaje = trim($_POST['mensaje'] ?? '');
            $alcance = $_POST['alcance'] ?? 'grupo';
            $ids_raw = trim($_POST['selected_ids'] ?? '');

            if ($asunto === '' || $mensaje === '') {
                json_response(['ok' => false, 'error' => 'Completa asunto y cuerpo del correo.'], 422);
            }

            if ($alcance === 'masivo') {
                $ids = $pdo->query("SELECT id FROM militantes WHERE estado='activo'")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_raw)))));
                $alcance = count($ids) === 1 ? 'individual' : 'grupo';
            }

            if (empty($ids)) {
                json_response(['ok' => false, 'error' => 'Selecciona al menos un militante activo.'], 422);
            }

            $attachment = upload_email_attachment();

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO militante_mensajes
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
                "INSERT IGNORE INTO militante_mensaje_canales (mensaje_id, canal)
                 VALUES (?, ?)"
            );
            $canal_stmt->execute([$mensaje_id, 'correo']);

            $dest = $pdo->prepare(
                "INSERT IGNORE INTO militante_mensaje_destinatarios (mensaje_id, militante_id)
                 VALUES (?, ?)"
            );
            foreach ($ids as $mid) {
                $dest->execute([$mensaje_id, $mid]);
            }
            $pdo->commit();

            $recipients_stmt = $pdo->prepare(
                "SELECT id, nombre, correo
                 FROM militantes
                 WHERE estado='activo' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
            );
            $recipients_stmt->execute($ids);
            $recipients_to_send = $recipients_stmt->fetchAll(PDO::FETCH_ASSOC);

            log_activity($pdo, 'Preparo correo masivo a militantes: mensaje_id=' . $mensaje_id, 'militantes');
            json_response([
                'ok' => true,
                'mensaje_id' => $mensaje_id,
                'total' => count($recipients_to_send),
                'recipients' => array_map(fn($r) => [
                    'id' => (int)$r['id'],
                    'nombre' => $r['nombre'],
                    'correo' => $r['correo'],
                ], $recipients_to_send),
            ]);
        }

        if ($action === 'send_email_recipient') {
            $mensaje_id = (int)($_POST['mensaje_id'] ?? 0);
            $militante_id = (int)($_POST['militante_id'] ?? 0);

            if ($mensaje_id <= 0 || $militante_id <= 0) {
                json_response(['ok' => false, 'error' => 'Datos de envio incompletos.'], 422);
            }

            $row_stmt = $pdo->prepare(
                "SELECT mm.asunto, mm.mensaje, mm.adjunto_nombre, mm.adjunto_ruta, mm.adjunto_tipo,
                        m.id AS militante_id, m.nombre, m.correo, d.estado
                 FROM militante_mensaje_destinatarios d
                 INNER JOIN militante_mensajes mm ON mm.id = d.mensaje_id
                 INNER JOIN militantes m ON m.id = d.militante_id
                 WHERE d.mensaje_id = ? AND d.militante_id = ?
                 LIMIT 1"
            );
            $row_stmt->execute([$mensaje_id, $militante_id]);
            $row = $row_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                json_response(['ok' => false, 'error' => 'Destinatario no encontrado.'], 404);
            }

            $status_stmt = $pdo->prepare(
                "UPDATE militante_mensaje_destinatarios
                 SET estado=?, enviado_en=?, error=?
                 WHERE mensaje_id=? AND militante_id=?"
            );

            $attachments = [];
            if (!empty($row['adjunto_ruta'])) {
                $path = realpath(__DIR__ . '/../' . $row['adjunto_ruta']);
                $uploads_root = realpath(__DIR__ . '/../uploads/militantes-correo');
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
                (string)$row['nombre'],
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
                $militante_id,
            ]);

            json_response([
                'ok' => true,
                'sent' => $sent,
                'estado' => $sent ? 'enviado' : 'fallido',
                'error' => $sent ? null : $send_error,
                'recipient' => [
                    'id' => $militante_id,
                    'nombre' => $row['nombre'],
                    'correo' => $row['correo'],
                ],
            ]);
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT nombre, estado FROM militantes WHERE id=? LIMIT 1");
            $row->execute([$id]);
            $militante = $row->fetch(PDO::FETCH_ASSOC);
            if (!$militante) redirect_militantes('no_encontrado');

            $nuevo_estado = $militante['estado'] === 'inactivo' ? 'activo' : 'inactivo';
            $stmt = $pdo->prepare("UPDATE militantes SET estado=? WHERE id=?");
            $stmt->execute([$nuevo_estado, $id]);
            log_activity($pdo, 'Cambio estado de militante: ' . $militante['nombre'] . ' a ' . $nuevo_estado, 'militantes');
            redirect_militantes('estado');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT nombre FROM militantes WHERE id=? LIMIT 1");
            $row->execute([$id]);
            $nombre = $row->fetchColumn();
            if (!$nombre) redirect_militantes('no_encontrado');

            $stmt = $pdo->prepare("DELETE FROM militantes WHERE id=?");
            $stmt->execute([$id]);
            log_activity($pdo, 'Elimino militante: ' . $nombre, 'militantes');
            redirect_militantes('eliminado');
        }

        if ($action === 'save_wa_plantilla') {
            $pid      = (int)($_POST['pid'] ?? 0);
            $nombre   = trim($_POST['nombre']   ?? '');
            $contenido = trim($_POST['contenido'] ?? '');
            if ($nombre === '' || $contenido === '') {
                json_response(['ok' => false, 'error' => 'Nombre y contenido son obligatorios.'], 422);
            }
            if ($pid > 0) {
                $pdo->prepare("UPDATE militante_wa_plantillas SET nombre=?, contenido=? WHERE id=?")
                    ->execute([$nombre, $contenido, $pid]);
                json_response(['ok' => true, 'id' => $pid, 'nombre' => $nombre, 'contenido' => $contenido]);
            } else {
                $pdo->prepare("INSERT INTO militante_wa_plantillas (nombre, contenido) VALUES (?, ?)")
                    ->execute([$nombre, $contenido]);
                $new_id = (int)$pdo->lastInsertId();
                json_response(['ok' => true, 'id' => $new_id, 'nombre' => $nombre, 'contenido' => $contenido]);
            }
        }

        if ($action === 'delete_wa_plantilla') {
            $pid = (int)($_POST['pid'] ?? 0);
            if ($pid <= 0) json_response(['ok' => false, 'error' => 'ID invalido.'], 422);
            $pdo->prepare("DELETE FROM militante_wa_plantillas WHERE id=?")->execute([$pid]);
            json_response(['ok' => true]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (in_array($action, ['prepare_email', 'send_email_recipient'], true)) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        redirect_militantes('error');
    }
}

$messages = [
    'creado' => ['success', 'Militante creado correctamente.'],
    'actualizado' => ['success', 'Militante actualizado correctamente.'],
    'estado' => ['success', 'Estado del militante actualizado.'],
    'eliminado' => ['success', 'Militante eliminado correctamente.'],
    'dni_duplicado' => ['error', 'Ya existe un militante con ese DNI.'],
    'datos_invalidos' => ['error', 'Completa nombres, DNI valido de 8 digitos y fecha de ingreso.'],
    'no_encontrado' => ['error', 'No se encontro el militante solicitado.'],
    'correo_enviado' => ['success', 'Proceso de envio finalizado. Los resultados quedaron registrados en la campana.'],
    'mensaje_invalido' => ['error', 'Completa asunto, mensaje y destinatarios.'],
    'sin_destinatarios' => ['error', 'Selecciona al menos un militante activo.'],
    'error' => ['error', 'No se pudo completar la operacion.'],
];
$flash = null;
$flash_type = 'success';
if (isset($_GET['msg'], $messages[$_GET['msg']])) {
    [$flash_type, $flash] = $messages[$_GET['msg']];
}

$militantes = [];
$militantes_activos_email = [];
$wa_plantillas = [];
$cargos = [];
$total = 0;
$activos = 0;
$inactivos = 0;
$cargos_total = 0;
$buscar = trim($_GET['q'] ?? '');
$estado_filter = $_GET['estado'] ?? '';
$cargo_filter = (int)($_GET['cargo'] ?? 0);
$orden = $_GET['orden'] ?? 'fecha';
$dir = strtolower($_GET['dir'] ?? 'desc');
$por_pag = 10;
$pag = max(1, (int)($_GET['pag'] ?? 1));
$offset = ($pag - 1) * $por_pag;
$total_registros = 0;
$pages = 1;
$pdf_url = 'exportar-militantes-pdf.php';
$pdf_url_embed = 'exportar-militantes-pdf.php?embed=1';
$pdf_download_url = 'exportar-militantes-pdf.php?download=1';

$estado_options = ['', 'activo', 'inactivo'];
if (!in_array($estado_filter, $estado_options, true)) {
    $estado_filter = '';
}

$order_map = [
    'nombre' => 'm.nombre',
    'dni' => 'm.dni',
    'cargo' => 'c.nombre',
    'fecha' => 'm.fecha_ingreso',
    'estado' => 'm.estado',
];
if (!isset($order_map[$orden])) {
    $orden = 'fecha';
}
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}

$where = [];
$params = [];

if ($buscar !== '') {
    $where[] = "(m.nombre LIKE ? OR m.dni LIKE ? OR c.nombre LIKE ?)";
    $like = '%' . $buscar . '%';
    array_push($params, $like, $like, $like);
}
if ($estado_filter !== '') {
    $where[] = "m.estado = ?";
    $params[] = $estado_filter;
}
if ($cargo_filter > 0) {
    $where[] = "m.cargo_id = ?";
    $params[] = $cargo_filter;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$order_sql = $order_map[$orden] . ' ' . strtoupper($dir) . ', m.id DESC';

if ($db_ready && isset($_GET['export']) && $_GET['export'] === 'excel') {
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.nombre, m.dni, m.celular, m.whatsapp, m.correo,
                    m.fecha_ingreso, m.estado, c.nombre AS cargo
             FROM militantes m
             LEFT JOIN militante_cargos c ON c.id = m.cargo_id
             $where_sql
             ORDER BY $order_sql"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $title = 'Relacion de Militantes';
        if ($estado_filter === 'activo') {
            $title = 'Relacion de Militantes Activos';
        } elseif ($estado_filter === 'inactivo') {
            $title = 'Relacion de Militantes Inactivos';
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="militantes_' . date('Ymd_His') . '.xls"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF";
        ?>
        <html>
        <head>
          <meta charset="UTF-8">
          <style>
            table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11px; }
            th, td { border: 1px solid #d9e2f3; padding: 7px 8px; vertical-align: top; }
            .title { background: #1E3A8A; color: #ffffff; font-size: 18px; font-weight: 800; text-align: center; }
            .meta { background: #eef4ff; color: #1E3A8A; font-weight: 700; }
            .head { background: #1E3A8A; color: #ffffff; font-weight: 800; text-align: center; }
            .text { mso-number-format: "\@"; }
            .state-active { color: #15803d; font-weight: 800; }
            .state-inactive { color: #b91c1c; font-weight: 800; }
          </style>
        </head>
        <body>
        <table>
          <tr><td colspan="9" class="title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr>
            <td colspan="3" class="meta">Generado</td>
            <td colspan="6"><?= date('d/m/Y H:i') ?></td>
          </tr>
          <tr>
            <td colspan="3" class="meta">Total registros</td>
            <td colspan="6"><?= count($rows) ?></td>
          </tr>
          <?php if ($buscar !== ''): ?>
          <tr>
            <td colspan="3" class="meta">Busqueda</td>
            <td colspan="6"><?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endif; ?>
          <tr class="head">
            <th>Nro.</th>
            <th>Apellidos y nombres</th>
            <th>Fecha ingreso</th>
            <th>DNI</th>
            <th>Celular</th>
            <th>WhatsApp</th>
            <th>Correo</th>
            <th>Cargo</th>
            <th>Estado</th>
          </tr>
          <?php foreach ($rows as $i => $row): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($row['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= !empty($row['fecha_ingreso']) ? date('d/m/Y', strtotime($row['fecha_ingreso'])) : '' ?></td>
            <td class="text"><?= htmlspecialchars($row['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text"><?= htmlspecialchars($row['celular'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text"><?= htmlspecialchars($row['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['cargo'] ?: 'Sin cargo', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="<?= ($row['estado'] ?? '') === 'inactivo' ? 'state-inactive' : 'state-active' ?>">
              <?= ($row['estado'] ?? '') === 'inactivo' ? 'Inactivo' : 'Activo' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        </body>
        </html>
        <?php
    } catch (Exception $e) {}
    exit;
}

if ($db_ready) {
    try {
        $cargos = $pdo->query(
            "SELECT id, nombre FROM militante_cargos WHERE activo=1 ORDER BY orden ASC, nombre ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM militantes")->fetchColumn();
        $activos = (int)$pdo->query("SELECT COUNT(*) FROM militantes WHERE estado='activo'")->fetchColumn();
        $inactivos = (int)$pdo->query("SELECT COUNT(*) FROM militantes WHERE estado='inactivo'")->fetchColumn();
        $cargos_total = (int)$pdo->query("SELECT COUNT(*) FROM militante_cargos WHERE activo=1")->fetchColumn();

        $count_stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM militantes m
             LEFT JOIN militante_cargos c ON c.id = m.cargo_id
             $where_sql"
        );
        $count_stmt->execute($params);
        $total_registros = (int)$count_stmt->fetchColumn();
        $pages = max(1, (int)ceil($total_registros / $por_pag));
        if ($pag > $pages) {
            $pag = $pages;
            $offset = ($pag - 1) * $por_pag;
        }

        $stmt = $pdo->prepare(
            "SELECT m.id, m.nombre, m.dni, m.celular, m.whatsapp, m.correo,
                    m.fecha_ingreso, m.estado, m.cargo_id, c.nombre AS cargo
             FROM militantes m
             LEFT JOIN militante_cargos c ON c.id = m.cargo_id
             $where_sql
             ORDER BY $order_sql
             LIMIT ? OFFSET ?"
        );
        $bind_index = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bind_index++, $param);
        }
        $stmt->bindValue($bind_index++, $por_pag, PDO::PARAM_INT);
        $stmt->bindValue($bind_index, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $militantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $militantes_activos_email = $pdo->query(
            "SELECT m.id, m.nombre, m.dni, m.correo, c.nombre AS cargo
             FROM militantes m
             LEFT JOIN militante_cargos c ON c.id = m.cargo_id
             WHERE m.estado='activo'
             ORDER BY m.nombre ASC, m.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $wa_plantillas = $pdo->query(
            "SELECT id, nombre, contenido FROM militante_wa_plantillas ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $db_ready = false;
        $error_msg = $e->getMessage();
    }
}

$pdf_params = $_GET;
unset($pdf_params['msg'], $pdf_params['pag'], $pdf_params['embed']);
$pdf_query = http_build_query($pdf_params);
$pdf_url = 'exportar-militantes-pdf.php' . ($pdf_query !== '' ? '?' . $pdf_query : '');
$pdf_url_embed = $pdf_url . ($pdf_query !== '' ? '&' : '?') . 'embed=1';
$pdf_download_url = $pdf_url . ($pdf_query !== '' ? '&' : '?') . 'download=1';

$excel_params = $_GET;
unset($excel_params['msg'], $excel_params['pag'], $excel_params['export']);
$excel_params['export'] = 'excel';
$excel_url = 'militantes.php?' . http_build_query($excel_params);

include __DIR__ . '/layout.php';
?>

<style>
  .btn-pro {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    border-radius: .95rem;
    font-weight: 900;
    letter-spacing: .01em;
    border: 1px solid transparent;
    box-shadow: 0 10px 22px rgba(15, 32, 87, .08);
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease, color .18s ease;
    overflow: hidden;
    white-space: nowrap;
  }

  .btn-pro::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,.26) 45%, transparent 70%);
    transform: translateX(-120%);
    transition: transform .42s ease;
    pointer-events: none;
  }

  .btn-pro:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px rgba(15, 32, 87, .16);
  }

  .btn-pro:hover::after {
    transform: translateX(120%);
  }

  .btn-pro:active {
    transform: translateY(0);
    box-shadow: 0 8px 18px rgba(15, 32, 87, .12);
  }

  .btn-primary-pro {
    color: #fff;
    background: linear-gradient(135deg, #0F2057 0%, #1D4ED8 100%);
    border-color: rgba(37, 99, 235, .25);
  }

  .btn-primary-pro:hover {
    background: linear-gradient(135deg, #0B1745 0%, #2563EB 100%);
  }

  .btn-green-pro {
    color: #047857;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border-color: #A7F3D0;
  }

  .btn-red-pro {
    color: #DC2626;
    background: linear-gradient(135deg, #FFF1F2 0%, #FFE4E6 100%);
    border-color: #FECDD3;
  }

  .btn-amber-pro {
    color: #B45309;
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border-color: #FDE68A;
  }

  .btn-blue-soft-pro {
    color: #1D4ED8;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-color: #BFDBFE;
  }

  .btn-row-pro {
    min-height: 2.25rem;
    padding: .5rem .85rem;
    border-radius: .8rem;
    font-size: .74rem;
  }
</style>

<div x-data="militantesManager()" class="space-y-6">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
      <h1 class="text-2xl font-black text-gray-800">Militantes</h1>
      <p class="text-sm text-gray-400 mt-1">Lista oficial de militantes y estructura dirigencial del partido.</p>
    </div>
    <?php if ($db_ready): ?>
    <div class="flex flex-wrap items-center gap-2">
      <a href="<?= htmlspecialchars($excel_url) ?>"
         class="btn-pro btn-green-pro px-5 py-3 text-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16M8 7v10m8-10v10"/>
        </svg>
        Excel
      </a>
      <button type="button"
              @click="openPdf()"
              class="btn-pro btn-red-pro px-5 py-3 text-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9l-6-6H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v6h6"/>
        </svg>
        PDF
      </button>
      <button type="button"
              @click="openMensaje()"
              class="btn-pro btn-primary-pro px-5 py-3 text-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 7l8 6 8-6"/>
        </svg>
        Crear Mensaje
      </button>
      <button type="button"
              @click="openWaPlantillas()"
              class="btn-pro px-5 py-3 text-sm font-black"
              style="background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;border-color:#075E54">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
        Crear SMS
      </button>
      <button type="button"
              @click="openNew()"
              class="btn-pro btn-primary-pro px-5 py-3 text-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Nuevo Militante
      </button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($flash): ?>
  <div class="rounded-2xl px-5 py-4 text-sm font-bold border <?= $flash_type === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <?php if ($db_ready && MAIL_PASS === ''): ?>
  <div class="rounded-2xl px-5 py-4 text-sm border bg-amber-50 text-amber-800 border-amber-200">
    <strong>SMTP pendiente:</strong> configura la variable de entorno <code class="bg-white/70 px-1.5 py-0.5 rounded">JOYER_MAIL_PASS</code>
    para habilitar el envio real de correos desde <?= htmlspecialchars(MAIL_USER) ?>.
  </div>
  <?php endif; ?>

  <?php if (!$db_ready): ?>
  <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-5">
    <p class="font-bold text-sm mb-1">Falta preparar la base de datos de militantes.</p>
    <p class="text-sm">Ejecuta <code class="bg-white/70 px-1.5 py-0.5 rounded">admin/migration_militantes.sql</code> y vuelve a cargar esta pagina.</p>
    <p class="text-xs text-amber-700 mt-3">Detalle tecnico: <?= htmlspecialchars($error_msg) ?></p>
  </div>
  <?php else: ?>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
    <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm">
      <p class="text-xs font-bold uppercase text-gray-400">Total militantes</p>
      <p class="text-3xl font-black text-[#1E3A8A] mt-1"><?= $total ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm">
      <p class="text-xs font-bold uppercase text-gray-400">Activos</p>
      <p class="text-3xl font-black text-green-600 mt-1"><?= $activos ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm">
      <p class="text-xs font-bold uppercase text-gray-400">Inactivos</p>
      <p class="text-3xl font-black text-red-500 mt-1"><?= $inactivos ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm">
      <p class="text-xs font-bold uppercase text-gray-400">Cargos activos</p>
      <p class="text-3xl font-black text-[#FACC15] mt-1"><?= $cargos_total ?></p>
    </div>
  </div>

  <form method="GET" x-ref="filtersForm" @submit.prevent="submitFiltersNow()" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3 items-end">
      <div class="xl:col-span-2">
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Buscar</label>
        <input type="search" name="q" value="<?= htmlspecialchars($buscar) ?>"
               @input="submitFiltersDebounced()"
               @search="submitFiltersNow()"
               placeholder="Apellidos, nombres, DNI o cargo..."
               class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
      </div>
      <div>
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cargo</label>
        <select name="cargo"
                @change="submitFiltersNow()"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          <option value="0">Todos</option>
          <?php foreach ($cargos as $cargo): ?>
          <option value="<?= (int)$cargo['id'] ?>" <?= $cargo_filter === (int)$cargo['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cargo['nombre']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Estado</label>
        <select name="estado"
                @change="submitFiltersNow()"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          <option value="">Todos</option>
          <option value="activo" <?= $estado_filter === 'activo' ? 'selected' : '' ?>>Activos</option>
          <option value="inactivo" <?= $estado_filter === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Ordenar por</label>
        <select name="orden"
                @change="submitFiltersNow()"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          <option value="fecha" <?= $orden === 'fecha' ? 'selected' : '' ?>>Fecha ingreso</option>
          <option value="nombre" <?= $orden === 'nombre' ? 'selected' : '' ?>>Apellidos y nombres</option>
          <option value="dni" <?= $orden === 'dni' ? 'selected' : '' ?>>DNI</option>
          <option value="cargo" <?= $orden === 'cargo' ? 'selected' : '' ?>>Cargo</option>
          <option value="estado" <?= $orden === 'estado' ? 'selected' : '' ?>>Estado</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Direccion</label>
        <select name="dir"
                @change="submitFiltersNow()"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descendente</option>
          <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascendente</option>
        </select>
      </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
      <p class="text-sm text-gray-400">
        <?= $total_registros ?> militante(s) <?= $buscar !== '' ? 'encontrado(s)' : 'registrado(s)' ?>
      </p>
      <div class="flex items-center gap-2">
        <?php if ($buscar !== '' || $estado_filter !== '' || $cargo_filter > 0 || $orden !== 'fecha' || $dir !== 'desc'): ?>
        <a href="militantes.php" class="btn-pro px-4 py-2.5 text-sm bg-white text-gray-500 border-gray-200 hover:text-[#1E3A8A]">
          Limpiar
        </a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <?php if (empty($militantes)): ?>
    <div class="text-center py-16 px-6">
      <p class="font-bold text-gray-700"><?= $total > 0 ? 'No hay resultados con los filtros actuales.' : 'Aun no hay militantes registrados.' ?></p>
      <p class="text-sm text-gray-400 mt-1"><?= $total > 0 ? 'Prueba limpiando la busqueda o cambiando el orden.' : 'Puedes crear uno directamente o convertirlo desde Simpatizantes.' ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
          <tr>
            <th class="px-4 py-3 text-left">#</th>
            <th class="px-4 py-3 text-left">Nombres y apellidos</th>
            <th class="px-4 py-3 text-left">Fecha ingreso</th>
            <th class="px-4 py-3 text-left">DNI</th>
            <th class="px-4 py-3 text-left">Celular</th>
            <th class="px-4 py-3 text-left">WhatsApp</th>
            <th class="px-4 py-3 text-left">Correo</th>
            <th class="px-4 py-3 text-left">Cargo</th>
            <th class="px-4 py-3 text-left">Estado</th>
            <th class="px-4 py-3 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($militantes as $i => $m): ?>
          <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-4 py-3 text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
            <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($m['nombre']) ?></td>
            <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= date('d/m/Y', strtotime($m['fecha_ingreso'])) ?></td>
            <td class="px-4 py-3 font-mono text-gray-600"><?= htmlspecialchars($m['dni']) ?></td>
            <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars($m['celular'] ?: '-') ?></td>
            <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars($m['whatsapp'] ?: '-') ?></td>
            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($m['correo'] ?: '-') ?></td>
            <td class="px-4 py-3">
              <span class="bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded-full font-medium">
                <?= htmlspecialchars($m['cargo'] ?? 'Sin cargo') ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <?php if ($m['estado'] === 'inactivo'): ?>
              <span class="bg-red-50 text-red-600 text-xs px-2 py-0.5 rounded-full font-bold">Inactivo</span>
              <?php else: ?>
              <span class="bg-green-50 text-green-700 text-xs px-2 py-0.5 rounded-full font-bold">Activo</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-2">
                <?php if (!empty($m['whatsapp'])): ?>
                <button type="button"
                        @click='openWaEnviar(<?= json_encode(['id'=>$m['id'],'nombre'=>$m['nombre'],'whatsapp'=>$m['whatsapp']], JSON_HEX_APOS|JSON_HEX_TAG|JSON_UNESCAPED_UNICODE) ?>)'
                        class="w-9 h-9 flex items-center justify-center rounded-xl text-white transition-all hover:scale-110 active:scale-95 shadow-sm"
                        style="background:linear-gradient(135deg,#128C7E,#25D366)"
                        title="Enviar WhatsApp">
                  <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                  </svg>
                </button>
                <?php endif; ?>
                <!-- Convertir a Personero -->
                <a href="personeros.php?from_militante=<?= $m['id'] ?>"
                   title="Convertir a Personero"
                   class="w-8 h-8 flex items-center justify-center rounded-xl bg-purple-50 text-purple-600 hover:bg-purple-100 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2"/>
                  </svg>
                </a>
                <!-- Editar -->
                <button type="button"
                        @click='openEdit(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>)'
                        title="Editar"
                        class="w-8 h-8 flex items-center justify-center rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </button>
                <!-- Activar / Inactivar -->
                <form method="POST" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <?php if ($m['estado'] === 'inactivo'): ?>
                  <button type="submit" title="Activar"
                          class="w-8 h-8 flex items-center justify-center rounded-xl bg-green-50 text-green-600 hover:bg-green-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                  </button>
                  <?php else: ?>
                  <button type="submit" title="Inactivar"
                          class="w-8 h-8 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                  </button>
                  <?php endif; ?>
                </form>
                <!-- Eliminar -->
                <form method="POST" class="inline" onsubmit="return confirm('Eliminar este militante? Esta accion no se puede deshacer.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button type="submit" title="Eliminar"
                          class="w-8 h-8 flex items-center justify-center rounded-xl bg-red-50 text-red-500 hover:bg-red-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($total_registros > 0): ?>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-4 border-t border-gray-50">
      <p class="text-xs text-gray-400">
        Mostrando <?= $offset + 1 ?>-<?= min($offset + $por_pag, $total_registros) ?> de <?= $total_registros ?>
      </p>
      <?php if ($pages > 1): ?>
      <div class="flex flex-wrap items-center gap-2">
        <a href="<?= htmlspecialchars(militantes_url(['pag' => max(1, $pag - 1)])) ?>"
           class="px-3 py-2 rounded-lg text-sm font-bold border <?= $pag <= 1 ? 'pointer-events-none opacity-40 text-gray-400 border-gray-100' : 'text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
          Anterior
        </a>
        <?php
          $start_page = max(1, $pag - 2);
          $end_page = min($pages, $pag + 2);
        ?>
        <?php if ($start_page > 1): ?>
        <a href="<?= htmlspecialchars(militantes_url(['pag' => 1])) ?>" class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-sm font-bold text-gray-500 hover:bg-gray-100">1</a>
        <?php if ($start_page > 2): ?><span class="text-gray-300">...</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
        <a href="<?= htmlspecialchars(militantes_url(['pag' => $p])) ?>"
           class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-sm font-bold <?= $p === $pag ? 'bg-[#1E3A8A] text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
          <?= $p ?>
        </a>
        <?php endfor; ?>
        <?php if ($end_page < $pages): ?>
        <?php if ($end_page < $pages - 1): ?><span class="text-gray-300">...</span><?php endif; ?>
        <a href="<?= htmlspecialchars(militantes_url(['pag' => $pages])) ?>" class="w-9 h-9 inline-flex items-center justify-center rounded-lg text-sm font-bold text-gray-500 hover:bg-gray-100"><?= $pages ?></a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(militantes_url(['pag' => min($pages, $pag + 1)])) ?>"
           class="px-3 py-2 rounded-lg text-sm font-bold border <?= $pag >= $pages ? 'pointer-events-none opacity-40 text-gray-400 border-gray-100' : 'text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
          Siguiente
        </a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div x-show="modal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="modal = false"></div>
    <form method="POST" class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-3xl overflow-hidden">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" :value="form.id">

      <div class="bg-[#1E3A8A] px-6 py-4">
        <h2 class="text-white font-black text-lg" x-text="form.id ? 'Editar Militante' : 'Nuevo Militante'"></h2>
        <p class="text-blue-100 text-sm mt-1">Registro oficial de estructura dirigencial.</p>
      </div>

      <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-5 max-h-[70vh] overflow-y-auto">
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">DNI</label>
          <div class="relative">
            <input name="dni" x-model="form.dni" maxlength="8" required
                   @input="if(form.dni.replace(/\D/g,'').length===8) buscarDniMilitante()"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-[#1E3A8A] outline-none pr-8">
            <span x-show="reniecLoading" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[#1E3A8A] text-xs animate-spin">⟳</span>
          </div>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Fecha de ingreso</label>
          <input type="date" name="fecha_ingreso" x-model="form.fecha_ingreso" required
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Nombres y apellidos</label>
          <input name="nombre" x-model="form.nombre" required
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Celular</label>
          <input name="celular" x-model="form.celular"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">WhatsApp</label>
          <input name="whatsapp" x-model="form.whatsapp"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Correo</label>
          <input type="email" name="correo" x-model="form.correo"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cargo</label>
          <select name="cargo_id" x-model="form.cargo_id"
                  class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
            <option value="">Sin cargo</option>
            <?php foreach ($cargos as $cargo): ?>
            <option value="<?= (int)$cargo['id'] ?>"><?= htmlspecialchars($cargo['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Anadir cargo</label>
          <input name="cargo_nuevo" placeholder="Ej. Coordinador zonal"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3">
        <button type="button" @click="modal = false"
                class="btn-pro px-5 py-2.5 text-sm bg-white text-gray-600 border-gray-200 hover:text-[#1E3A8A]">
          Cancelar
        </button>
        <button type="submit"
                class="btn-pro btn-primary-pro px-5 py-2.5 text-sm">
          Guardar
        </button>
      </div>
    </form>
  </div>

  <div x-show="mensajeModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="!sending && (mensajeModal = false)"></div>
    <form method="POST" enctype="multipart/form-data" @submit.prevent="sendCampaign($event)"
          class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-4xl max-h-[92vh] overflow-hidden flex flex-col">
      <?= csrf_field() ?>
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
          <input name="asunto" required placeholder="Ej. Reunion de coordinacion distrital"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none mb-3">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cuerpo del correo</label>
          <textarea name="mensaje" rows="5" required
                    placeholder="Escribe el contenido que recibiran los militantes..."
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
              Seleccionar militantes
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
            <template x-for="m in filteredRecipients()" :key="m.id">
              <label class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                <input type="checkbox" :value="String(m.id)" x-model="selected" class="rounded border-gray-300 text-[#1E3A8A]">
                <span class="min-w-0">
                  <span class="block text-sm font-bold text-gray-800 truncate" x-text="m.nombre"></span>
                  <span class="block text-xs text-gray-400 truncate" x-text="(m.correo || 'Sin correo') + ' - ' + (m.cargo || 'Sin cargo')"></span>
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

  <div x-show="pdfModal" x-cloak class="fixed inset-0 z-[95] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-950/60 backdrop-blur-sm" @click="pdfModal = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-6xl h-[90vh] overflow-hidden flex flex-col">
      <div class="bg-[#1E3A8A] px-5 py-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
          <h2 class="text-white font-black text-lg">Previsualizar PDF</h2>
          <p class="text-blue-100 text-sm mt-1">Relacion de militantes segun los filtros actuales.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" @click="printPdf()"
                  class="btn-pro px-4 py-2.5 text-sm bg-[#FACC15] text-[#1E3A8A] border-yellow-300">
            Imprimir
          </button>
          <button type="button" @click="downloadPdf()"
                  class="btn-pro px-4 py-2.5 text-sm bg-white text-[#1E3A8A] border-blue-100">
            Descargar PDF
          </button>
          <a :href="pdfUrl" target="_blank"
             class="btn-pro px-4 py-2.5 text-sm bg-white/10 text-white border-white/20">
            Abrir
          </a>
          <button type="button" @click="pdfModal = false"
                  class="btn-pro px-4 py-2.5 text-sm bg-white/10 text-white border-white/20">
            Cerrar
          </button>
        </div>
      </div>
      <div class="bg-gray-100 p-3 flex-1 min-h-0">
        <iframe x-ref="pdfFrame" :src="pdfEmbedUrl" class="w-full h-full bg-white rounded-xl border border-gray-200"></iframe>
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
        <!-- Error -->
        <template x-if="waError">
          <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm font-bold" x-text="waError"></div>
        </template>

        <!-- Formulario nueva / editar plantilla -->
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
          <!-- Preview -->
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

        <!-- Lista de plantillas guardadas -->
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

  <!-- ── Modal: Enviar WA a militante ──────────────────────── -->
  <div x-show="waEnviarModal" x-cloak class="fixed inset-0 z-[85] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="waEnviarModal = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col max-h-[92vh]">

      <div class="px-6 py-4" style="background:linear-gradient(135deg,#075E54,#128C7E)">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
            <span class="text-white font-black text-sm" x-text="waCurrent.nombre ? waCurrent.nombre.charAt(0).toUpperCase() : 'M'"></span>
          </div>
          <div>
            <h2 class="text-white font-black text-base" x-text="waCurrent.nombre"></h2>
            <p class="text-white/60 text-xs" x-text="'WhatsApp: ' + waCurrent.whatsapp"></p>
          </div>
        </div>
      </div>

      <div class="p-6 space-y-4 overflow-y-auto flex-1">
        <!-- Sin plantillas -->
        <div x-show="waPlantillas.length === 0" class="text-center py-8">
          <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
          </div>
          <p class="font-bold text-gray-500 text-sm">No hay plantillas guardadas.</p>
          <p class="text-xs text-gray-400 mt-1">Crea una usando el botón "Crear SMS".</p>
        </div>

        <!-- Selección de plantilla -->
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

        <!-- Preview mensaje final -->
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

  <?php endif; ?>
</div>

<script>
function militantesManager() {
  return {
    modal: false,
    reniecLoading: false,
    mensajeModal: false,
    progressModal: false,
    pdfModal: false,
    waPlantillaModal: false,
    waEnviarModal: false,
    waSaving: false,
    waError: '',
    waPlantillas: <?= json_encode(array_values($wa_plantillas), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    waForm: { id: null, nombre: '', contenido: '' },
    waCurrent: { id: 0, nombre: '', whatsapp: '' },
    waSelectedId: null,
    sending: false,
    filterTimer: null,
    formError: '',
    recipientSearch: '',
    attachmentName: '',
    pdfUrl: '<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>',
    pdfEmbedUrl: '<?= htmlspecialchars($pdf_url_embed, ENT_QUOTES) ?>',
    pdfDownloadUrl: '<?= htmlspecialchars($pdf_download_url, ENT_QUOTES) ?>',
    selected: [],
    csrf: '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>',
    recipients: <?= json_encode(array_values(array_map(
      fn($m) => [
        'id' => (string)$m['id'],
        'nombre' => $m['nombre'],
        'dni' => $m['dni'] ?? '',
        'correo' => $m['correo'] ?? '',
        'cargo' => $m['cargo'] ?? '',
      ],
      $militantes_activos_email
    )), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    allIds: <?= json_encode(array_values(array_map(
      fn($m) => (string)$m['id'],
      $militantes_activos_email
    ))) ?>,
    mensaje: { alcance: 'grupo' },
    progress: {
      total: 0,
      sent: 0,
      failed: 0,
      finished: false,
      items: []
    },
    form: {
      id: '',
      nombre: '',
      dni: '',
      celular: '',
      whatsapp: '',
      correo: '',
      cargo_id: '',
      fecha_ingreso: '<?= date('Y-m-d') ?>'
    },
    async buscarDniMilitante() {
      const dni = (this.form.dni || '').replace(/\D/g, '');
      if (dni.length !== 8) return;
      this.reniecLoading = true;
      try {
        const res = await fetch('ajax/reniec-lookup.php?dni=' + dni);
        const data = await res.json();
        if (data.ok) {
          this.form.nombre = data.data.nombre_completo;
        }
      } catch(_) {}
      this.reniecLoading = false;
    },

    openNew() {
      this.form = {
        id: '',
        nombre: '',
        dni: '',
        celular: '',
        whatsapp: '',
        correo: '',
        cargo_id: '',
        fecha_ingreso: '<?= date('Y-m-d') ?>'
      };
      this.modal = true;
    },
    submitFiltersDebounced() {
      clearTimeout(this.filterTimer);
      this.filterTimer = setTimeout(() => this.submitFiltersNow(), 650);
    },
    submitFiltersNow() {
      clearTimeout(this.filterTimer);
      const form = this.$refs.filtersForm;
      if (!form) return;
      const params = new URLSearchParams(new FormData(form));
      params.delete('pag');
      for (const [key, value] of [...params.entries()]) {
        if (value === '' || value === '0') params.delete(key);
      }
      const query = params.toString();
      window.location.href = 'militantes.php' + (query ? '?' + query : '');
    },
    openEdit(row) {
      this.form = {
        id: row.id || '',
        nombre: row.nombre || '',
        dni: row.dni || '',
        celular: row.celular || '',
        whatsapp: row.whatsapp || '',
        correo: row.correo || '',
        cargo_id: row.cargo_id ? String(row.cargo_id) : '',
        fecha_ingreso: row.fecha_ingreso || '<?= date('Y-m-d') ?>'
      };
      this.modal = true;
    },
    openMensaje() {
      this.selected = [];
      this.formError = '';
      this.recipientSearch = '';
      this.attachmentName = '';
      this.mensaje = { alcance: 'grupo' };
      this.mensajeModal = true;
    },
    openPdf() {
      this.pdfModal = true;
    },
    printPdf() {
      const frame = this.$refs.pdfFrame;
      if (!frame || !frame.contentWindow) return;
      frame.contentWindow.focus();
      frame.contentWindow.print();
    },
    downloadPdf() {
      window.location.href = this.pdfDownloadUrl;
    },
    filteredRecipients() {
      const q = this.recipientSearch.trim().toLowerCase();
      if (!q) return this.recipients;
      return this.recipients.filter((m) => {
        return [m.nombre, m.dni, m.correo, m.cargo]
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
      window.location.href = 'militantes.php?msg=correo_enviado';
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
          sendData.append('militante_id', item.id);

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
    openWaEnviar(militante) {
      this.waCurrent = militante;
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
      const response = await fetch('militantes.php', {
        method: 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload) {
        throw new Error(payload?.error || 'Respuesta invalida del servidor.');
      }
      return payload;
    }
  }
}
</script>

    </main>
  </div>
</body>
</html>
