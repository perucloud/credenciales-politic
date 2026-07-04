<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/webp.php';
require_once __DIR__ . '/../includes/helpers/qr.php';
require_once __DIR__ . '/../includes/helpers/reniec.php';

require_login();
require_modulo($pdo, 'credenciales_modulo');

$page_title = 'Credenciales';

// ── Helpers ───────────────────────────────────────────────────
function json_resp(array $d, int $s = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($s);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Configuración del PDF de credencial ────────────────────────
function titulo_font_catalog(): array {
    return [
        'DejaVu Sans' => '',
        'Helvetica' => '',
        'Times' => '',
        'Courier' => '',
        'Arial' => 'arial.ttf',
        'Arial Bold' => 'arialbd.ttf',
        'Arial Black' => 'ariblk.ttf',
        'Coolvetica' => 'coolvetica.ttf',
        'Montserrat Alternates Bold' => 'MontserratAlternates-Bold.ttf',
        'Swiss 721 Black' => 'Swis721 Blk BT Black.ttf',
        'Humanist 777 Black Italic' => 'Humnst777 Blk BT Black Italic.ttf',
        'Cream Cake Bold' => 'Cream Cake Bold.ttf',
        'Latenza Script' => 'LatenzaScript.ttf',
    ];
}

function cfg_pdf_default(): array {
    return [
        'plantilla_a4'     => '',
        'plantilla_reverso_a4' => '',
        'mensaje_partido'  => 'Por la presente, el partido reconoce y respalda la labor del titular de esta credencial en el cumplimiento de sus funciones partidarias, exhortando a las autoridades, instituciones y ciudadanía en general a brindarle las facilidades necesarias para el desarrollo de sus actividades de representación y organización política dentro de su jurisdicción.',
        'titulo_credencial'=> 'CREDENCIAL',
        'titulo_font_family' => 'DejaVu Sans',
        'titulo_font_size'   => 28,
        'titulo_font_weight' => 900,
        'titulo_italic'      => 1,
        'titulo_text_color'  => '#FFFFFF',
        'titulo_bg_color'    => '#1E3A8A',
        'titulo_banner_height' => 13,
        'titulo_radius'      => 2,
        'titulo_letter_spacing' => 2,
        'codigo_sufijo'    => 'IVSIS',
        'tamanos'          => ['a4', 'carnet'],
        'texto1'           => 'AL SEÑOR(A): {{nombres}}',
        'nombre_font_size' => 13,
        'texto3'           => 'IDENTIFICADO CON DNI N°: {{dni}}, SE LE OTORGA PLENAS FACULTADES PARA ORGANIZAR Y REPRESENTAR AL PARTIDO EN SU ÁMBITO GEOGRÁFICO, PARA LO CUAL SE LE DESIGNA COMO: {{cargo}} EN LA JURISDICCIÓN DEL {{ccpp_ccnn}}, DISTRITO DE: {{distrito}}, PROVINCIA DE: {{provincia}}',
        'texto4_ciudad'    => 'Satipo',
        'num_firmas'       => 2,
        'firmas'           => [
            ['nombre' => '', 'cargo' => '', 'imagen' => ''],
            ['nombre' => '', 'cargo' => '', 'imagen' => ''],
            ['nombre' => '', 'cargo' => '', 'imagen' => ''],
        ],
    ];
}

function cfg_pdf_get(PDO $pdo): array {
    $default = cfg_pdf_default();
    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ? LIMIT 1");
        $stmt->execute(['credencial_pdf_config']);
        $val = $stmt->fetchColumn();
        if ($val) {
            $data = json_decode((string)$val, true);
            if (is_array($data)) {
                $cfg = array_merge($default, $data);
                // asegurar 3 slots de firmas
                $firmas = is_array($cfg['firmas'] ?? null) ? $cfg['firmas'] : [];
                for ($i = 0; $i < 3; $i++) {
                    $firmas[$i] = array_merge(['nombre' => '', 'cargo' => '', 'imagen' => ''], $firmas[$i] ?? []);
                }
                $cfg['firmas'] = $firmas;
                return $cfg;
            }
        }
    } catch (Exception $e) {}
    return $default;
}

function cfg_pdf_save(PDO $pdo, array $cfg): void {
    $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('credencial_pdf_config', ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $stmt->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
}

function cfg_simple_get(PDO $pdo, string $clave, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ? LIMIT 1");
        $stmt->execute([$clave]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? $default : (string)$valor;
    } catch (Exception $e) {
        return $default;
    }
}

function cfg_simple_set(PDO $pdo, string $clave, string $valor): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $stmt->execute([$clave, $valor]);
    } catch (Exception $e) {}
}

function sanitize_credencial_rich_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|link|meta)[^>]*>.*?</\1>#is', '', $html);
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><span>');

    return preg_replace_callback('/<\\/?([a-z0-9]+)([^>]*)>/i', function ($m) {
        $tag = strtolower($m[1]);
        $closing = str_starts_with($m[0], '</');
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'span'];
        if (!in_array($tag, $allowed, true)) return '';
        if ($closing) return $tag === 'br' ? '' : "</$tag>";
        if ($tag !== 'span') return $tag === 'br' ? '<br>' : "<$tag>";

        $attrs = '';
        if (preg_match('/class=["\\\']([^"\\\']+)["\\\']/i', $m[2], $cm)) {
            $classes = array_filter(preg_split('/\\s+/', $cm[1]), fn($c) => in_array($c, ['ql-size-small', 'ql-size-large'], true));
            if ($classes) $attrs .= ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"';
        }
        if (preg_match('/style=["\\\']([^"\\\']+)["\\\']/i', $m[2], $sm)) {
            $styles = [];
            if (preg_match('/color\\s*:\\s*(#[0-9a-f]{3,6}|rgb\\([0-9,\\s]+\\))/i', $sm[1], $color)) {
                $styles[] = 'color:' . $color[1];
            }
            if ($styles) $attrs .= ' style="' . htmlspecialchars(implode(';', $styles), ENT_QUOTES, 'UTF-8') . '"';
        }
        return '<span' . $attrs . '>';
    }, $html) ?? '';
}

function normalizar_orientacion_jpeg(string $src, string $ext, string $dir, string $prefix): ?string {
    if (!in_array(strtolower($ext), ['jpg', 'jpeg'], true)) return null;
    if (!function_exists('exif_read_data') || !function_exists('imagecreatefromjpeg')) return null;

    $exif = @exif_read_data($src);
    $orientation = (int)($exif['Orientation'] ?? 1);
    if (!in_array($orientation, [2, 3, 4, 5, 6, 7, 8], true)) return null;

    $img = @imagecreatefromjpeg($src);
    if ($img === false) return null;

    switch ($orientation) {
        case 2:
            if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $img = imagerotate($img, 180, 0);
            break;
        case 4:
            if (function_exists('imageflip')) imageflip($img, IMG_FLIP_VERTICAL);
            break;
        case 5:
            if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL);
            $img = imagerotate($img, -90, 0);
            break;
        case 6:
            $img = imagerotate($img, -90, 0);
            break;
        case 7:
            if (function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL);
            $img = imagerotate($img, 90, 0);
            break;
        case 8:
            $img = imagerotate($img, 90, 0);
            break;
    }

    $tmp = $dir . $prefix . '_oriented.png';
    imagepng($img, $tmp);
    imagedestroy($img);
    return is_file($tmp) ? $tmp : null;
}

// Sube una imagen de configuración (plantilla o firma) a uploads/credenciales/config/
function upload_imagen_config(array $file, array $exts_permitidas, ?string $old_path = null, bool $preservar_orientacion = false, bool $mantener_original = false): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) return null;

    $max = 6 * 1024 * 1024;
    if ($file['size'] > $max) throw new RuntimeException('El archivo debe pesar máximo 6 MB.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts_permitidas, true)) {
        throw new RuntimeException('Formato no permitido (' . implode(', ', $exts_permitidas) . ').');
    }

    $dir = dirname(__DIR__) . '/uploads/credenciales/config/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $prefix = 'cfg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    if ($ext === 'svg' || $mantener_original) {
        $nombre = $prefix . '.svg';
        if ($ext !== 'svg') {
            $nombre = $prefix . '.' . $ext;
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . $nombre)) {
            throw new RuntimeException('No se pudo guardar la imagen subida.');
        }
    } else {
        // Reduce imágenes muy grandes (p.ej. plantillas a resolución de impresión)
        // a ~300dpi A4 (2480px de ancho) antes de convertir a WebP, para evitar
        // agotar la memoria de PHP con archivos de decenas de megapíxeles.
        $tmp_src = $file['tmp_name'];
        $src_ext = $ext;
        $tmp_files = [];
        $oriented = $preservar_orientacion ? null : normalizar_orientacion_jpeg($tmp_src, $src_ext, $dir, $prefix);
        if ($oriented) {
            $tmp_src = $oriented;
            $src_ext = 'png';
            $tmp_files[] = $oriented;
        }

        $info = @getimagesize($tmp_src);
        if ($info && $info[0] > 2480) {
            $img = match ($src_ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($tmp_src),
                'png'         => @imagecreatefrompng($tmp_src),
                'gif'         => @imagecreatefromgif($tmp_src),
                'webp'        => @imagecreatefromwebp($tmp_src),
                default       => false,
            };
            if ($img !== false) {
                $w = imagesx($img); $h = imagesy($img);
                $nw = 2480; $nh = (int)round($h * $nw / $w);
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $tmp_resized = $dir . $prefix . '_tmp.png';
                imagepng($dst, $tmp_resized);
                imagedestroy($dst);
                $tmp_src = $tmp_resized;
                $src_ext = 'png';
                $tmp_files[] = $tmp_resized;
            }
        }

        $webp_bytes = img_to_webp($tmp_src, $src_ext);
        foreach ($tmp_files as $tmp_file) {
            if (is_file($tmp_file)) @unlink($tmp_file);
        }

        if ($webp_bytes !== false) {
            $nombre = $prefix . '.webp';
            file_put_contents($dir . $nombre, $webp_bytes);
        } else {
            $nombre = $prefix . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dir . $nombre);
        }
    }

    if ($old_path) {
        $old_full = dirname(__DIR__) . '/' . $old_path;
        if (is_file($old_full)) @unlink($old_full);
    }

    return 'uploads/credenciales/config/' . $nombre;
}

function ensure_credenciales_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS credenciales (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        codigo              VARCHAR(30)  NOT NULL UNIQUE,
        qr_token            CHAR(32)     NOT NULL UNIQUE,
        persona_tipo        ENUM('militante','simpatizante') NOT NULL,
        persona_id          INT          NOT NULL,
        nombres_completos   VARCHAR(200) NOT NULL,
        dni                 CHAR(8)      NOT NULL,
        cargo               VARCHAR(150) NULL,
        correo              VARCHAR(150) NULL,
        centro_poblado      VARCHAR(150) NULL,
        comunidad_nativa    VARCHAR(150) NULL,
        distrito            VARCHAR(120) NULL,
        provincia           VARCHAR(120) NOT NULL DEFAULT 'Satipo',
        region              VARCHAR(120) NOT NULL DEFAULT 'Junín',
        direccion           VARCHAR(255) NULL,
        foto                VARCHAR(300) NULL,
        fecha_emision       DATE         NOT NULL,
        fecha_vencimiento   DATE         NOT NULL,
        estado              ENUM('activo','anulado','vencido') NOT NULL DEFAULT 'activo',
        creado_por          INT          NULL,
        creado_en           DATETIME     DEFAULT CURRENT_TIMESTAMP,
        actualizado_en      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_credenciales_estado (estado),
        INDEX idx_credenciales_dni (dni),
        INDEX idx_credenciales_persona (persona_tipo, persona_id),
        INDEX idx_credenciales_vencimiento (fecha_vencimiento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function ensure_credenciales_columnas_contacto(PDO $pdo): void {
    $needed = [
        'celular'  => "ALTER TABLE credenciales ADD celular VARCHAR(20) NULL AFTER correo",
        'whatsapp' => "ALTER TABLE credenciales ADD whatsapp VARCHAR(20) NULL AFTER celular",
    ];
    $cols = $pdo->query("SHOW COLUMNS FROM credenciales")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($needed as $column => $sql) {
        if (!in_array($column, $cols, true)) {
            $pdo->exec($sql);
        }
    }
}
function ensure_centros_poblados_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS centros_poblados (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(200) NOT NULL,
        distrito   VARCHAR(120) NULL,
        provincia  VARCHAR(120) NULL,
        region     VARCHAR(120) NULL,
        activo     TINYINT(1) NOT NULL DEFAULT 1,
        creado_en  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cp_nombre_distrito (nombre, distrito)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function ensure_comunidades_nativas_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comunidades_nativas (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(200) NOT NULL,
        distrito   VARCHAR(120) NULL,
        provincia  VARCHAR(120) NULL,
        region     VARCHAR(120) NULL,
        activo     TINYINT(1) NOT NULL DEFAULT 1,
        creado_en  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ccnn_nombre_distrito (nombre, distrito)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function seed_jurisdiccion_junin(PDO $pdo): void {
    // Centros poblados ─────────────────────────────────────────────
    $cpCount = (int)$pdo->query("SELECT COUNT(*) FROM centros_poblados")->fetchColumn();
    if ($cpCount === 0) {
        $cp = [
            // ── Satipo / Satipo ──────────────────────────────────
            ['Satipo','Satipo','Satipo','Junín'],
            ['Paratushiali','Satipo','Satipo','Junín'],
            ['Alto Paratushiali','Satipo','Satipo','Junín'],
            ['Bajo Paratushiali','Satipo','Satipo','Junín'],
            ['Vista Alegre','Satipo','Satipo','Junín'],
            ['Unión Capiri','Satipo','Satipo','Junín'],
            ['San Pedro','Satipo','Satipo','Junín'],
            ['San Francisco de Satipo','Satipo','Satipo','Junín'],
            ['Palestina','Satipo','Satipo','Junín'],
            ['Alto Sangani','Satipo','Satipo','Junín'],
            ['Bajo Sangani','Satipo','Satipo','Junín'],
            ['Tres Unidos','Satipo','Satipo','Junín'],
            ['Libertad de Junín','Satipo','Satipo','Junín'],
            ['Pampa Silva','Satipo','Satipo','Junín'],
            ['San Nicolás de Satipo','Satipo','Satipo','Junín'],
            ['Bajo Aldea','Satipo','Satipo','Junín'],
            ['Alto Aldea','Satipo','Satipo','Junín'],
            ['Shanki','Satipo','Satipo','Junín'],
            ['Alto Shanki','Satipo','Satipo','Junín'],
            ['Bajo Esperanza','Satipo','Satipo','Junín'],
            ['Alto Esperanza','Satipo','Satipo','Junín'],
            ['Huancayo de Satipo','Satipo','Satipo','Junín'],
            ['Campo Verde','Satipo','Satipo','Junín'],
            ['Río Seco','Satipo','Satipo','Junín'],
            // ── Satipo / Coviriali ────────────────────────────────
            ['Coviriali','Coviriali','Satipo','Junín'],
            ['Alto Coviriali','Coviriali','Satipo','Junín'],
            ['Bajo Coviriali','Coviriali','Satipo','Junín'],
            ['Aynamayo','Coviriali','Satipo','Junín'],
            ['La Florida de Coviriali','Coviriali','Satipo','Junín'],
            // ── Satipo / Llaylla ─────────────────────────────────
            ['Llaylla','Llaylla','Satipo','Junín'],
            ['Alto Llaylla','Llaylla','Satipo','Junín'],
            ['Yanacocha','Llaylla','Satipo','Junín'],
            ['Churingaveni','Llaylla','Satipo','Junín'],
            // ── Satipo / Mazamari ─────────────────────────────────
            ['Mazamari','Mazamari','Satipo','Junín'],
            ['San Antonio de Sonomoro','Mazamari','Satipo','Junín'],
            ['Alto Sonomoro','Mazamari','Satipo','Junín'],
            ['Puerto Sonomoro','Mazamari','Satipo','Junín'],
            ['Boca Sonomoro','Mazamari','Satipo','Junín'],
            ['Shankivironi','Mazamari','Satipo','Junín'],
            ['Porvenir de Mazamari','Mazamari','Satipo','Junín'],
            // ── Satipo / Pampa Hermosa ────────────────────────────
            ['Pampa Hermosa','Pampa Hermosa','Satipo','Junín'],
            ['Quiteni','Pampa Hermosa','Satipo','Junín'],
            ['Alto Kiatari','Pampa Hermosa','Satipo','Junín'],
            ['Bajo Kiatari','Pampa Hermosa','Satipo','Junín'],
            ['Alto Yurinaki','Pampa Hermosa','Satipo','Junín'],
            // ── Satipo / Pangoa ───────────────────────────────────
            ['San Martín de Pangoa','Pangoa','Satipo','Junín'],
            ['Cubantía','Pangoa','Satipo','Junín'],
            ['Valle Pangoa','Pangoa','Satipo','Junín'],
            ['Alto Pangoa','Pangoa','Satipo','Junín'],
            ['Alto Saniveni','Pangoa','Satipo','Junín'],
            ['Tsomaveni','Pangoa','Satipo','Junín'],
            ['Porvenir de Pangoa','Pangoa','Satipo','Junín'],
            ['Oviri','Pangoa','Satipo','Junín'],
            ['San Ramón de Pangoa','Pangoa','Satipo','Junín'],
            ['Bajo Tsomaveni','Pangoa','Satipo','Junín'],
            // ── Satipo / Río Negro ────────────────────────────────
            ['Río Negro','Río Negro','Satipo','Junín'],
            ['Puerto Ocopa','Río Negro','Satipo','Junín'],
            ['San Jerónimo de Río Negro','Río Negro','Satipo','Junín'],
            ['Chavini','Río Negro','Satipo','Junín'],
            ['Shimapucha','Río Negro','Satipo','Junín'],
            ['Santa Rosa de Río Negro','Río Negro','Satipo','Junín'],
            ['Pangoa de Río Negro','Río Negro','Satipo','Junín'],
            // ── Satipo / Río Tambo ────────────────────────────────
            ['Puerto Prado','Río Tambo','Satipo','Junín'],
            ['Betania','Río Tambo','Satipo','Junín'],
            ['Paureli','Río Tambo','Satipo','Junín'],
            ['Cushireni','Río Tambo','Satipo','Junín'],
            ['Anapati','Río Tambo','Satipo','Junín'],
            ['Potsoteni','Río Tambo','Satipo','Junín'],
            ['San Ramón de Picha','Río Tambo','Satipo','Junín'],
            ['Boca Tambo','Río Tambo','Satipo','Junín'],
            ['Impamequiari','Río Tambo','Satipo','Junín'],
            // ── Satipo / Vizcatán del Ene ─────────────────────────
            ['Puerto Rico','Vizcatán del Ene','Satipo','Junín'],
            ['Ayne','Vizcatán del Ene','Satipo','Junín'],
            ['Alto Anapati','Vizcatán del Ene','Satipo','Junín'],
            // ── Chanchamayo ───────────────────────────────────────
            ['La Merced','Chanchamayo','Chanchamayo','Junín'],
            ['San Ramón','Chanchamayo','Chanchamayo','Junín'],
            ['Villa Rica de Chanchamayo','Chanchamayo','Chanchamayo','Junín'],
            ['Pichanaqui','Pichanaqui','Chanchamayo','Junín'],
            ['Perené','Perené','Chanchamayo','Junín'],
            ['San Luis de Shuaro','San Luis de Shuaro','Chanchamayo','Junín'],
            ['Villa Perené','Perené','Chanchamayo','Junín'],
            ['Alto Perené','Perené','Chanchamayo','Junín'],
            ['Bajo Pichanaqui','Pichanaqui','Chanchamayo','Junín'],
            ['San Isidro de Perené','Perené','Chanchamayo','Junín'],
            ['Pampa Juliana','Perené','Chanchamayo','Junín'],
            // ── Huancayo ─────────────────────────────────────────
            ['Huancayo','Huancayo','Huancayo','Junín'],
            ['El Tambo','El Tambo','Huancayo','Junín'],
            ['Chilca','Chilca','Huancayo','Junín'],
            ['Huancán','Huancán','Huancayo','Junín'],
            ['Sapallanga','Sapallanga','Huancayo','Junín'],
            ['Pilcomayo','Pilcomayo','Huancayo','Junín'],
            ['San Agustín de Cajas','San Agustín','Huancayo','Junín'],
            ['Pucará','Pucará','Huancayo','Junín'],
            ['Viques','Viques','Huancayo','Junín'],
            // ── Jauja ─────────────────────────────────────────────
            ['Jauja','Jauja','Jauja','Junín'],
            ['Marco','Marco','Jauja','Junín'],
            ['Acolla','Acolla','Jauja','Junín'],
            ['Masma','Masma','Jauja','Junín'],
            ['Apata','Apata','Jauja','Junín'],
            ['Muquiyauyo','Muquiyauyo','Jauja','Junín'],
            ['Mito','Mito','Jauja','Junín'],
            // ── Tarma ─────────────────────────────────────────────
            ['Tarma','Tarma','Tarma','Junín'],
            ['Acobamba','Acobamba','Tarma','Junín'],
            ['Huasahuasi','Huasahuasi','Tarma','Junín'],
            ['La Unión','La Unión','Tarma','Junín'],
            ['San Pedro de Cajas','San Pedro de Cajas','Tarma','Junín'],
            ['Palcamayo','Palcamayo','Tarma','Junín'],
            // ── Junín ─────────────────────────────────────────────
            ['Junín','Junín','Junín','Junín'],
            ['Carhuamayo','Carhuamayo','Junín','Junín'],
            ['Ondores','Ondores','Junín','Junín'],
            ['Ulcumayo','Ulcumayo','Junín','Junín'],
            // ── Yauli ─────────────────────────────────────────────
            ['La Oroya','Yauli','Yauli','Junín'],
            ['Morococha','Morococha','Yauli','Junín'],
            ['Santa Bárbara de Carhuacayán','Santa Bárbara de Carhuacayán','Yauli','Junín'],
            ['Santa Rosa de Sacco','Santa Rosa de Sacco','Yauli','Junín'],
            // ── Concepción ────────────────────────────────────────
            ['Concepción','Concepción','Concepción','Junín'],
            ['Matahuasi','Matahuasi','Concepción','Junín'],
            ['Santa Rosa de Ocopa','Santa Rosa de Ocopa','Concepción','Junín'],
            ['Orcotuna','Orcotuna','Concepción','Junín'],
            ['Comas','Comas','Concepción','Junín'],
            // ── Chupaca ───────────────────────────────────────────
            ['Chupaca','Chupaca','Chupaca','Junín'],
            ['Chongos Bajo','Chongos Bajo','Chupaca','Junín'],
            ['Ahuac','Ahuac','Chupaca','Junín'],
            ['Huamancaca Chico','Huamancaca Chico','Chupaca','Junín'],
        ];
        $s = $pdo->prepare("INSERT IGNORE INTO centros_poblados (nombre,distrito,provincia,region) VALUES (?,?,?,?)");
        foreach ($cp as $r) $s->execute($r);
    }

    // Comunidades nativas ──────────────────────────────────────────
    $ccnnCount = (int)$pdo->query("SELECT COUNT(*) FROM comunidades_nativas")->fetchColumn();
    if ($ccnnCount === 0) {
        $ccnn = [
            // ── Río Tambo (Asháninka / Ashéninka) ─────────────────
            ['Cutivireni','Río Tambo','Satipo','Junín'],
            ['Alto Quimiriki','Río Tambo','Satipo','Junín'],
            ['Potsoteni','Río Tambo','Satipo','Junín'],
            ['Saniveni','Río Tambo','Satipo','Junín'],
            ['Anapati','Río Tambo','Satipo','Junín'],
            ['Betania','Río Tambo','Satipo','Junín'],
            ['Cushireni','Río Tambo','Satipo','Junín'],
            ['Paureli','Río Tambo','Satipo','Junín'],
            ['Marankehari','Río Tambo','Satipo','Junín'],
            ['Impamequiari','Río Tambo','Satipo','Junín'],
            ['Camantavishi','Río Tambo','Satipo','Junín'],
            ['Boca Tambo','Río Tambo','Satipo','Junín'],
            ['Alto Tambo','Río Tambo','Satipo','Junín'],
            ['Shintiari','Río Tambo','Satipo','Junín'],
            // ── Pangoa (Nomatsiguenga / Asháninka) ────────────────
            ['Tsiriari','Pangoa','Satipo','Junín'],
            ['Camajeni','Pangoa','Satipo','Junín'],
            ['Sharingaveni','Pangoa','Satipo','Junín'],
            ['Alto Tsomaveni','Pangoa','Satipo','Junín'],
            ['Selva de Oro','Pangoa','Satipo','Junín'],
            ['Otica','Pangoa','Satipo','Junín'],
            ['Coriteni Tarso','Pangoa','Satipo','Junín'],
            // ── Río Negro ─────────────────────────────────────────
            ['Chavini','Río Negro','Satipo','Junín'],
            ['Shimapucha','Río Negro','Satipo','Junín'],
            ['Oviri','Río Negro','Satipo','Junín'],
            // ── Pampa Hermosa ─────────────────────────────────────
            ['Alto Kiatari','Pampa Hermosa','Satipo','Junín'],
            ['Quiteni','Pampa Hermosa','Satipo','Junín'],
            // ── Vizcatán del Ene (Kakinte / Asháninka) ────────────
            ['Ayne','Vizcatán del Ene','Satipo','Junín'],
            ['Alto Anapati','Vizcatán del Ene','Satipo','Junín'],
            ['Kakinte','Vizcatán del Ene','Satipo','Junín'],
            // ── Chanchamayo ───────────────────────────────────────
            ['Marankiari','Perené','Chanchamayo','Junín'],
            ['Bajo Marankiari','Perené','Chanchamayo','Junín'],
            ['Pampa Michi','San Luis de Shuaro','Chanchamayo','Junín'],
            ['Meritani','Chanchamayo','Chanchamayo','Junín'],
            ['Camantavishi','Chanchamayo','Chanchamayo','Junín'],
            ['Alto Pichanaqui','Pichanaqui','Chanchamayo','Junín'],
            ['Yurinaki','Perené','Chanchamayo','Junín'],
            // ── Satipo / Mazamari ─────────────────────────────────
            ['Shankivironi','Mazamari','Satipo','Junín'],
            ['Alto Sonomoro','Mazamari','Satipo','Junín'],
            ['Puerto Sonomoro','Mazamari','Satipo','Junín'],
        ];
        $s2 = $pdo->prepare("INSERT IGNORE INTO comunidades_nativas (nombre,distrito,provincia,region) VALUES (?,?,?,?)");
        foreach ($ccnn as $r) $s2->execute($r);
    }
}
// ── Schema / seed: solo una vez por sesión ────────────────────
if (empty($_SESSION['cred_schema_ok'])) {
    ensure_credenciales_table($pdo);
    ensure_credenciales_columnas_contacto($pdo);
    ensure_centros_poblados_table($pdo);
    ensure_comunidades_nativas_table($pdo);
    seed_jurisdiccion_junin($pdo);
    $_SESSION['cred_schema_ok'] = true;
}

// Marca como vencidas las credenciales activas cuya fecha ya pasó.
// Se ejecuta en página completa (no en AJAX) y máximo una vez por hora.
function actualizar_vencidas(PDO $pdo): void {
    $pdo->exec("UPDATE credenciales SET estado='vencido'
                WHERE estado='activo' AND fecha_vencimiento < CURDATE()");
}
$_is_ajax_req = isset($_GET['json'], $_GET['cfg_pdf_get'], $_GET['buscar_persona'],
                      $_GET['ccpp'], $_GET['ccnn'], $_GET['preview_cfg_set'])
                || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (!$_is_ajax_req) {
    $last_venc = $_SESSION['cred_vencidas_ts'] ?? 0;
    if ((time() - $last_venc) > 3600) {
        actualizar_vencidas($pdo);
        $_SESSION['cred_vencidas_ts'] = time();
    }
}
unset($_is_ajax_req);

// Catálogo de cargos dirigenciales (mismo origen que en militantes/simpatizantes)
$cargos_catalogo = $pdo->query(
    "SELECT id, nombre FROM militante_cargos WHERE activo=1 ORDER BY orden ASC, nombre ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Genera el siguiente código correlativo: IVSIS{AÑO}{0001}
function generar_codigo_credencial(PDO $pdo): string {
    $anio   = date('Y');
    $sufijo = cfg_pdf_get($pdo)['codigo_sufijo'] ?: 'IVSIS';
    $prefix = $sufijo . $anio . '-';
    $stmt = $pdo->prepare("SELECT codigo FROM credenciales WHERE codigo LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $ultimo = $stmt->fetchColumn();
    $siguiente = 1;
    if ($ultimo) {
        $num = (int)substr((string)$ultimo, strlen($prefix));
        $siguiente = $num + 1;
    }

    $contadorManual = (int)cfg_simple_get($pdo, 'credenciales_codigo_next', '0');
    if ($contadorManual > 0) {
        $siguiente = max($siguiente, $contadorManual);
    }

    do {
        $codigo = $prefix . str_pad((string)$siguiente, 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT COUNT(*) FROM credenciales WHERE codigo = ?");
        $check->execute([$codigo]);
        if ((int)$check->fetchColumn() === 0) break;
        $siguiente++;
    } while (true);

    cfg_simple_set($pdo, 'credenciales_codigo_next', (string)($siguiente + 1));
    return $codigo;
}

// ── Subida de foto (mismo patron que personeros) ──────────────
function upload_foto_credencial(array $file, ?string $old_foto): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) return null;

    $max = 4 * 1024 * 1024;
    if ($file['size'] > $max) throw new RuntimeException('La foto debe pesar máximo 4 MB.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) throw new RuntimeException('Solo JPG, PNG o WEBP.');

    $dir = dirname(__DIR__) . '/uploads/credenciales/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $prefix = 'cred_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

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

    return 'uploads/credenciales/' . $nombre;
}

// ── Endpoint: guardar config PDF en session para preview (sin tocar BD) ──
if (isset($_GET['preview_cfg_set']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $cfg = cfg_pdf_get($pdo); // parte de la config guardada (para las imágenes)
    $cfg['mensaje_partido']      = trim((string)($_POST['mensaje_partido']   ?? $cfg['mensaje_partido']));
    $cfg['titulo_credencial']    = trim((string)($_POST['titulo_credencial'] ?? $cfg['titulo_credencial'])) ?: 'CREDENCIAL';
    $font = trim((string)($_POST['titulo_font_family'] ?? $cfg['titulo_font_family']));
    $cfg['titulo_font_family']   = array_key_exists($font, titulo_font_catalog()) ? $font : 'DejaVu Sans';
    $cfg['titulo_font_size']     = max(16, min(42, (float)($_POST['titulo_font_size'] ?? $cfg['titulo_font_size'])));
    $weight = (int)($_POST['titulo_font_weight'] ?? $cfg['titulo_font_weight']);
    $cfg['titulo_font_weight']   = in_array($weight, [600,700,800,900], true) ? $weight : 900;
    $cfg['titulo_italic']        = !empty($_POST['titulo_italic']) ? 1 : 0;
    $tc = strtoupper(trim((string)($_POST['titulo_text_color'] ?? $cfg['titulo_text_color'])));
    $bc = strtoupper(trim((string)($_POST['titulo_bg_color']   ?? $cfg['titulo_bg_color'])));
    $cfg['titulo_text_color']    = preg_match('/^#[0-9A-F]{6}$/', $tc) ? $tc : '#FFFFFF';
    $cfg['titulo_bg_color']      = preg_match('/^#[0-9A-F]{6}$/', $bc) ? $bc : '#1E3A8A';
    $cfg['titulo_banner_height'] = max(10, min(20, (float)($_POST['titulo_banner_height'] ?? $cfg['titulo_banner_height'])));
    $cfg['titulo_radius']        = max(0, min(8,  (float)($_POST['titulo_radius']         ?? $cfg['titulo_radius'])));
    $cfg['titulo_letter_spacing']= max(0, min(6,  (float)($_POST['titulo_letter_spacing'] ?? $cfg['titulo_letter_spacing'])));
    $sufijo = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim((string)($_POST['codigo_sufijo'] ?? $cfg['codigo_sufijo']))));
    $cfg['codigo_sufijo']        = $sufijo !== '' ? $sufijo : 'IVSIS';
    $cfg['texto1']               = trim((string)($_POST['texto1'] ?? $cfg['texto1']));
    $cfg['nombre_font_size']     = max(10, min(24, (float)($_POST['nombre_font_size'] ?? $cfg['nombre_font_size'] ?? 13)));
    $cfg['texto3']               = sanitize_credencial_rich_html((string)($_POST['texto3'] ?? $cfg['texto3']));
    $cfg['texto4_ciudad']        = trim((string)($_POST['texto4_ciudad'] ?? $cfg['texto4_ciudad']));
    $tamanos = $_POST['tamanos'] ?? [];
    $tamanos = is_array($tamanos) ? array_values(array_intersect($tamanos, ['a4','carnet'])) : [];
    $cfg['tamanos']   = !empty($tamanos) ? $tamanos : ['a4'];
    $cfg['num_firmas']= max(1, min(3, (int)($_POST['num_firmas'] ?? $cfg['num_firmas'])));
    $firmas_post = $_POST['firmas'] ?? [];
    for ($i = 0; $i < 3; $i++) {
        $cfg['firmas'][$i]['nombre'] = trim((string)($firmas_post[$i]['nombre'] ?? $cfg['firmas'][$i]['nombre']));
        $cfg['firmas'][$i]['cargo']  = trim((string)($firmas_post[$i]['cargo']  ?? $cfg['firmas'][$i]['cargo']));
    }
    $_SESSION['preview_cfg_tmp'] = $cfg;
    json_resp(['ok' => true]);
}

// ── Endpoint JSON: refresco de listado ────────────────────────
if (isset($_GET['json'])) {
    while (ob_get_level() > 0) ob_end_clean();
    $list = $pdo->query("SELECT * FROM credenciales ORDER BY id DESC")->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['credenciales' => array_values($list)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint JSON: configuración del PDF de credencial ─────────
if (isset($_GET['cfg_pdf_get'])) {
    json_resp(['ok' => true, 'cfg' => cfg_pdf_get($pdo)]);
}

// ── Endpoint JSON: búsqueda de persona (militante/simpatizante) ─
if (isset($_GET['buscar_persona'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $tipo = ($_GET['tipo'] ?? '') === 'simpatizante' ? 'simpatizante' : 'militante';
    $q    = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['resultados' => []]); exit; }

    $like = '%' . $q . '%';
    $resultados = [];
    try {
        if ($tipo === 'militante') {
            $stmt = $pdo->prepare(
                "SELECT m.id, m.nombre, m.dni, m.correo, c.nombre AS cargo
                 FROM militantes m
                 LEFT JOIN militante_cargos c ON c.id = m.cargo_id
                 WHERE (m.nombre LIKE ? OR m.dni LIKE ?) AND m.estado='activo'
                 ORDER BY m.nombre ASC LIMIT 12"
            );
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $resultados[] = [
                    'id' => (int)$r['id'], 'nombre' => $r['nombre'], 'dni' => $r['dni'],
                    'correo' => $r['correo'], 'cargo' => $r['cargo'], 'tipo' => 'militante',
                ];
            }
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, nombre, dni, correo, distrito
                 FROM simpatizantes
                 WHERE (nombre LIKE ? OR dni LIKE ?)
                 ORDER BY nombre ASC LIMIT 12"
            );
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $resultados[] = [
                    'id' => (int)$r['id'], 'nombre' => $r['nombre'], 'dni' => $r['dni'],
                    'correo' => $r['correo'], 'cargo' => 'Simpatizante', 'distrito' => $r['distrito'],
                    'tipo' => 'simpatizante',
                ];
            }
        }
    } catch (Exception $e) {}
    echo json_encode(['resultados' => $resultados], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint JSON: consulta de persona por DNI (RENIEC + BD interna) ─
if (isset($_GET['consultar_dni'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $dni = trim((string)($_GET['dni'] ?? ''));
    if (!preg_match('/^\d{8}$/', $dni)) {
        echo json_encode(['ok' => false, 'msg' => 'El DNI debe tener 8 dígitos.']);
        exit;
    }

    // 1) Buscar en la BD interna para determinar el origen (militante / simpatizante)
    $origen = null;
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.nombre, m.dni, m.correo, c.nombre AS cargo
             FROM militantes m
             LEFT JOIN militante_cargos c ON c.id = m.cargo_id
             WHERE m.dni = ? LIMIT 1"
        );
        $stmt->execute([$dni]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $origen = [
                'tipo' => 'militante', 'id' => (int)$r['id'], 'nombre' => $r['nombre'],
                'dni' => $r['dni'], 'correo' => $r['correo'], 'cargo' => $r['cargo'],
            ];
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, nombre, dni, correo, distrito FROM simpatizantes WHERE dni = ? LIMIT 1"
            );
            $stmt->execute([$dni]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $origen = [
                    'tipo' => 'simpatizante', 'id' => (int)$r['id'], 'nombre' => $r['nombre'],
                    'dni' => $r['dni'], 'correo' => $r['correo'], 'cargo' => 'Simpatizante',
                    'distrito' => $r['distrito'],
                ];
            }
        }
    } catch (Exception $e) {}

    // 2) Consultar RENIEC (si está configurado) para obtener/confirmar el nombre
    $reniec = consultar_reniec_dni($dni);

    if (!$origen && !$reniec) {
        echo json_encode(['ok' => false, 'msg' => 'No se encontró ningún militante o simpatizante registrado con ese DNI.']);
        exit;
    }

    echo json_encode([
        'ok'     => true,
        'origen' => $origen,
        'reniec' => $reniec,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint JSON: sugerencias de Centro Poblado / Comunidad Nativa ─
// ── GET: sugerencias de jurisdicción desde tablas dedicadas ──────
if (isset($_GET['sugerir_jurisdiccion'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $esCcnn  = ($_GET['campo'] ?? '') === 'comunidad_nativa';
    $tabla   = $esCcnn ? 'comunidades_nativas' : 'centros_poblados';
    $q       = trim((string)($_GET['q'] ?? ''));
    $distrito = trim((string)($_GET['distrito'] ?? ''));

    $sugerencias = [];
    try {
        $where  = 'activo = 1';
        $params = [];
        if ($q !== '') {
            $where   .= ' AND nombre LIKE ?';
            $params[] = '%' . $q . '%';
        }
        if ($distrito !== '') {
            $where   .= ' AND (distrito = ? OR distrito IS NULL)';
            $params[] = $distrito;
        }
        // Sin q: lista completa para el select client-side (sin límite estricto)
        $limit = ($q === '') ? 500 : 30;
        $stmt = $pdo->prepare("SELECT nombre FROM $tabla WHERE $where ORDER BY nombre ASC LIMIT $limit");
        $stmt->execute($params);
        $sugerencias = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nombre');

        // Fallback: incluye valores ya usados en credenciales si hay búsqueda activa
        if ($q !== '') {
            $campoCol = $esCcnn ? 'comunidad_nativa' : 'centro_poblado';
            $stmt2 = $pdo->prepare(
                "SELECT DISTINCT $campoCol AS nombre FROM credenciales
                 WHERE $campoCol IS NOT NULL AND $campoCol <> '' AND $campoCol LIKE ?
                 ORDER BY $campoCol ASC LIMIT 10"
            );
            $stmt2->execute(['%' . $q . '%']);
            foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $v) {
                if (!in_array($v, $sugerencias, true)) $sugerencias[] = $v;
            }
            sort($sugerencias);
        }
    } catch (Exception $e) {}

    echo json_encode(['sugerencias' => $sugerencias], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: crear cargo dirigencial ──────────────────────────────────
if (isset($_GET['crear_cargo'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $nombre = trim((string)($_GET['nombre'] ?? ''));
    if ($nombre === '') { echo json_encode(['ok' => false, 'msg' => 'Nombre vacío']); exit; }
    try {
        $pdo->prepare("INSERT IGNORE INTO militante_cargos (nombre, orden, activo) VALUES (?, 99, 1)")->execute([$nombre]);
        $id = (int)$pdo->lastInsertId();
        if ($id === 0) {
            $r = $pdo->prepare("SELECT id FROM militante_cargos WHERE nombre = ? LIMIT 1");
            $r->execute([$nombre]);
            $id = (int)($r->fetchColumn() ?: 0);
        }
        echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── GET: crear centro poblado ─────────────────────────────────────
if (isset($_GET['crear_ccpp'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $nombre   = trim((string)($_GET['nombre'] ?? ''));
    $distrito = trim((string)($_GET['distrito'] ?? ''));
    $provincia= trim((string)($_GET['provincia'] ?? ''));
    $region   = trim((string)($_GET['region'] ?? ''));
    if ($nombre === '') { echo json_encode(['ok' => false, 'msg' => 'Nombre vacío']); exit; }
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO centros_poblados (nombre, distrito, provincia, region) VALUES (?,?,?,?)"
        )->execute([$nombre, $distrito ?: null, $provincia ?: null, $region ?: null]);
        echo json_encode(['ok' => true, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── GET: crear comunidad nativa ───────────────────────────────────
if (isset($_GET['crear_ccnn'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $nombre   = trim((string)($_GET['nombre'] ?? ''));
    $distrito = trim((string)($_GET['distrito'] ?? ''));
    $provincia= trim((string)($_GET['provincia'] ?? ''));
    $region   = trim((string)($_GET['region'] ?? ''));
    if ($nombre === '') { echo json_encode(['ok' => false, 'msg' => 'Nombre vacío']); exit; }
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO comunidades_nativas (nombre, distrito, provincia, region) VALUES (?,?,?,?)"
        )->execute([$nombre, $distrito ?: null, $provincia ?: null, $region ?: null]);
        echo json_encode(['ok' => true, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── GET: listar centros poblados / comunidades nativas (tab Gestión) ──
if (isset($_GET['jur_listar'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $tablaJ = ($_GET['tabla'] ?? '') === 'ccnn' ? 'comunidades_nativas' : 'centros_poblados';
    $q    = trim((string)($_GET['q'] ?? ''));
    $prov = trim((string)($_GET['provincia'] ?? ''));
    $dist = trim((string)($_GET['distrito'] ?? ''));

    $where = ['1=1']; $params = [];
    if ($q    !== '') { $where[] = 'nombre LIKE ?';  $params[] = "%$q%"; }
    if ($prov !== '') { $where[] = 'provincia = ?';  $params[] = $prov; }
    if ($dist !== '') { $where[] = 'distrito = ?';   $params[] = $dist; }

    $stmt = $pdo->prepare(
        "SELECT id, nombre, distrito, provincia, region FROM $tablaJ WHERE "
        . implode(' AND ', $where) . " ORDER BY distrito, nombre ASC"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
$allowed_actions = ['save_credencial', 'delete_credencial', 'cambiar_estado', 'jur_crear', 'jur_editar', 'jur_eliminar', 'guardar_cfg_pdf', 'enviar_credencial_email'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, $allowed_actions, true)) csrf_verify();
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Guardar (crear / editar) ──────────────────────────────
    if ($action === 'save_credencial') {
        $id               = (int)($_POST['id'] ?? 0);
        $persona_tipo     = in_array($_POST['persona_tipo'] ?? '', ['militante','simpatizante'], true) ? $_POST['persona_tipo'] : '';
        $persona_id       = (int)($_POST['persona_id'] ?? 0);
        $nombres          = trim($_POST['nombres_completos'] ?? '');
        $dni              = trim($_POST['dni'] ?? '');
        $cargo            = trim($_POST['cargo'] ?? '') ?: null;
        $correo           = trim($_POST['correo'] ?? '') ?: null;
        $celular          = trim($_POST['celular'] ?? '') ?: null;
        $whatsapp         = trim($_POST['whatsapp'] ?? '') ?: null;
        $centro_poblado   = trim($_POST['centro_poblado'] ?? '') ?: null;
        $comunidad_nativa = trim($_POST['comunidad_nativa'] ?? '') ?: null;
        $distrito         = trim($_POST['distrito'] ?? '') ?: null;
        $provincia        = trim($_POST['provincia'] ?? '') ?: 'Satipo';
        $region           = trim($_POST['region'] ?? '') ?: 'Junín';
        $direccion        = trim($_POST['direccion'] ?? '') ?: null;
        $fecha_emision    = trim($_POST['fecha_emision'] ?? '');
        $fecha_vencimiento= trim($_POST['fecha_vencimiento'] ?? '');
        $estado           = in_array($_POST['estado'] ?? '', ['activo','anulado','vencido'], true) ? $_POST['estado'] : 'activo';

        if ($id <= 0 && $persona_tipo === '') {
            json_resp(['ok'=>false,'msg'=>'Selecciona el origen (militante o simpatizante).']);
        }
        if ($nombres === '') json_resp(['ok'=>false,'msg'=>'Los nombres y apellidos son obligatorios.']);
        if (!preg_match('/^\d{8}$/', $dni)) json_resp(['ok'=>false,'msg'=>'El DNI debe tener exactamente 8 dígitos.']);
        if ($fecha_emision === '' || $fecha_vencimiento === '') json_resp(['ok'=>false,'msg'=>'Las fechas de emisión y vencimiento son obligatorias.']);
        if (strtotime($fecha_vencimiento) <= strtotime($fecha_emision)) {
            json_resp(['ok'=>false,'msg'=>'La fecha de vencimiento debe ser posterior a la de emisión.']);
        }

        // Si el DNI no existe en militantes/simpatizantes, se crea automáticamente
        // en la tabla elegida, usando el nombre confirmado (RENIEC o ingresado).
        if ($id <= 0 && $persona_id <= 0) {
            $tabla = $persona_tipo === 'militante' ? 'militantes' : 'simpatizantes';
            $existe = $pdo->prepare("SELECT id FROM $tabla WHERE dni = ? LIMIT 1");
            $existe->execute([$dni]);
            $persona_id = (int)($existe->fetchColumn() ?: 0);

            if ($persona_id <= 0) {
                if ($persona_tipo === 'militante') {
                    $pdo->prepare("INSERT INTO militantes (nombre, dni, correo, fecha_ingreso, estado) VALUES (?,?,?,?, 'activo')")
                        ->execute([$nombres, $dni, $correo, date('Y-m-d')]);
                } else {
                    $pdo->prepare("INSERT INTO simpatizantes (nombre, dni, correo, distrito, estado) VALUES (?,?,?,?, 'activo')")
                        ->execute([$nombres, $dni, $correo, $distrito]);
                }
                $persona_id = (int)$pdo->lastInsertId();
                log_activity($pdo, 'Creó ' . $persona_tipo . ' automáticamente desde módulo de credenciales: ' . $nombres . ' (' . $dni . ')', 'credenciales_modulo');
            }
        }

        $old_foto = null;
        $old_qr_token = '';
        if ($id > 0) {
            $r = $pdo->prepare("SELECT foto, qr_token FROM credenciales WHERE id=?");
            $r->execute([$id]);
            $old_row = $r->fetch(PDO::FETCH_ASSOC) ?: [];
            $old_foto = $old_row['foto'] ?? null;
            $old_qr_token = (string)($old_row['qr_token'] ?? '');
        }

        try {
            $foto_path = upload_foto_credencial($_FILES['foto'] ?? [], $old_foto);
        } catch (RuntimeException $e) {
            json_resp(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        $foto_final = $foto_path ?? ($id > 0 ? $old_foto : null);

        // recalcular estado si la fecha de vencimiento ya pasó
        if ($estado === 'activo' && strtotime($fecha_vencimiento) < strtotime(date('Y-m-d'))) {
            $estado = 'vencido';
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE credenciales SET
                cargo=?, correo=?, celular=?, whatsapp=?, centro_poblado=?, comunidad_nativa=?, distrito=?,
                provincia=?, region=?, direccion=?, foto=?, fecha_emision=?, fecha_vencimiento=?,
                estado=?, actualizado_en=NOW()
                WHERE id=?")->execute([
                $cargo, $correo, $celular, $whatsapp, $centro_poblado, $comunidad_nativa, $distrito,
                $provincia, $region, $direccion, $foto_final, $fecha_emision, $fecha_vencimiento,
                $estado, $id
            ]);
            log_activity($pdo, 'Actualizó credencial: '.$nombres.' ('.$dni.')', 'credenciales_modulo');
            if ($old_qr_token !== '') {
                $qr_dir = dirname(__DIR__) . '/uploads/credenciales/qr';
                generar_qr_archivo(credencial_verify_url($old_qr_token), $qr_dir, 'qr_' . $old_qr_token, 480, '1E3A8A');
            }
            json_resp(['ok'=>true, 'msg'=>'Credencial actualizada.', 'id'=>$id]);
        } else {
            $codigo   = generar_codigo_credencial($pdo);
            $qr_token = bin2hex(random_bytes(16));

            $pdo->prepare("INSERT INTO credenciales
                (codigo, qr_token, persona_tipo, persona_id, nombres_completos, dni, cargo, correo,
                 celular, whatsapp, centro_poblado, comunidad_nativa, distrito, provincia, region, direccion, foto,
                 fecha_emision, fecha_vencimiento, estado, creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $codigo, $qr_token, $persona_tipo, $persona_id, $nombres, $dni, $cargo, $correo,
                $celular, $whatsapp, $centro_poblado, $comunidad_nativa, $distrito, $provincia, $region, $direccion, $foto_final,
                $fecha_emision, $fecha_vencimiento, $estado, (int)($_SESSION['admin_id'] ?? 0)
            ]);
            $nuevo_id = (int)$pdo->lastInsertId();

            // Generar y guardar el QR (apunta a la pagina publica de verificacion)
            $verify_url = credencial_verify_url($qr_token);
            $qr_dir     = dirname(__DIR__) . '/uploads/credenciales/qr';
            generar_qr_archivo($verify_url, $qr_dir, 'qr_' . $qr_token, 480, '1E3A8A');

            log_activity($pdo, 'Generó credencial: '.$nombres.' ('.$dni.') código '.$codigo, 'credenciales_modulo');
            json_resp(['ok'=>true, 'msg'=>'Credencial generada: '.$codigo, 'id'=>$nuevo_id]);
        }
    }

    // ── Eliminar ──────────────────────────────────────────────
    if ($action === 'delete_credencial') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
        $r = $pdo->prepare("SELECT foto, qr_token, nombres_completos FROM credenciales WHERE id=?");
        $r->execute([$id]);
        $row = $r->fetch();
        if (!$row) json_resp(['ok'=>false,'msg'=>'No encontrada.']);

        if ($row['foto']) {
            $p = dirname(__DIR__) . '/' . $row['foto'];
            if (is_file($p)) @unlink($p);
        }
        $qr_path = dirname(__DIR__) . '/uploads/credenciales/qr/qr_' . $row['qr_token'] . '.png';
        if (is_file($qr_path)) @unlink($qr_path);

        $pdo->prepare("DELETE FROM credenciales WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Eliminó credencial de: '.$row['nombres_completos'], 'credenciales_modulo');
        json_resp(['ok'=>true, 'msg'=>'Credencial eliminada.']);
    }

    // ── Cambiar estado (activo / anulado) ─────────────────────
    if ($action === 'cambiar_estado') {
        $id     = (int)($_POST['id'] ?? 0);
        $nuevo  = $_POST['estado'] ?? '';
        if ($id <= 0) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
        if (!in_array($nuevo, ['activo','anulado'], true)) json_resp(['ok'=>false,'msg'=>'Estado inválido.']);

        // Si se reactiva y ya venció, vuelve a quedar como vencido automaticamente
        $r = $pdo->prepare("SELECT fecha_vencimiento FROM credenciales WHERE id=?");
        $r->execute([$id]);
        $venc = $r->fetchColumn();
        if ($nuevo === 'activo' && $venc && strtotime($venc) < strtotime(date('Y-m-d'))) {
            $nuevo = 'vencido';
        }

        $pdo->prepare("UPDATE credenciales SET estado=?, actualizado_en=NOW() WHERE id=?")->execute([$nuevo, $id]);
        json_resp(['ok'=>true, 'estado'=>$nuevo]);
    }

    // ── Tab CC.PP/CC.NN: crear / editar centro poblado o comunidad nativa ─
    if ($action === 'jur_crear' || $action === 'jur_editar') {
        $tablaJ = ($_POST['tabla'] ?? '') === 'ccnn' ? 'comunidades_nativas' : 'centros_poblados';
        $labelJ = $tablaJ === 'comunidades_nativas' ? 'Comunidad Nativa' : 'Centro Poblado';
        $nombreJ    = trim($_POST['nombre']    ?? '');
        $distritoJ  = trim($_POST['distrito']  ?? '') ?: null;
        $provinciaJ = trim($_POST['provincia'] ?? '') ?: null;
        $regionJ    = trim($_POST['region']    ?? '') ?: null;

        if ($nombreJ   === '') json_resp(['ok'=>false,'msg'=>"El nombre de $labelJ es obligatorio."]);
        if (!$regionJ)          json_resp(['ok'=>false,'msg'=>'Debes seleccionar una Región.']);
        if (!$provinciaJ)       json_resp(['ok'=>false,'msg'=>'Debes seleccionar una Provincia.']);
        if (!$distritoJ)        json_resp(['ok'=>false,'msg'=>'Debes seleccionar un Distrito.']);

        if ($action === 'jur_crear') {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM $tablaJ WHERE LOWER(nombre) = LOWER(?)");
            $chk->execute([$nombreJ]);
            if ((int)$chk->fetchColumn() > 0) {
                json_resp(['ok'=>false,'msg'=>"Ya existe un $labelJ con el nombre \"$nombreJ\" en el catálogo."]);
            }
            try {
                $pdo->prepare("INSERT INTO $tablaJ (nombre,distrito,provincia,region) VALUES (?,?,?,?)")
                    ->execute([$nombreJ, $distritoJ, $provinciaJ, $regionJ]);
                json_resp(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'nombre'=>$nombreJ,'distrito'=>$distritoJ,'provincia'=>$provinciaJ,'region'=>$regionJ]);
            } catch (PDOException $e) {
                json_resp(['ok'=>false,'msg'=>'Error al guardar: '.$e->getMessage()]);
            }
        } else {
            $idJ = (int)($_POST['id'] ?? 0);
            if (!$idJ) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM $tablaJ WHERE LOWER(nombre) = LOWER(?) AND id != ?");
            $chk->execute([$nombreJ, $idJ]);
            if ((int)$chk->fetchColumn() > 0) {
                json_resp(['ok'=>false,'msg'=>"Ya existe un $labelJ con el nombre \"$nombreJ\" en el catálogo."]);
            }
            try {
                $pdo->prepare("UPDATE $tablaJ SET nombre=?,distrito=?,provincia=?,region=? WHERE id=?")
                    ->execute([$nombreJ, $distritoJ, $provinciaJ, $regionJ, $idJ]);
                json_resp(['ok'=>true,'id'=>$idJ,'nombre'=>$nombreJ,'distrito'=>$distritoJ,'provincia'=>$provinciaJ,'region'=>$regionJ]);
            } catch (PDOException $e) {
                json_resp(['ok'=>false,'msg'=>'Error al actualizar: '.$e->getMessage()]);
            }
        }
    }

    // ── Tab CC.PP/CC.NN: eliminar centro poblado o comunidad nativa ───────
    if ($action === 'jur_eliminar') {
        $idJ    = (int)($_POST['id'] ?? 0);
        $tablaJ = ($_POST['tabla'] ?? '') === 'ccnn' ? 'comunidades_nativas' : 'centros_poblados';
        if (!$idJ) json_resp(['ok'=>false,'msg'=>'ID inválido.']);
        $pdo->prepare("DELETE FROM $tablaJ WHERE id=?")->execute([$idJ]);
        json_resp(['ok'=>true]);
    }

    // ── Enviar credencial por correo ──────────────────────────
    if ($action === 'enviar_credencial_email') {
        require_once dirname(__DIR__) . '/includes/config/mail.php';
        require_once dirname(__DIR__) . '/includes/smtp-mailer.php';
        $cred_id = (int)($_POST['id'] ?? 0);
        if (!$cred_id) json_resp(['ok' => false, 'msg' => 'ID de credencial inválido.']);
        $row = $pdo->prepare("SELECT nombres_completos, correo, qr_token, codigo FROM credenciales WHERE id=?");
        $row->execute([$cred_id]);
        $cred = $row->fetch(PDO::FETCH_ASSOC);
        if (!$cred) json_resp(['ok' => false, 'msg' => 'Credencial no encontrada.']);
        if (!$cred['correo']) json_resp(['ok' => false, 'msg' => 'El titular no tiene correo registrado.']);
        $pdf_url = trim((string)($_POST['pdf_url'] ?? ''));
        $pdf_base = rtrim(BASE_URL, '/') . '/uploads/credenciales/pdf/';
        if ($pdf_url === '' || strncmp($pdf_url, $pdf_base, strlen($pdf_base)) !== 0) {
            json_resp(['ok' => false, 'msg' => 'No se pudo obtener el enlace PDF de la credencial.']);
        }

        $verify_url = credencial_verify_url((string)$cred['qr_token']);
        $asunto = 'Tu credencial oficial - ' . $cred['codigo'];
        $cuerpo =
            "Estimado/a {$cred['nombres_completos']},\n\n" .
            "Le informamos que su Credencial Oficial ha sido generada exitosamente.\n\n" .
            "Codigo: {$cred['codigo']}\n\n" .
            "Descargue su credencial en PDF aqui:\n" .
            $pdf_url . "\n\n" .
            "Tambien puede verificar su credencial aqui:\n" .
            $verify_url . "\n\n" .
            "Alianza Para el Progreso - Satipo";
        $err = null;
        $ok = smtp_send_mail($cred['correo'], $cred['nombres_completos'], $asunto, $cuerpo, $err);
        if ($ok) {
            json_resp(['ok' => true, 'msg' => 'Correo enviado a ' . $cred['correo']]);
        } else {
            json_resp(['ok' => false, 'msg' => $err ?: 'No se pudo enviar el correo. Verifica la configuración SMTP.']);
        }
    }

    // ── Tab Configurar PDF: guardar configuración ─────────────────
    if ($action === 'guardar_cfg_pdf') {
        $cfg = cfg_pdf_get($pdo);

        $cfg['mensaje_partido']   = trim((string)($_POST['mensaje_partido'] ?? $cfg['mensaje_partido']));
        $cfg['titulo_credencial'] = trim((string)($_POST['titulo_credencial'] ?? $cfg['titulo_credencial'])) ?: 'CREDENCIAL';
        $font = trim((string)($_POST['titulo_font_family'] ?? $cfg['titulo_font_family']));
        $cfg['titulo_font_family'] = array_key_exists($font, titulo_font_catalog()) ? $font : 'DejaVu Sans';
        $cfg['titulo_font_size'] = max(16, min(42, (float)($_POST['titulo_font_size'] ?? $cfg['titulo_font_size'])));
        $weight = (int)($_POST['titulo_font_weight'] ?? $cfg['titulo_font_weight']);
        $cfg['titulo_font_weight'] = in_array($weight, [600, 700, 800, 900], true) ? $weight : 900;
        $cfg['titulo_italic'] = !empty($_POST['titulo_italic']) ? 1 : 0;
        $textColor = strtoupper(trim((string)($_POST['titulo_text_color'] ?? $cfg['titulo_text_color'])));
        $bgColor = strtoupper(trim((string)($_POST['titulo_bg_color'] ?? $cfg['titulo_bg_color'])));
        $cfg['titulo_text_color'] = preg_match('/^#[0-9A-F]{6}$/', $textColor) ? $textColor : '#FFFFFF';
        $cfg['titulo_bg_color'] = preg_match('/^#[0-9A-F]{6}$/', $bgColor) ? $bgColor : '#1E3A8A';
        $cfg['titulo_banner_height'] = max(10, min(20, (float)($_POST['titulo_banner_height'] ?? $cfg['titulo_banner_height'])));
        $cfg['titulo_radius'] = max(0, min(8, (float)($_POST['titulo_radius'] ?? $cfg['titulo_radius'])));
        $cfg['titulo_letter_spacing'] = max(0, min(6, (float)($_POST['titulo_letter_spacing'] ?? $cfg['titulo_letter_spacing'])));
        $sufijo = strtoupper(trim((string)($_POST['codigo_sufijo'] ?? $cfg['codigo_sufijo'])));
        $cfg['codigo_sufijo']     = $sufijo !== '' ? preg_replace('/[^A-Z0-9]/', '', $sufijo) : 'IVSIS';
        $cfg['texto1']            = trim((string)($_POST['texto1'] ?? $cfg['texto1']));
        $cfg['nombre_font_size']  = max(10, min(24, (float)($_POST['nombre_font_size'] ?? $cfg['nombre_font_size'] ?? 13)));
        $cfg['texto3']            = sanitize_credencial_rich_html((string)($_POST['texto3'] ?? $cfg['texto3']));
        $cfg['texto4_ciudad']     = trim((string)($_POST['texto4_ciudad'] ?? $cfg['texto4_ciudad']));

        $tamanos = $_POST['tamanos'] ?? [];
        $tamanos = is_array($tamanos) ? array_values(array_intersect($tamanos, ['a4', 'carnet'])) : [];
        $cfg['tamanos'] = !empty($tamanos) ? $tamanos : ['a4'];

        $num_firmas = (int)($_POST['num_firmas'] ?? $cfg['num_firmas']);
        $cfg['num_firmas'] = max(1, min(3, $num_firmas));

        $firmas_post = $_POST['firmas'] ?? [];
        for ($i = 0; $i < 3; $i++) {
            $cfg['firmas'][$i]['nombre'] = trim((string)($firmas_post[$i]['nombre'] ?? $cfg['firmas'][$i]['nombre']));
            $cfg['firmas'][$i]['cargo']  = trim((string)($firmas_post[$i]['cargo']  ?? $cfg['firmas'][$i]['cargo']));
        }

        try {
            // Plantilla de fondo A4: conservar original para evitar pérdida en el membrete.
            if (!empty($_FILES['plantilla_a4']['name'])) {
                $nueva = upload_imagen_config($_FILES['plantilla_a4'], ['svg', 'png', 'jpg', 'jpeg'], $cfg['plantilla_a4'] ?: null, false, true);
                if ($nueva) $cfg['plantilla_a4'] = $nueva;
            }
            if (!empty($_FILES['plantilla_reverso_a4']['name'])) {
                $nueva = upload_imagen_config($_FILES['plantilla_reverso_a4'], ['svg', 'png', 'jpg', 'jpeg'], $cfg['plantilla_reverso_a4'] ?: null, true, true);
                if ($nueva) $cfg['plantilla_reverso_a4'] = $nueva;
            }
            // Firmas (hasta 3 imágenes)
            for ($i = 0; $i < 3; $i++) {
                $campo = 'firma_imagen_' . $i;
                if (!empty($_FILES[$campo]['name'])) {
                    $nueva = upload_imagen_config($_FILES[$campo], ['png', 'jpg', 'jpeg', 'svg', 'webp'], $cfg['firmas'][$i]['imagen'] ?: null);
                    if ($nueva) $cfg['firmas'][$i]['imagen'] = $nueva;
                }
            }
        } catch (RuntimeException $e) {
            json_resp(['ok' => false, 'msg' => $e->getMessage()]);
        }

        cfg_pdf_save($pdo, $cfg);
        json_resp(['ok' => true, 'msg' => 'Configuración guardada.', 'cfg' => $cfg]);
    }
}

// ── Cargar datos para el listado ──────────────────────────────
$credenciales = $pdo->query("SELECT * FROM credenciales ORDER BY id DESC")->fetchAll();
$total    = count($credenciales);
$activas  = count(array_filter($credenciales, fn($c) => $c['estado'] === 'activo'));
$vencidas = count(array_filter($credenciales, fn($c) => $c['estado'] === 'vencido'));
$anuladas = count(array_filter($credenciales, fn($c) => $c['estado'] === 'anulado'));

include __DIR__ . '/layout.php';
?>

<style>
<?php foreach (titulo_font_catalog() as $fontName => $fontFile): ?>
<?php if ($fontFile !== ''): ?>
@font-face {
  font-family: '<?= htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8') ?>';
  src: url('<?= BASE_URL ?>/assets/fonts/tittle/<?= rawurlencode($fontFile) ?>');
  font-weight: 400 900;
  font-style: normal;
}
<?php endif; ?>
<?php endforeach; ?>
.cred-estado-activo  { background:#D1FAE5; color:#065F46; }
.cred-estado-vencido { background:#FEF3C7; color:#92400E; }
.cred-estado-anulado { background:#FEE2E2; color:#991B1B; }
.cred-tipo-militante    { background:#EDE9FE; color:#6D28D9; }
.cred-tipo-simpatizante { background:#FEF3C7; color:#92400E; }
</style>
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<style>
.cred-quill .ql-toolbar {
  border-color: #E5E7EB !important;
  border-radius: 0.5rem 0.5rem 0 0 !important;
  background: #F9FAFB;
}
.cred-quill .ql-container {
  border-color: #E5E7EB !important;
  border-radius: 0 0 0.5rem 0.5rem !important;
  font-size: 0.875rem;
}
.cred-quill .ql-editor {
  min-height: 140px;
  line-height: 1.7;
}
.cred-quill .ql-editor.ql-blank::before {
  color: #9CA3AF;
  font-style: normal;
}

@media (max-width: 640px) {
  .cred-module-page {
    width: 100%;
    max-width: 100vw;
    overflow-x: hidden;
  }

  .cred-main-tabs {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }

  .cred-main-tabs::-webkit-scrollbar {
    display: none;
  }

  .cred-main-tabs-inner {
    width: max-content;
    min-width: 100%;
  }

  .cred-main-tabs button {
    flex: 0 0 auto;
    min-height: 46px;
    white-space: nowrap;
  }

  .cred-page-header {
    align-items: stretch;
  }

  .cred-page-header > div,
  .cred-page-actions,
  .cred-page-actions button {
    width: 100%;
  }

  .cred-page-actions {
    display: grid;
    grid-template-columns: 1fr;
  }

  .cred-stats-grid {
    gap: 0.75rem;
  }

  .cred-stat-card {
    padding: 0.85rem;
    border-radius: 1rem;
  }

  .cred-stat-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.8rem;
  }

  .cred-filter-row {
    display: grid;
    grid-template-columns: 1fr;
  }

  .cred-filter-search {
    min-width: 0 !important;
    width: 100%;
  }

  .cred-filter-row select,
  .cred-filter-row button {
    width: 100%;
  }

  .cred-list-shell {
    max-width: 100%;
  }

  .cred-table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .cred-table {
    min-width: 920px;
  }

  .cred-config-page {
    padding-bottom: 6rem;
  }

  .cred-config-toolbar {
    display: grid;
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .cred-config-tabs {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }

  .cred-config-tabs::-webkit-scrollbar {
    display: none;
  }

  .cred-config-tabs-inner {
    width: max-content;
    min-width: 100%;
  }

  .cred-config-tabs button {
    flex: 0 0 auto;
    min-height: 46px;
    white-space: nowrap;
  }

  .cred-config-actions {
    display: grid;
    grid-template-columns: 1fr;
    width: 100%;
  }

  .cred-config-actions > span {
    order: -1;
    width: 100%;
    text-align: center;
  }

  .cred-config-actions button {
    width: 100%;
    justify-content: center;
    min-height: 46px;
  }

  .cred-config-panel {
    padding: 1.15rem;
    border-radius: 1.1rem;
  }

  .cred-upload-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
  }

  .cred-upload-preview {
    width: min(100%, 13rem);
    height: 16rem;
    margin-inline: auto;
  }

  .cred-upload-card input[type="file"] {
    font-size: 0.8rem;
  }

  .cred-upload-card input[type="file"]::file-selector-button {
    display: block;
    width: 100%;
    margin: 0 0 0.55rem 0;
  }

  .cred-config-page textarea,
  .cred-config-page input,
  .cred-config-page select {
    max-width: 100%;
  }

  .cred-config-page .min-w-\[260px\] {
    min-width: 0 !important;
  }

  .cred-config-page .w-40,
  .cred-config-page .w-24 {
    width: 100% !important;
  }

  .cred-modal-overlay {
    align-items: stretch;
    padding: 0.75rem;
  }

  .cred-form-modal {
    max-height: calc(100dvh - 1.5rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 1.25rem;
  }

  .cred-form-modal form {
    min-height: 0;
    display: flex;
    flex-direction: column;
    flex: 1;
  }

  .cred-form-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 1rem;
    padding-bottom: 1.5rem;
    -webkit-overflow-scrolling: touch;
  }

  .cred-form-header {
    padding: 1rem 1.25rem;
    flex-shrink: 0;
  }

  .cred-form-footer {
    position: sticky;
    bottom: 0;
    z-index: 20;
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.65rem;
    padding: 0.85rem 1rem calc(0.85rem + env(safe-area-inset-bottom));
    box-shadow: 0 -18px 30px rgba(15, 23, 42, 0.08);
    flex-shrink: 0;
  }

  .cred-form-footer button {
    width: 100%;
    justify-content: center;
    min-height: 46px;
  }

  .cred-photo-drop {
    display: grid;
    grid-template-columns: 5rem 1fr;
    align-items: center;
    gap: 0.85rem;
    padding: 0.9rem;
  }

  .cred-photo-drop input[type="file"] {
    max-width: 100%;
    font-size: 0.78rem;
  }

  .cred-photo-drop input[type="file"]::file-selector-button {
    display: block;
    width: 100%;
    margin: 0 0 0.5rem 0;
  }

  .cred-pdf-modal {
    align-items: stretch;
    padding: 0.75rem;
  }

  .cred-pdf-shell {
    height: calc(100dvh - 1.5rem);
    max-height: calc(100dvh - 1.5rem);
    border-radius: 1.15rem;
  }

  .cred-pdf-header {
    padding: 1rem;
  }

  .cred-pdf-title {
    width: 100%;
  }

  .cred-pdf-actions {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
  }

  .cred-pdf-actions > * {
    width: 100%;
    min-height: 42px;
    justify-content: center;
  }

  .cred-pdf-actions .cred-pdf-action-close {
    width: 100%;
  }

  .cred-pdf-subbar {
    align-items: stretch;
  }

  .cred-pdf-subbar > div {
    width: 100%;
  }

  .cred-pdf-subbar button {
    flex: 1 1 0;
    justify-content: center;
  }

  .cred-pdf-viewer {
    padding: 0.75rem;
    padding-bottom: calc(5.75rem + env(safe-area-inset-bottom));
  }
}
</style>

<div class="cred-module-page"
     x-data="{ mainTab: (localStorage.getItem('cred_main_tab') || 'credenciales') }"
     x-init="$watch('mainTab', v => localStorage.setItem('cred_main_tab', v))">

  <!-- ── Pestañas principales ──────────────────────────────── -->
  <div class="cred-main-tabs mb-5">
  <div class="cred-main-tabs-inner flex gap-1 bg-gray-100 p-1 rounded-2xl w-fit">
    <button type="button" @click="mainTab='credenciales'"
            :class="mainTab==='credenciales' ? 'bg-white text-[#1E3A8A] shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
            class="flex items-center gap-2 px-4 sm:px-5 py-2 rounded-xl text-sm transition-all">
      <i class="ti ti-id-badge-2"></i>
      Credenciales
    </button>
    <button type="button" @click="mainTab='jurisdiccion'"
            :class="mainTab==='jurisdiccion' ? 'bg-white text-emerald-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
            class="flex items-center gap-2 px-4 sm:px-5 py-2 rounded-xl text-sm transition-all">
      <i class="ti ti-map-pins"></i>
      CC.PP y CC.NN
    </button>
    <button type="button" @click="mainTab='configurar_pdf'"
            :class="mainTab==='configurar_pdf' ? 'bg-white text-amber-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
            class="flex items-center gap-2 px-4 sm:px-5 py-2 rounded-xl text-sm transition-all">
      <i class="ti ti-file-certificate"></i>
      Configurar PDF Credencial
    </button>
  </div>
  </div>

  <div x-show="mainTab==='credenciales'">
  <div class="space-y-5"
     x-data="credencialApp()"
     x-init="init()"
     @keydown.escape.window="closeModal(); closePdf(); closeListadoPdf()">

  <!-- ── Header ────────────────────────────────────────────── -->
  <div class="cred-page-header flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-xl font-black text-gray-800">Credenciales</h2>
      <p class="text-xs text-gray-400 mt-0.5">Genera y administra carnets de militantes y simpatizantes con código QR</p>
    </div>
    <div class="cred-page-actions flex items-center gap-2 flex-wrap">
      <button @click="openListadoPdf()"
              :disabled="filtrados.length === 0"
              class="inline-flex items-center gap-2 bg-white hover:bg-blue-50 disabled:opacity-50 disabled:cursor-not-allowed text-[#1E3A8A]
                     border border-blue-200 text-sm font-bold px-4 py-2.5 rounded-xl shadow-sm transition-all">
        <i class="ti ti-file-type-pdf text-base"></i>
        Imprimir PDF
      </button>
      <button @click="openModal()"
              class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 text-white
                     text-sm font-bold px-4 py-2.5 rounded-xl shadow transition-all">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Generar Credencial
      </button>
    </div>
  </div>

  <!-- ── Stats ─────────────────────────────────────────────── -->
  <div class="cred-stats-grid grid grid-cols-2 sm:grid-cols-4 gap-3">
    <?php foreach([
      ['Total',     $total,    '#1E3A8A','M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
      ['Activas',   $activas,  '#059669','M5 13l4 4L19 7'],
      ['Vencidas',  $vencidas, '#D97706','M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
      ['Anuladas',  $anuladas, '#DC2626','M6 18L18 6M6 6l12 12'],
    ] as [$label,$val,$color,$path]): ?>
    <div class="cred-stat-card bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
      <div class="cred-stat-icon w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
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

  <!-- ── Filtros ───────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
    <div class="cred-filter-row flex flex-wrap gap-3 items-center">
      <div class="cred-filter-search relative flex-1 min-w-[220px]">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
        </svg>
        <input x-model="filtro.busqueda" type="text" placeholder="Buscar por nombre, DNI o código..."
               class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-xl
                      focus:ring-2 focus:ring-[#1E3A8A] focus:border-transparent outline-none">
      </div>
      <select x-model="filtro.tipo"
              class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-[#1E3A8A]">
        <option value="">Todos</option>
        <option value="simpatizante">Simpatizantes</option>
      </select>
      <select x-model="filtro.estado"
              class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-[#1E3A8A]">
        <option value="">Todos los estados</option>
        <option value="activo">Activas</option>
        <option value="vencido">Vencidas</option>
        <option value="anulado">Anuladas</option>
      </select>
      <button @click="filtro={busqueda:'',tipo:'',estado:''}"
              class="text-xs text-gray-400 hover:text-gray-600 px-2">Limpiar</button>
    </div>
  </div>

  <!-- ── Tabla ─────────────────────────────────────────────── -->
  <div class="cred-list-shell bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-50 flex items-center justify-between">
      <span class="text-xs text-gray-400">
        Mostrando <span class="font-bold text-gray-600" x-text="filtrados.length"></span>
        de <span class="font-bold text-gray-600" x-text="credenciales.length"></span> credenciales
      </span>
    </div>

    <div class="cred-table-scroll overflow-x-auto">
      <table class="cred-table w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Código</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Titular</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Distrito</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">DNI</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Cargo</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Origen</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Vigencia</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wide">Estado</th>
            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase tracking-wide">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">

          <template x-if="filtrados.length === 0">
            <tr>
              <td colspan="9" class="px-4 py-14 text-center">
                <div class="flex flex-col items-center gap-2">
                  <svg class="w-10 h-10 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                  <p class="text-gray-400 font-semibold text-sm">No hay credenciales que coincidan</p>
                  <p class="text-gray-300 text-xs">Ajusta los filtros o genera una nueva credencial</p>
                </div>
              </td>
            </tr>
          </template>

          <template x-for="c in filtrados" :key="c.id">
            <tr class="hover:bg-blue-50/30 transition-colors">
              <td class="px-4 py-3 font-mono text-xs font-bold text-[#1E3A8A]" x-text="c.codigo"></td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-xl overflow-hidden flex-shrink-0 bg-[#1E3A8A] flex items-center justify-center">
                    <template x-if="c.foto">
                      <img :src="'<?= BASE_URL ?>/' + c.foto" :alt="c.nombres_completos" class="w-9 h-9 object-cover">
                    </template>
                    <template x-if="!c.foto">
                      <span class="text-white font-black text-sm leading-none" x-text="(c.nombres_completos[0]||'').toUpperCase()"></span>
                    </template>
                  </div>
                  <div>
                    <div class="font-bold text-gray-800 text-sm leading-tight" x-text="c.nombres_completos"></div>
                    <div class="text-xs text-gray-400" x-text="c.provincia || 'Sin provincia'"></div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs font-bold text-gray-600" x-text="c.distrito || '—'"></td>
              <td class="px-4 py-3 font-mono text-xs text-gray-600" x-text="c.dni"></td>
              <td class="px-4 py-3 text-xs text-gray-600" x-text="c.cargo || '—'"></td>
              <td class="px-4 py-3">
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold"
                      :class="c.persona_tipo === 'militante' ? 'cred-tipo-militante' : 'cred-tipo-simpatizante'"
                      x-text="c.persona_tipo === 'militante' ? 'Militante' : 'Simpatizante'"></span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-500">                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        
                <div x-text="'Emisión: ' + formatoFecha(c.fecha_emision)"></div>
                <div x-text="'Vence: ' + formatoFecha(c.fecha_vencimiento)"></div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold"
                      :class="'cred-estado-' + c.estado"
                      x-text="c.estado === 'activo' ? 'Activa' : (c.estado === 'vencido' ? 'Vencida' : 'Anulada')"></span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1.5">
                  <button @click="verQr(c)" title="Ver código QR"
                          class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-[#1E3A8A] hover:bg-blue-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4h2m4 0h2v-4h-2m-6 0v4m0-11v6m-6-6h6V4H4v6zm10-6h6v6h-6V4zM4 14h6v6H4v-6z"/>
                    </svg>
                  </button>
                  <div x-data="{openPdfDrop:false}" class="relative">
                    <button @click="openPdfDrop=!openPdfDrop" title="Imprimir PDF"
                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                      </svg>
                    </button>
                    <div x-show="openPdfDrop" x-cloak @click.outside="openPdfDrop=false"
                         class="absolute right-0 top-9 z-50 bg-white rounded-xl shadow-lg border border-gray-100 py-1 w-44">
                      <button @click="openPdf(c,'a4'); openPdfDrop=false"
                              class="w-full text-left px-4 py-2 text-xs font-bold text-gray-700 hover:bg-blue-50 hover:text-[#1E3A8A] flex items-center gap-2 transition-colors">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        Credencial A4
                      </button>
                      <button @click="openPdf(c,'carnet'); openPdfDrop=false"
                              class="w-full text-left px-4 py-2 text-xs font-bold text-gray-700 hover:bg-blue-50 hover:text-[#1E3A8A] flex items-center gap-2 transition-colors">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m2 0h6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/>
                        </svg>
                        Carnet 59×95 mm
                      </button>
                    </div>
                  </div>
                  <button @click="openModal(c)" title="Editar"
                          class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-[#1E3A8A] hover:bg-blue-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <button @click="anularToggle(c)" :title="c.estado === 'anulado' ? 'Reactivar' : 'Anular'"
                          class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-amber-600 hover:bg-amber-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                  </button>
                  <button @click="confirmarEliminar(c)" title="Eliminar"
                          class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/>
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

  <!-- ════════════════════════════════════════════════════════
       MODAL: Generar / Editar credencial
  ════════════════════════════════════════════════════════ -->
  <template x-if="modal.open">
    <div class="cred-modal-overlay fixed inset-0 z-[10020] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="closeModal()">
      <div class="cred-form-modal bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <form id="form-credencial" @submit.prevent="guardar()" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_credencial">
          <input type="hidden" name="id" :value="modal.id">
          <input type="hidden" name="persona_tipo" :value="modal.persona_tipo">
          <input type="hidden" name="persona_id" :value="modal.persona_id">

          <div class="cred-form-header px-6 py-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
            <h3 class="font-black text-gray-800 text-lg" x-text="modal.id ? 'Editar credencial' : 'Generar nueva credencial'"></h3>
            <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <div class="cred-form-body p-6 space-y-5">
            <template x-if="modal.error">
              <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-bold rounded-xl px-4 py-3" x-text="modal.error"></div>
            </template>

            <!-- ── Datos editables ────────────────────────────── -->
            <div class="space-y-5">

              <!-- ═══ Sección: Datos personales ═══ -->
              <div class="relative border border-gray-200 rounded-2xl p-4 pt-6">
                <span class="absolute -top-2.5 left-4 bg-white px-2 text-[11px] font-black text-gray-400 uppercase tracking-wide">Datos personales</span>
                <div class="space-y-4">

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="relative">
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">DNI</label>
                      <input type="text" name="dni" x-model="modal.dni" maxlength="8" inputmode="numeric" required
                             :readonly="!!modal.id"
                             @input="modal.dni = modal.dni.replace(/\D/g, '').slice(0, 8); buscarPorDni()"
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] font-mono"
                             :class="modal.id ? 'bg-gray-50' : ''">
                      <svg x-show="modal.consultandoDni" class="w-4 h-4 animate-spin text-gray-400 absolute right-3 top-9" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                      </svg>
                      <p x-show="modal.dniError" x-text="modal.dniError" class="text-xs font-bold text-red-500 mt-1.5"></p>
                    </div>
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Nombres y apellidos</label>
                      <input type="text" name="nombres_completos" x-model="modal.nombres_completos" required readonly
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 outline-none">
                    </div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Origen</label>
                      <template x-if="modal.persona_id > 0">
                        <input type="text" readonly
                               :value="modal.persona_tipo === 'militante' ? 'Militante' : 'Simpatizante'"
                               class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 outline-none">
                      </template>
                      <template x-if="modal.persona_id === 0">
                        <select x-model="modal.persona_tipo"
                                class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] bg-white">
                          <option value="">Selecciona...</option>
                          <option value="simpatizante">Simpatizante</option>
                        </select>
                      </template>
                      <p x-show="modal.persona_id === 0 && modal.dni.length === 8 && !modal.id"
                         class="text-xs text-gray-400 mt-1.5">DNI nuevo: el registro se creará automáticamente al guardar.</p>
                    </div>
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Correo electrónico</label>
                      <input type="email" name="correo" x-model="modal.correo" disabled
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none bg-gray-50 text-gray-400 cursor-not-allowed">
                    </div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Dirección</label>
                      <input type="text" name="direccion" x-model="modal.direccion" placeholder="Dirección" disabled
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none bg-gray-50 text-gray-400 cursor-not-allowed">
                    </div>
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Cargo</label>
                      <select name="cargo" x-model="modal.cargoSelect"
                              @change="onCargoChange($event)"
                              class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] bg-white">
                        <option value="">Selecciona...</option>
                        <template x-for="c in cargosLista" :key="c">
                          <option :value="c" x-text="c" :selected="modal.cargo === c"></option>
                        </template>
                        <option value="__nuevo__" class="text-[#1E3A8A] font-bold">➕ Añadir Cargo ( + )</option>
                      </select>
                      <input type="hidden" name="cargo" :value="modal.cargo">
                    </div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Celular</label>
                      <input type="text" name="celular" x-model="modal.celular" maxlength="20" inputmode="tel" placeholder="Ej. 987654321"
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                    </div>
                    <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">WhatsApp</label>
                      <input type="text" name="whatsapp" x-model="modal.whatsapp" maxlength="20" inputmode="tel" placeholder="Ej. 987654321"
                             class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                    </div>
                  </div>

                </div>
              </div>

              <!-- ═══ Sección: Jurisdicción ═══ -->
              <div class="relative border border-gray-200 rounded-2xl p-4 pt-6">
                <span class="absolute -top-2.5 left-4 bg-white px-2 text-[11px] font-black text-gray-400 uppercase tracking-wide">Jurisdicción</span>
                <div class="space-y-4">

                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <select name="region" x-model="modal.region"
                            @change="onRegionChange()"
                            :class="modal.region ? 'text-gray-800' : 'text-gray-400'"
                            class="px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] bg-white">
                      <option value="">Selecciona una región…</option>
                      <template x-for="r in GEO_REGIONES" :key="r">
                        <option :value="r" x-text="r"></option>
                      </template>
                    </select>

                    <select name="provincia" x-model="modal.provincia"
                            @change="onProvinciaChange()"
                            :disabled="!modal.region"
                            :class="modal.provincia ? 'text-gray-800' : 'text-gray-400'"
                            class="px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] bg-white disabled:bg-gray-50 disabled:text-gray-400">
                      <option value="">Selecciona una provincia…</option>
                      <template x-for="p in provinciasDeRegion()" :key="p">
                        <option :value="p" x-text="p"></option>
                      </template>
                    </select>

                    <select name="distrito" x-model="modal.distrito"
                            @change="onDistritoChange()"
                            :disabled="!modal.provincia"
                            :class="modal.distrito ? 'text-gray-800' : 'text-gray-400'"
                            class="px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] bg-white disabled:bg-gray-50 disabled:text-gray-400">
                      <option value="">Selecciona un distrito…</option>
                      <template x-for="d in distritosDeProvinicia()" :key="d">
                        <option :value="d" x-text="d"></option>
                      </template>
                    </select>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <!-- ── Searchable Select: Centro Poblado ─────── -->
                    <div class="relative" @click.outside="cerrarCcpp()">

                      <!-- Trigger -->
                      <button type="button" @click="toggleCcpp()"
                              :disabled="!modal.distrito"
                              :class="!modal.distrito ? 'opacity-60 cursor-not-allowed bg-gray-50' : 'bg-white hover:border-gray-300 cursor-pointer'"
                              class="w-full flex items-center justify-between px-3 py-2.5 text-sm border border-gray-200 rounded-xl transition-colors text-left">
                        <span :class="modal.centro_poblado ? 'text-gray-800 font-medium' : (modal.distrito ? 'text-gray-600' : 'text-gray-500 italic text-xs')"
                              x-text="modal.centro_poblado || (modal.distrito ? 'Centro Poblado' : 'Elige primero un distrito')"></span>
                        <div class="flex items-center gap-1 flex-shrink-0">
                          <span x-show="modal.centro_poblado" @click.stop="modal.centro_poblado = ''"
                                class="text-gray-300 hover:text-red-400 font-bold text-lg leading-none -mt-0.5 cursor-pointer px-0.5">×</span>
                          <svg class="w-4 h-4 text-gray-400 transition-transform duration-150"
                               :class="modal.ccppOpen ? 'rotate-180' : ''"
                               fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                          </svg>
                        </div>
                      </button>
                      <input type="hidden" name="centro_poblado" :value="modal.centro_poblado === 'Ninguno' ? '' : modal.centro_poblado">

                      <!-- Dropdown panel -->
                      <div x-show="modal.ccppOpen"
                           x-transition:enter="transition ease-out duration-100"
                           x-transition:enter-start="opacity-0 translate-y-1"
                           x-transition:enter-end="opacity-100 translate-y-0"
                           class="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-2xl overflow-hidden">

                        <!-- Buscador interno -->
                        <div class="p-2 border-b border-gray-100">
                          <div class="relative">
                            <svg class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input type="text" x-model="modal.ccppQ" x-ref="ccppSearch"
                                   placeholder="Busca un CC.PP" autocomplete="off"
                                   @keydown.escape.stop="cerrarCcpp()"
                                   class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                          </div>
                        </div>

                        <!-- Lista -->
                        <div class="max-h-52 overflow-y-auto">
                          <!-- Loading -->
                          <div x-show="modal.ccppLoading" class="px-3 py-3 flex items-center justify-center gap-2 text-xs text-gray-400">
                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Cargando…
                          </div>

                          <!-- "Selecciona un cc.pp. ...." -->
                          <button type="button" @click="seleccionarCcpp('')" x-show="!modal.ccppLoading"
                                  class="w-full text-left px-3 py-2 text-xs text-gray-400 italic hover:bg-gray-50 border-b border-gray-50">
                            Selecciona un cc.pp. ....
                          </button>

                          <!-- "Ninguno" -->
                          <button type="button" @click="seleccionarCcpp('Ninguno')" x-show="!modal.ccppLoading"
                                  :class="modal.centro_poblado === 'Ninguno' ? 'bg-blue-50 text-[#1E3A8A] font-semibold' : 'text-gray-700 hover:bg-blue-50'"
                                  class="w-full text-left px-3 py-2 text-sm border-b border-gray-100 flex items-center justify-between">
                            <span>Ninguno</span>
                            <svg x-show="modal.centro_poblado === 'Ninguno'" class="w-3.5 h-3.5 text-[#1E3A8A]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                          </button>

                          <!-- Opciones filtradas del catálogo -->
                          <template x-for="item in filtradosCcpp()" :key="item">
                            <button type="button" @click="seleccionarCcpp(item)"
                                    :class="modal.centro_poblado === item ? 'bg-blue-50 text-[#1E3A8A] font-semibold' : 'text-gray-700 hover:bg-blue-50'"
                                    class="w-full text-left px-3 py-2 text-sm flex items-center gap-2 border-b border-gray-50 last:border-0 transition-colors">
                              <svg class="w-3 h-3 flex-shrink-0 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                              </svg>
                              <span x-text="item"></span>
                              <svg x-show="modal.centro_poblado === item" class="w-3.5 h-3.5 ml-auto text-[#1E3A8A] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                              </svg>
                            </button>
                          </template>

                          <!-- Sin coincidencias -->
                          <div x-show="!modal.ccppLoading && filtradosCcpp().length === 0 && modal.ccppQ.trim() !== ''"
                               class="px-3 py-3 text-xs text-gray-400 italic text-center">
                            Sin coincidencias. Usa "Añadir" para crear uno nuevo.
                          </div>
                        </div>

                        <!-- Footer: Añadir CC.PP. -->
                        <div class="border-t border-blue-200">
                          <button type="button" @click="abrirModalNuevoCcpp()"
                                  class="w-full flex items-center gap-2.5 px-4 py-3 text-sm font-black text-white bg-[#1E3A8A] hover:bg-blue-900 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            Añadir CC.PP. (+) .....
                          </button>
                        </div>
                      </div>
                    </div>

                    <!-- ── Searchable Select: Comunidad Nativa ─────── -->
                    <div class="relative" @click.outside="cerrarCcnn()">

                      <!-- Trigger -->
                      <button type="button" @click="toggleCcnn()"
                              :disabled="!modal.distrito"
                              :class="!modal.distrito ? 'opacity-60 cursor-not-allowed bg-gray-50' : 'bg-white hover:border-gray-300 cursor-pointer'"
                              class="w-full flex items-center justify-between px-3 py-2.5 text-sm border border-gray-200 rounded-xl transition-colors text-left">
                        <span :class="modal.comunidad_nativa ? 'text-gray-800 font-medium' : (modal.distrito ? 'text-gray-600' : 'text-gray-500 italic text-xs')"
                              x-text="modal.comunidad_nativa || (modal.distrito ? 'Comunidad Nativa (CC.NN.)' : 'Elige primero un distrito')"></span>
                        <div class="flex items-center gap-1 flex-shrink-0">
                          <span x-show="modal.comunidad_nativa" @click.stop="modal.comunidad_nativa = ''"
                                class="text-gray-300 hover:text-red-400 font-bold text-lg leading-none -mt-0.5 cursor-pointer px-0.5">×</span>
                          <svg class="w-4 h-4 text-gray-400 transition-transform duration-150"
                               :class="modal.ccnnOpen ? 'rotate-180' : ''"
                               fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                          </svg>
                        </div>
                      </button>
                      <input type="hidden" name="comunidad_nativa" :value="modal.comunidad_nativa === 'Ninguno' ? '' : modal.comunidad_nativa">

                      <!-- Dropdown panel -->
                      <div x-show="modal.ccnnOpen"
                           x-transition:enter="transition ease-out duration-100"
                           x-transition:enter-start="opacity-0 translate-y-1"
                           x-transition:enter-end="opacity-100 translate-y-0"
                           class="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-2xl overflow-hidden">

                        <!-- Buscador interno -->
                        <div class="p-2 border-b border-gray-100">
                          <div class="relative">
                            <svg class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input type="text" x-model="modal.ccnnQ" x-ref="ccnnSearch"
                                   placeholder="Busca una CC.NN." autocomplete="off"
                                   @keydown.escape.stop="cerrarCcnn()"
                                   class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                          </div>
                        </div>

                        <!-- Lista -->
                        <div class="max-h-52 overflow-y-auto">
                          <!-- Loading -->
                          <div x-show="modal.ccnnLoading" class="px-3 py-3 flex items-center justify-center gap-2 text-xs text-gray-400">
                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Cargando…
                          </div>

                          <!-- "Selecciona una cc.nn. ...." -->
                          <button type="button" @click="seleccionarCcnn('')" x-show="!modal.ccnnLoading"
                                  class="w-full text-left px-3 py-2 text-xs text-gray-400 italic hover:bg-gray-50 border-b border-gray-50">
                            Selecciona una cc.nn. ....
                          </button>

                          <!-- "Ninguno" -->
                          <button type="button" @click="seleccionarCcnn('Ninguno')" x-show="!modal.ccnnLoading"
                                  :class="modal.comunidad_nativa === 'Ninguno' ? 'bg-amber-50 text-amber-700 font-semibold' : 'text-gray-700 hover:bg-amber-50'"
                                  class="w-full text-left px-3 py-2 text-sm border-b border-gray-100 flex items-center justify-between">
                            <span>Ninguno</span>
                            <svg x-show="modal.comunidad_nativa === 'Ninguno'" class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                          </button>

                          <!-- Opciones filtradas del catálogo -->
                          <template x-for="item in filtradosCcnn()" :key="item">
                            <button type="button" @click="seleccionarCcnn(item)"
                                    :class="modal.comunidad_nativa === item ? 'bg-amber-50 text-amber-700 font-semibold' : 'text-gray-700 hover:bg-amber-50'"
                                    class="w-full text-left px-3 py-2 text-sm flex items-center gap-2 border-b border-gray-50 last:border-0 transition-colors">
                              <svg class="w-3 h-3 flex-shrink-0 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                              </svg>
                              <span x-text="item"></span>
                              <svg x-show="modal.comunidad_nativa === item" class="w-3.5 h-3.5 ml-auto text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                              </svg>
                            </button>
                          </template>

                          <!-- Sin coincidencias -->
                          <div x-show="!modal.ccnnLoading && filtradosCcnn().length === 0 && modal.ccnnQ.trim() !== ''"
                               class="px-3 py-3 text-xs text-gray-400 italic text-center">
                            Sin coincidencias. Usa "Añadir" para crear una nueva.
                          </div>
                        </div>

                        <!-- Footer: Añadir CC.NN. -->
                        <div class="border-t border-amber-200">
                          <button type="button" @click="abrirModalNuevoCcnn()"
                                  class="w-full flex items-center gap-2.5 px-4 py-3 text-sm font-black text-white bg-amber-600 hover:bg-amber-700 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            Añadir CC.NN. (+) .....
                          </button>
                        </div>
                      </div>
                    </div>

                  </div>

                </div>
              </div>

              <!-- ═══ Sección: Fecha ═══ -->
              <div class="relative border border-gray-200 rounded-2xl p-4 pt-6">
                <span class="absolute -top-2.5 left-4 bg-white px-2 text-[11px] font-black text-gray-400 uppercase tracking-wide">Fecha</span>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Fecha de emisión</label>
                    <input type="date" name="fecha_emision" x-model="modal.fecha_emision" required
                           class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Fecha de vencimiento</label>
                    <input type="date" name="fecha_vencimiento" x-model="modal.fecha_vencimiento" required
                           class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Estado</label>
                    <select name="estado" x-model="modal.estado"
                            class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                      <option value="activo">Activo</option>
                      <option value="anulado">Anulado</option>
                      <option value="vencido">Vencido</option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- ═══ Fotografía (con arrastrar y soltar) ═══ -->
              <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Fotografía</label>
                <div @dragover.prevent="modal.fotoArrastrando = true"
                     @dragleave.prevent="modal.fotoArrastrando = false"
                     @drop.prevent="modal.fotoArrastrando = false; soltarFoto($event)"
                     :class="modal.fotoArrastrando ? 'border-[#1E3A8A] bg-blue-50/40' : 'border-gray-200'"
                     class="cred-photo-drop flex items-center gap-4 border-2 border-dashed rounded-xl px-4 py-4 transition-colors">
                  <div class="w-20 h-20 rounded-xl overflow-hidden bg-gray-100 border border-gray-200 flex items-center justify-center flex-shrink-0">
                    <template x-if="modal.foto_preview">
                      <img :src="modal.foto_preview" class="w-20 h-20 object-cover">
                    </template>
                    <template x-if="!modal.foto_preview">
                      <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                      </svg>
                    </template>
                  </div>
                  <div>
                    <input type="file" name="foto" x-ref="fotoInput" accept=".jpg,.jpeg,.png,.webp" @change="procesarYMostrarFoto($event.target.files[0])"
                           class="text-xs text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0
                                  file:text-xs file:font-bold file:bg-blue-50 file:text-[#1E3A8A] hover:file:bg-blue-100">
                    <p class="text-xs text-gray-400 mt-1.5">JPG, PNG o WEBP. Máximo <strong>1 MB</strong> — se ajusta automáticamente a formato carnet.</p>
                    <p x-show="modal.fotoError" x-text="modal.fotoError" class="text-xs font-bold text-red-500 mt-1"></p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="cred-form-footer px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 sticky bottom-0 bg-white">
            <button type="button" @click="closeModal()"
                    class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2.5">Cancelar</button>
            <button type="submit" :disabled="modal.saving || (!modal.id && !modal.persona_id && !modal.persona_tipo)"
                    class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 disabled:opacity-50 disabled:cursor-not-allowed
                           text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow transition-all">
              <svg x-show="modal.saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              <span x-text="modal.id ? 'Guardar cambios' : 'Generar credencial'"></span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MODAL: Error de validación PRO
  ════════════════════════════════════════════════════════ -->
  <template x-if="validError.open">
    <div class="fixed inset-0 z-[350] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="validError.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xs p-6 text-center"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 scale-95"
           x-transition:enter-end="opacity-100 scale-100">
        <div class="w-14 h-14 rounded-2xl bg-red-100 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <h3 class="font-black text-gray-800 text-base mb-2" x-text="validError.titulo"></h3>
        <p class="text-sm text-gray-500 mb-6 leading-relaxed" x-text="validError.mensaje"></p>
        <button type="button" @click="validError.open=false"
                class="w-full bg-[#1E3A8A] hover:bg-blue-900 text-white font-bold text-sm px-5 py-2.5 rounded-xl transition-all">
          Entendido
        </button>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MINI-MODAL: Añadir Cargo
  ════════════════════════════════════════════════════════ -->
  <template x-if="cargoModal.open">
    <div class="fixed inset-0 z-[10040] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="cargoModal.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <h3 class="font-black text-gray-800 text-base mb-4">Añadir nuevo cargo</h3>
        <template x-if="cargoModal.error">
          <p class="text-xs font-bold text-red-500 mb-3" x-text="cargoModal.error"></p>
        </template>
        <input type="text" x-model="cargoModal.nombre" placeholder="Nombre del cargo" maxlength="150"
               @keydown.enter.prevent="crearCargo()"
               class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] mb-4">
        <div class="flex justify-end gap-3">
          <button type="button" @click="cargoModal.open=false; modal.cargoSelect=''"
                  class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cancelar</button>
          <button type="button" @click="crearCargo()" :disabled="cargoModal.saving || !cargoModal.nombre.trim()"
                  class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 disabled:opacity-50 text-white text-sm font-bold px-5 py-2 rounded-xl transition-all">
            <svg x-show="cargoModal.saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Guardar cargo
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MINI-MODAL: Añadir Centro Poblado
  ════════════════════════════════════════════════════════ -->
  <template x-if="ccppModal.open">
    <div class="fixed inset-0 z-[10040] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="ccppModal.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-9 h-9 rounded-xl bg-[#1E3A8A]/10 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-[#1E3A8A]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            </svg>
          </div>
          <div>
            <h3 class="font-black text-gray-800 text-sm">Añadir Centro Poblado</h3>
            <p class="text-xs text-gray-400">Se añadirá al catálogo de CC.PP.</p>
          </div>
        </div>
        <template x-if="ccppModal.error">
          <p class="text-xs font-bold text-red-500 mb-3 bg-red-50 rounded-lg px-3 py-2" x-text="ccppModal.error"></p>
        </template>
        <input type="text" x-model="ccppModal.nombre" x-ref="ccppModalInput" placeholder="Nombre del Centro Poblado" maxlength="200"
               @keydown.enter.prevent="guardarNuevoCcpp()"
               @keydown.escape="ccppModal.open=false"
               class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-[#1E3A8A] mb-4">
        <p class="text-xs text-gray-400 mb-4" x-show="modal.distrito">
          Se asociará al distrito: <strong x-text="modal.distrito"></strong>
        </p>
        <div class="flex justify-end gap-3">
          <button type="button" @click="ccppModal.open=false"
                  class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cancelar</button>
          <button type="button" @click="guardarNuevoCcpp()" :disabled="ccppModal.saving || !ccppModal.nombre.trim()"
                  class="inline-flex items-center gap-2 bg-[#1E3A8A] hover:bg-blue-900 disabled:opacity-50 text-white text-sm font-bold px-5 py-2 rounded-xl transition-all">
            <svg x-show="ccppModal.saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg x-show="!ccppModal.saving" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
            </svg>
            Añadir CC.PP.
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MINI-MODAL: Añadir Comunidad Nativa
  ════════════════════════════════════════════════════════ -->
  <template x-if="ccnnModal.open">
    <div class="fixed inset-0 z-[10040] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="ccnnModal.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
          </div>
          <div>
            <h3 class="font-black text-gray-800 text-sm">Añadir Comunidad Nativa</h3>
            <p class="text-xs text-gray-400">Se añadirá al catálogo de CC.NN.</p>
          </div>
        </div>
        <template x-if="ccnnModal.error">
          <p class="text-xs font-bold text-red-500 mb-3 bg-red-50 rounded-lg px-3 py-2" x-text="ccnnModal.error"></p>
        </template>
        <input type="text" x-model="ccnnModal.nombre" x-ref="ccnnModalInput" placeholder="Nombre de la Comunidad Nativa" maxlength="200"
               @keydown.enter.prevent="guardarNuevoCcnn()"
               @keydown.escape="ccnnModal.open=false"
               class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-amber-400 mb-4">
        <p class="text-xs text-gray-400 mb-4" x-show="modal.distrito">
          Se asociará al distrito: <strong x-text="modal.distrito"></strong>
        </p>
        <div class="flex justify-end gap-3">
          <button type="button" @click="ccnnModal.open=false"
                  class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cancelar</button>
          <button type="button" @click="guardarNuevoCcnn()" :disabled="ccnnModal.saving || !ccnnModal.nombre.trim()"
                  class="inline-flex items-center gap-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white text-sm font-bold px-5 py-2 rounded-xl transition-all">
            <svg x-show="ccnnModal.saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg x-show="!ccnnModal.saving" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
            </svg>
            Añadir CC.NN.
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MODAL: Ver código QR
  ════════════════════════════════════════════════════════ -->
  <template x-if="qrModal.open">
    <div class="fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="qrModal.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
        <h3 class="font-black text-gray-800 text-lg mb-1" x-text="qrModal.nombre"></h3>
        <p class="text-xs text-gray-400 mb-4" x-text="'Código: ' + qrModal.codigo"></p>
        <div class="bg-gray-50 rounded-2xl p-5 inline-block">
          <img :src="qrModal.url" alt="Código QR" class="w-52 h-52 mx-auto">
        </div>
        <p class="text-xs text-gray-400 mt-4">Este código enlaza con la página pública de verificación de la credencial.</p>
        <div class="flex items-center justify-center gap-2 mt-4">
          <a :href="qrModal.url" download
             class="text-xs font-bold text-[#1E3A8A] hover:text-blue-900 px-4 py-2 rounded-xl border border-blue-100 hover:bg-blue-50">Descargar PNG</a>
          <button @click="qrModal.open=false"
                  class="text-xs font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cerrar</button>
        </div>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MODAL: Vista previa / impresión PDF
  ════════════════════════════════════════════════════════ -->
  <template x-if="listadoPdfModal.open">
    <div class="fixed inset-0 z-[220] flex items-center justify-center p-4 bg-slate-950/75 backdrop-blur-sm" @click.self="closeListadoPdf()">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[92vh] flex flex-col overflow-hidden border border-white/20">
        <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 text-white">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-file-type-pdf text-xl"></i>
              </div>
              <div class="min-w-0">
                <p class="text-[11px] font-black uppercase tracking-wide text-blue-100">Listado de credenciales</p>
                <h3 class="font-black text-base truncate">Vista previa PDF</h3>
                <p class="text-xs text-white/60 mt-0.5">
                  <span x-text="listadoPdfModal.total"></span> registros
                  <span class="mx-1">•</span>
                  <span x-text="listadoPdfModal.filtros"></span>
                </p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button type="button" @click="descargarListadoPdf()"
                      class="inline-flex items-center gap-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black px-3 py-2 transition-colors">
                <i class="ti ti-download"></i>
                Descargar PDF
              </button>
              <button type="button" @click="imprimirListadoPdf()"
                      class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                <i class="ti ti-printer"></i>
                Imprimir
              </button>
              <button type="button" @click="abrirListadoPdf()"
                      class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                <i class="ti ti-external-link"></i>
                Abrir
              </button>
              <button type="button" @click="closeListadoPdf()"
                      class="w-9 h-9 rounded-lg bg-white/10 hover:bg-red-500/80 text-white flex items-center justify-center transition-colors">
                <i class="ti ti-x text-lg"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 text-xs text-gray-500">
            <i class="ti ti-info-circle text-blue-500"></i>
            <span>El listado usa los filtros activos. Para descargar, elige Guardar como PDF en el diálogo de impresión.</span>
          </div>
          <button type="button" @click="openListadoPdf()"
                  class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-black px-3 py-2 transition-colors">
            <i class="ti ti-refresh"></i>
            Actualizar vista
          </button>
        </div>

        <div class="flex-1 bg-slate-200 p-4 overflow-hidden">
          <div class="h-full rounded-xl bg-white shadow-inner border border-gray-200 overflow-hidden">
            <iframe :srcdoc="listadoPdfModal.html" class="w-full h-full" x-ref="listadoPdfFrame"></iframe>
          </div>
        </div>
      </div>
    </div>
  </template>

  <template x-if="pdfModal.open">
    <div class="cred-pdf-modal fixed inset-0 z-[10020] flex items-center justify-center p-4 bg-slate-950/75 backdrop-blur-sm" @click.self="closePdf()">
      <div class="cred-pdf-shell bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[92vh] flex flex-col overflow-hidden border border-white/20">
        <div class="cred-pdf-header px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 text-white">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="cred-pdf-title flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-file-type-pdf text-xl"></i>
              </div>
              <div class="min-w-0">
                <p class="text-[11px] font-black uppercase tracking-wide text-blue-100">Credencial generada</p>
                <h3 class="font-black text-base truncate" x-text="pdfModal.titulo"></h3>
                <p class="text-xs text-white/60 mt-0.5">
                  <span x-text="pdfModal.codigo"></span>
                  <span class="mx-1">•</span>
                  <span x-text="pdfModal.formato === 'a4' ? 'Formato A4' : 'Carnet 67×95mm'"></span>
                </p>
              </div>
            </div>

            <div class="cred-pdf-actions flex flex-wrap items-center gap-2">
              <button type="button" @click="enviarCorreoCredencial()"
                      x-show="pdfModal.credencial && pdfModal.credencial.correo"
                      :disabled="pdfModal.emailEnviando"
                      class="inline-flex items-center gap-2 rounded-lg bg-red-500 hover:bg-red-600 disabled:opacity-60 text-white text-xs font-black px-3 py-2 transition-colors">
                <svg x-show="pdfModal.emailEnviando" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <i x-show="!pdfModal.emailEnviando" class="ti ti-mail"></i>
                <span x-text="pdfModal.emailEnviando ? 'Enviando...' : (pdfModal.emailOk === true ? '¡Correo enviado!' : 'Enviar correo')"></span>
              </button>
                <button type="button" @click="enviarWhatsappPdf()"
                      x-show="pdfModal.credencial && pdfModal.credencial.whatsapp"
                      :disabled="pdfModal.whatsappEnviando"
                      class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] hover:bg-[#1ebe59] text-white text-xs font-black px-3 py-2 transition-colors">
                <svg x-show="pdfModal.whatsappEnviando" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                  <svg x-show="!pdfModal.whatsappEnviando" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                <span x-text="pdfModal.whatsappEnviando ? 'Preparando PDF...' : 'Enviar a WhatsApp'"></span>
              </button>
              <a :href="pdfModal.url + '&download=1'"
                 class="inline-flex items-center gap-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black px-3 py-2 transition-colors">
                <i class="ti ti-download"></i>
                Descargar
              </a>
              <button type="button" @click="imprimirPdfModal()"
                      class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                <i class="ti ti-printer"></i>
                Imprimir
              </button>
              <a :href="pdfModal.url" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                <i class="ti ti-external-link"></i>
                Abrir
              </a>
              <button type="button" @click="closePdf()"
                      class="cred-pdf-action-close w-9 h-9 rounded-lg bg-white/10 hover:bg-red-500/80 text-white flex items-center justify-center transition-colors">
                <i class="ti ti-x text-lg"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="cred-pdf-subbar px-5 py-3 bg-gray-50 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 text-xs text-gray-500">
            <i class="ti ti-info-circle text-blue-500"></i>
            <span>Revisa la credencial antes de descargarla o imprimirla.</span>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" @click="openPdf(pdfModal.credencial, 'a4')"
                    :class="pdfModal.formato === 'a4' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
                    class="inline-flex items-center gap-2 rounded-lg border text-xs font-black px-3 py-2 transition-colors">
              <i class="ti ti-file"></i>
              A4
            </button>
            <button type="button" @click="openPdf(pdfModal.credencial, 'carnet')"
                    :class="pdfModal.formato === 'carnet' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
                    class="inline-flex items-center gap-2 rounded-lg border text-xs font-black px-3 py-2 transition-colors">
              <i class="ti ti-id-badge-2"></i>
              Carnet
            </button>
          </div>
        </div>

        <div class="cred-pdf-viewer flex-1 bg-slate-200 p-4 overflow-hidden">
          <div class="h-full rounded-xl bg-white shadow-inner border border-gray-200 overflow-hidden">
            <iframe :src="pdfModal.url" class="w-full h-full" x-ref="pdfFrame"></iframe>
          </div>
        </div>
      </div>
    </div>
  </template>

  <!-- ════════════════════════════════════════════════════════
       MODAL: Confirmar eliminación
  ════════════════════════════════════════════════════════ -->
  <template x-if="confirmDel.open">
    <div class="fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="confirmDel.open=false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <h3 class="font-black text-gray-800 text-lg mb-2">¿Eliminar credencial?</h3>
        <p class="text-sm text-gray-500 mb-5">
          Se eliminará permanentemente la credencial de <strong x-text="confirmDel.nombre"></strong>. Esta acción no se puede deshacer.
        </p>
        <div class="flex items-center justify-end gap-2">
          <button @click="confirmDel.open=false" class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2.5">Cancelar</button>
          <button @click="eliminar()" class="bg-red-600 hover:bg-red-700 text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow transition-all">Eliminar</button>
        </div>
      </div>
    </div>
  </template>

  <!-- ── Modal: conflicto de nombre BD interna vs RENIEC ────────── -->
  <template x-if="confirmNombre.open">
    <div class="fixed inset-0 z-[210] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="aplicarNombreReniec(false)">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <div class="flex items-start gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-amber-50 text-amber-500 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
          </div>
          <div>
            <h3 class="font-black text-gray-800 text-lg">El nombre no coincide</h3>
            <p class="text-sm text-gray-500 mt-1">
              Este DNI ya está registrado en militantes/simpatizantes, pero el nombre difiere del que reporta RENIEC.
              La fuente oficial es RENIEC.
            </p>
          </div>
        </div>
        <div class="space-y-2 mb-5">
          <div class="rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-[11px] font-black text-gray-400 uppercase tracking-wide">Registrado en el sistema</p>
            <p class="text-sm font-bold text-gray-700" x-text="confirmNombre.nombreInterno"></p>
          </div>
          <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
            <p class="text-[11px] font-black text-blue-500 uppercase tracking-wide">Según RENIEC</p>
            <p class="text-sm font-bold text-blue-800" x-text="confirmNombre.nombreReniec"></p>
          </div>
        </div>
        <p class="text-sm text-gray-600 mb-5">¿Deseas usar el nombre que reporta RENIEC para esta credencial?</p>
        <div class="flex items-center justify-end gap-2">
          <button @click="aplicarNombreReniec(false)" class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2.5">No, mantener el registrado</button>
          <button @click="aplicarNombreReniec(true)" class="bg-[#1E3A8A] hover:bg-blue-900 text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow transition-all">Sí, usar el de RENIEC</button>
        </div>
      </div>
    </div>
  </template>

</div>
  </div><!-- /tab credenciales -->

  <!-- ══════════════════════════════════════════════════════════════════
       TAB: CC.PP y CC.NN (Gestión de jurisdicción)
  ══════════════════════════════════════════════════════════════════ -->
  <div x-show="mainTab==='jurisdiccion'" x-cloak>

  <div x-data="jurisdiccionApp()"
       x-init="init()"
       @registro-guardado.window="cargar($event.detail.tipo)"
       @registro-eliminado.window="cargar($event.detail.tipo)">

    <!-- ── MODAL WORKSPACE ───────────────────────────────────────────────── -->
    <template x-if="wsOpen">
      <div class="fixed inset-0 z-[500] flex items-center justify-center p-4"
           style="background:rgba(15,32,87,0.85);backdrop-filter:blur(8px)">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden"
             x-transition:enter="transition ease-out duration-250"
             x-transition:enter-start="opacity-0 scale-90"
             x-transition:enter-end="opacity-100 scale-100">

          <div class="bg-gradient-to-br from-[#1E3A8A] to-[#1e4fd8] px-6 py-6 text-center">
            <div class="w-14 h-14 bg-white/15 rounded-2xl flex items-center justify-center mx-auto mb-3">
              <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
              </svg>
            </div>
            <h2 class="text-white font-black text-lg">Configurar espacio de trabajo</h2>
            <p class="text-white/60 text-sm mt-1">Selecciona la región y provincia donde operarás</p>
          </div>

          <div class="p-6 space-y-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Región</label>
              <select x-model="wsDraft.region" @change="wsDraft.provincia=''"
                      class="w-full px-3 py-3 text-sm border-2 border-gray-200 focus:border-[#1E3A8A] rounded-xl bg-white outline-none transition-colors font-semibold">
                <option value="">Selecciona una región…</option>
                <template x-for="r in GEO_REGIONES" :key="r">
                  <option :value="r" x-text="r"></option>
                </template>
              </select>
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Provincia</label>
              <select x-model="wsDraft.provincia" :disabled="!wsDraft.region"
                      class="w-full px-3 py-3 text-sm border-2 border-gray-200 focus:border-[#1E3A8A] rounded-xl bg-white outline-none transition-colors font-semibold disabled:bg-gray-50 disabled:text-gray-400">
                <option value="">Selecciona una provincia…</option>
                <template x-for="p in provinciasDraft()" :key="p">
                  <option :value="p" x-text="p"></option>
                </template>
              </select>
            </div>

            <template x-if="wsDraft.region && wsDraft.provincia">
              <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3">
                <p class="text-xs font-black text-blue-600 uppercase tracking-wide mb-2">Distritos del workspace</p>
                <div class="flex flex-wrap gap-1">
                  <template x-for="d in distritosDraft()" :key="d">
                    <span class="text-[11px] bg-white border border-blue-200 text-blue-700 font-semibold px-2 py-0.5 rounded-lg" x-text="d"></span>
                  </template>
                </div>
              </div>
            </template>
          </div>

          <div class="px-6 pb-6">
            <button type="button" @click="confirmarWorkspace()"
                    :disabled="!wsDraft.region || !wsDraft.provincia"
                    class="w-full flex items-center justify-center gap-2 bg-[#1E3A8A] hover:bg-[#1e4fd8] disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-black text-sm py-3.5 rounded-xl shadow-lg transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
              </svg>
              Establecer espacio de trabajo
            </button>
            <template x-if="wsGuardado">
              <button type="button" @click="wsOpen=false"
                      class="w-full mt-2 text-sm font-semibold text-gray-400 hover:text-gray-600 py-2 transition-colors">
                Cancelar
              </button>
            </template>
          </div>
        </div>
      </div>
    </template>

    <!-- ── CONTENIDO DEL MÓDULO ─────────────────────────────────────────── -->
    <div class="space-y-5" x-show="wsGuardado">

      <!-- Encabezado -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-black text-gray-800">Gestión CC.PP y CC.NN</h1>
          <p class="text-sm text-gray-400 mt-0.5">Catálogo de Centros Poblados y Comunidades Nativas</p>
        </div>
        <div class="flex items-center gap-2 bg-[#1E3A8A]/5 border border-[#1E3A8A]/15 rounded-2xl px-3 py-2">
          <svg class="w-4 h-4 text-[#1E3A8A] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
          </svg>
          <div class="text-xs">
            <span class="text-gray-400 font-semibold" x-text="ws.region"></span>
            <span class="text-gray-300 mx-1">/</span>
            <span class="text-[#1E3A8A] font-black" x-text="ws.provincia"></span>
          </div>
          <button type="button" @click="cambiarWorkspace()"
                  class="text-[11px] font-black text-[#1E3A8A]/60 hover:text-[#1E3A8A] underline underline-offset-2 transition-colors ml-1">
            Cambiar
          </button>
        </div>
      </div>

      <!-- Pestañas -->
      <div class="flex gap-1 bg-gray-100 p-1 rounded-2xl w-fit">
        <button type="button" @click="tab='ccpp'"
                :class="tab==='ccpp' ? 'bg-white text-emerald-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
                class="flex items-center gap-2 px-5 py-2 rounded-xl text-sm transition-all">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          Centro Poblado
          <span x-show="ccpp.rows.length > 0" class="bg-emerald-100 text-emerald-700 text-[10px] font-black px-1.5 py-0.5 rounded-full" x-text="ccpp.rows.length"></span>
        </button>
        <button type="button" @click="tab='ccnn'"
                :class="tab==='ccnn' ? 'bg-white text-amber-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
                class="flex items-center gap-2 px-5 py-2 rounded-xl text-sm transition-all">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          Comunidad Nativa
          <span x-show="ccnn.rows.length > 0" class="bg-amber-100 text-amber-700 text-[10px] font-black px-1.5 py-0.5 rounded-full" x-text="ccnn.rows.length"></span>
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════
           PANEL: Centro Poblado
      ══════════════════════════════════════════════════════════════════ -->
      <div x-show="tab==='ccpp'"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
           class="space-y-4">

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
              <input type="text" x-model="ccpp.q" @input.debounce.300ms="cargar('ccpp')"
                     placeholder="Buscar centro poblado…"
                     class="pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-400 w-52">
              <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
              </svg>
            </div>
            <select x-model="ccpp.filtroDistrito" @change="cargar('ccpp')"
                    class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
              <option value="">Todos los distritos</option>
              <template x-for="d in distritosWorkspace()" :key="d">
                <option :value="d" x-text="d"></option>
              </template>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <!-- Exportar CC.PP -->
            <button type="button" @click="abrirExportar('ccpp')" x-show="ccpp.rows.length > 0"
                    class="inline-flex items-center gap-2 border border-emerald-300 text-emerald-700 hover:bg-emerald-50 text-sm font-bold px-3 py-2 rounded-xl transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              Exportar
            </button>
            <!-- Crear CC.PP -->
            <button type="button" @click="abrirCrear('ccpp')"
                    class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2 rounded-xl shadow transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
              </svg>
              Crear C.P.
            </button>
          </div>
        </div>

        <!-- Tabla CC.PP -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div x-show="ccpp.loading" class="flex items-center justify-center py-16">
            <svg class="w-7 h-7 animate-spin text-emerald-400" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
          </div>
          <div x-show="!ccpp.loading && ccpp.rows.length===0" class="flex flex-col items-center justify-center py-16 text-gray-400">
            <svg class="w-10 h-10 mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            </svg>
            <p class="text-sm font-semibold">Sin centros poblados en <span class="text-emerald-600" x-text="ws.provincia"></span></p>
            <p class="text-xs mt-1">Crea el primero con el botón "Crear C.P."</p>
          </div>
          <table x-show="!ccpp.loading && ccpp.rows.length>0" class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 bg-gray-50/60">
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide w-8">#</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Nombre</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Distrito</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Provincia</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Región</th>
                <th class="text-right px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="(row,i) in ccpp.rows" :key="row.id">
                <tr class="border-b border-gray-50 hover:bg-emerald-50/30 transition-colors">
                  <td class="px-4 py-3 text-gray-400 text-xs font-mono" x-text="i+1"></td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-emerald-100 flex-shrink-0">
                        <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        </svg>
                      </span>
                      <span class="font-semibold text-gray-800" x-text="row.nombre"></span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-gray-600" x-text="row.distrito||'—'"></td>
                  <td class="px-4 py-3"><span class="bg-emerald-50 text-emerald-700 text-xs font-bold px-2 py-0.5 rounded-lg" x-text="row.provincia||'—'"></span></td>
                  <td class="px-4 py-3 text-gray-500 text-xs" x-text="row.region||'—'"></td>
                  <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-1">
                      <button type="button" @click="abrirEditar('ccpp',row)"
                              class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors" title="Editar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                      </button>
                      <button type="button" @click="confirmarEliminar('ccpp',row)"
                              class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors" title="Eliminar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
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

      <!-- ══════════════════════════════════════════════════════════════════
           PANEL: Comunidad Nativa
      ══════════════════════════════════════════════════════════════════ -->
      <div x-show="tab==='ccnn'"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
           class="space-y-4">

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
              <input type="text" x-model="ccnn.q" @input.debounce.300ms="cargar('ccnn')"
                     placeholder="Buscar comunidad nativa…"
                     class="pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-amber-400 w-52">
              <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
              </svg>
            </div>
            <select x-model="ccnn.filtroDistrito" @change="cargar('ccnn')"
                    class="text-sm border border-gray-200 rounded-xl px-3 py-2 outline-none focus:ring-2 focus:ring-amber-400 bg-white">
              <option value="">Todos los distritos</option>
              <template x-for="d in distritosWorkspace()" :key="d">
                <option :value="d" x-text="d"></option>
              </template>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <!-- Exportar CC.NN -->
            <button type="button" @click="abrirExportar('ccnn')" x-show="ccnn.rows.length > 0"
                    class="inline-flex items-center gap-2 border border-amber-300 text-amber-700 hover:bg-amber-50 text-sm font-bold px-3 py-2 rounded-xl transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              Exportar
            </button>
            <!-- Crear CC.NN -->
            <button type="button" @click="abrirCrear('ccnn')"
                    class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold px-4 py-2 rounded-xl shadow transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
              </svg>
              Crear CC.NN.
            </button>
          </div>
        </div>

        <!-- Tabla CC.NN -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div x-show="ccnn.loading" class="flex items-center justify-center py-16">
            <svg class="w-7 h-7 animate-spin text-amber-400" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
          </div>
          <div x-show="!ccnn.loading && ccnn.rows.length===0" class="flex flex-col items-center justify-center py-16 text-gray-400">
            <svg class="w-10 h-10 mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <p class="text-sm font-semibold">Sin comunidades nativas en <span class="text-amber-600" x-text="ws.provincia"></span></p>
            <p class="text-xs mt-1">Crea la primera con el botón "Crear CC.NN."</p>
          </div>
          <table x-show="!ccnn.loading && ccnn.rows.length>0" class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 bg-gray-50/60">
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide w-8">#</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Nombre</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Distrito</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Provincia</th>
                <th class="text-left px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Región</th>
                <th class="text-right px-4 py-3 text-xs font-black text-gray-400 uppercase tracking-wide">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="(row,i) in ccnn.rows" :key="row.id">
                <tr class="border-b border-gray-50 hover:bg-amber-50/30 transition-colors">
                  <td class="px-4 py-3 text-gray-400 text-xs font-mono" x-text="i+1"></td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-amber-100 flex-shrink-0">
                        <svg class="w-3 h-3 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                      </span>
                      <span class="font-semibold text-gray-800" x-text="row.nombre"></span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-gray-600" x-text="row.distrito||'—'"></td>
                  <td class="px-4 py-3"><span class="bg-amber-50 text-amber-700 text-xs font-bold px-2 py-0.5 rounded-lg" x-text="row.provincia||'—'"></span></td>
                  <td class="px-4 py-3 text-gray-500 text-xs" x-text="row.region||'—'"></td>
                  <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-1">
                      <button type="button" @click="abrirEditar('ccnn',row)"
                              class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Editar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                      </button>
                      <button type="button" @click="confirmarEliminar('ccnn',row)"
                              class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors" title="Eliminar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
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

    </div><!-- /contenido -->
  </div><!-- /app jurisdiccion -->


  <!-- ════════════════════════════════════════════════════════════════════════
       MODAL CREAR / EDITAR (CC.PP / CC.NN)
  ════════════════════════════════════════════════════════════════════════ -->
  <div x-data="modalJurisdiccion()"
       @abrir-modal-jur.window="abrir($event.detail)"
       @keydown.escape.window="cerrar()">
    <template x-if="open">
      <div class="fixed inset-0 z-[320] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
           @click.self="cerrar()">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

          <div :class="tipo==='ccpp' ? 'bg-gradient-to-r from-emerald-600 to-emerald-500' : 'bg-gradient-to-r from-amber-500 to-amber-400'"
               class="px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                <template x-if="tipo==='ccpp'">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                </template>
                <template x-if="tipo==='ccnn'">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                  </svg>
                </template>
              </div>
              <div>
                <h3 class="text-white font-black text-base" x-text="id ? 'Editar' : 'Nuevo'"></h3>
                <p class="text-white/70 text-xs" x-text="tipo==='ccpp' ? 'Centro Poblado' : 'Comunidad Nativa'"></p>
              </div>
            </div>
            <button type="button" @click="cerrar()" class="w-8 h-8 flex items-center justify-center rounded-xl bg-white/20 hover:bg-white/30 text-white">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <div class="p-6 space-y-4">
            <template x-if="error">
              <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-bold rounded-xl px-4 py-3 flex items-start gap-2">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span x-text="error"></span>
              </div>
            </template>

            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5"
                     x-text="tipo==='ccpp' ? 'Nombre del Centro Poblado' : 'Nombre de la Comunidad Nativa'"></label>
              <input type="text" x-model="form.nombre" maxlength="200"
                     :placeholder="tipo==='ccpp' ? 'Ej. Paratushiali' : 'Ej. Cutivireni'"
                     class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl outline-none transition-colors"
                     :class="tipo==='ccpp' ? 'focus:ring-2 focus:ring-emerald-400' : 'focus:ring-2 focus:ring-amber-400'">
            </div>

            <!-- Región → Provincia → Distrito -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">
                  Región <span class="text-red-400">*</span>
                </label>
                <select x-model="form.region" @change="form.provincia=''; form.distrito=''"
                        class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl bg-white outline-none"
                        :class="[tipo==='ccpp'?'focus:ring-2 focus:ring-emerald-400':'focus:ring-2 focus:ring-amber-400',
                                 !form.region && camposMarcados ? 'border-red-300 bg-red-50' : '']">
                  <option value="">Región…</option>
                  <template x-for="r in GEO_REGIONES" :key="r">
                    <option :value="r" x-text="r"></option>
                  </template>
                </select>
              </div>
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">
                  Provincia <span class="text-red-400">*</span>
                </label>
                <select x-model="form.provincia" @change="form.distrito=''" :disabled="!form.region"
                        class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl bg-white outline-none disabled:bg-gray-50 disabled:text-gray-400"
                        :class="[tipo==='ccpp'?'focus:ring-2 focus:ring-emerald-400':'focus:ring-2 focus:ring-amber-400',
                                 !form.provincia && camposMarcados ? 'border-red-300 bg-red-50' : '']">
                  <option value="">Provincia…</option>
                  <template x-for="p in provincias()" :key="p">
                    <option :value="p" x-text="p"></option>
                  </template>
                </select>
              </div>
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">
                  Distrito <span class="text-red-400">*</span>
                </label>
                <select x-model="form.distrito" :disabled="!form.provincia"
                        class="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl bg-white outline-none disabled:bg-gray-50 disabled:text-gray-400"
                        :class="[tipo==='ccpp'?'focus:ring-2 focus:ring-emerald-400':'focus:ring-2 focus:ring-amber-400',
                                 !form.distrito && camposMarcados ? 'border-red-300 bg-red-50' : '']">
                  <option value="">Distrito…</option>
                  <template x-for="d in distritos()" :key="d">
                    <option :value="d" x-text="d"></option>
                  </template>
                </select>
              </div>
            </div>
            <p x-show="camposMarcados && (!form.region||!form.provincia||!form.distrito)"
               class="text-xs text-red-500 font-semibold -mt-1">
              Selecciona Región, Provincia y Distrito antes de guardar.
            </p>
          </div>

          <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3">
            <button type="button" @click="cerrar()"
                    class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cancelar</button>
            <button type="button" @click="guardar()" :disabled="saving"
                    :class="tipo==='ccpp'
                      ? 'bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300'
                      : 'bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300'"
                    class="inline-flex items-center gap-2 text-white text-sm font-bold px-6 py-2.5 rounded-xl shadow transition-all disabled:cursor-not-allowed">
              <svg x-show="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              <span x-text="saving ? 'Guardando…' : (id ? 'Guardar cambios' : (tipo==='ccpp' ? 'Crear C.P.' : 'Crear CC.NN.'))"></span>
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>


  <!-- ════════════════════════════════════════════════════════════════════════
       MODAL EXPORTAR PDF (preview elegante) — CC.PP / CC.NN
  ════════════════════════════════════════════════════════════════════════ -->
  <div x-data="modalExportarJur()"
       @abrir-exportar-jur.window="abrir($event.detail)"
       @keydown.escape.window="open=false">
    <template x-if="open">
      <div class="fixed inset-0 z-[400] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
           @click.self="open=false">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

          <!-- Header -->
          <div class="bg-gradient-to-r from-[#1E3A8A] to-[#2563eb] px-6 py-5 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="text-white font-black text-base" x-text="titulo"></h3>
                <p class="text-white/60 text-xs" x-text="'Provincia de ' + ws.provincia + ' · ' + rows.length + ' registros'"></p>
              </div>
            </div>
            <button type="button" @click="open=false" class="w-8 h-8 flex items-center justify-center rounded-xl bg-white/20 hover:bg-white/30 text-white">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Documento preview -->
          <div class="flex-1 overflow-y-auto bg-gray-100 p-6">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
              <!-- Encabezado del documento -->
              <div :class="tipo==='ccpp' ? 'bg-emerald-600' : 'bg-amber-500'" class="px-6 py-5">
                <div class="flex items-start justify-between">
                  <div>
                    <p class="text-white/70 text-xs font-semibold uppercase tracking-widest mb-0.5">Partido Político</p>
                    <h2 class="text-white font-black text-lg leading-tight" x-text="titulo"></h2>
                    <p class="text-white/80 text-sm mt-1" x-text="'Provincia de ' + ws.provincia + ', ' + ws.region"></p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <div class="text-white/60 text-xs" x-text="fechaHoy()"></div>
                    <div :class="tipo==='ccpp' ? 'bg-emerald-500' : 'bg-amber-400'"
                         class="mt-2 text-white font-black text-2xl w-14 h-14 rounded-xl flex items-center justify-center ml-auto"
                         x-text="rows.length"></div>
                    <div class="text-white/60 text-[10px] mt-1">registros</div>
                  </div>
                </div>
              </div>

              <!-- Tabla -->
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr :class="tipo==='ccpp' ? 'bg-emerald-50 border-b border-emerald-100' : 'bg-amber-50 border-b border-amber-100'">
                      <th class="text-left px-4 py-2.5 text-xs font-black text-gray-500 uppercase tracking-wide w-8">#</th>
                      <th class="text-left px-4 py-2.5 text-xs font-black text-gray-500 uppercase tracking-wide">Nombre</th>
                      <th class="text-left px-4 py-2.5 text-xs font-black text-gray-500 uppercase tracking-wide">Distrito</th>
                      <th class="text-left px-4 py-2.5 text-xs font-black text-gray-500 uppercase tracking-wide">Provincia</th>
                      <th class="text-left px-4 py-2.5 text-xs font-black text-gray-500 uppercase tracking-wide">Región</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template x-for="(row,i) in rows" :key="row.id">
                      <tr :class="i%2===0 ? 'bg-white' : 'bg-gray-50/60'" class="border-b border-gray-50">
                        <td class="px-4 py-2.5 text-gray-400 text-xs font-mono" x-text="i+1"></td>
                        <td class="px-4 py-2.5 font-semibold text-gray-800" x-text="row.nombre"></td>
                        <td class="px-4 py-2.5 text-gray-600 text-xs" x-text="row.distrito||'—'"></td>
                        <td class="px-4 py-2.5 text-gray-600 text-xs" x-text="row.provincia||'—'"></td>
                        <td class="px-4 py-2.5 text-gray-500 text-xs" x-text="row.region||'—'"></td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>

              <!-- Footer del documento -->
              <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                <p class="text-[10px] text-gray-400 font-semibold" x-text="(window.BRAND_NAME || 'Credenciales App') + ' · Sistema de Gestión de Credenciales'"></p>
                <p class="text-[10px] text-gray-400" x-text="'Generado: ' + fechaHoy()"></p>
              </div>
            </div>
          </div>

          <!-- Footer con acciones -->
          <div class="px-6 py-4 border-t border-gray-100 bg-white flex-shrink-0 flex items-center justify-between gap-3">
            <button type="button" @click="open=false"
                    class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cerrar</button>
            <div class="flex items-center gap-2">
              <!-- Imprimir -->
              <button type="button" @click="imprimir()"
                      class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-bold px-4 py-2.5 rounded-xl transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir
              </button>
              <!-- Descargar PDF -->
              <button type="button" @click="descargarPDF()" :disabled="pdfLoading"
                      :class="tipo==='ccpp'
                        ? 'bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300'
                        : 'bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300'"
                      class="inline-flex items-center gap-2 text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow transition-all disabled:cursor-not-allowed">
                <svg x-show="pdfLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <svg x-show="!pdfLoading" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span x-text="pdfLoading ? 'Generando…' : 'Descargar PDF'"></span>
              </button>
            </div>
          </div>

        </div>
      </div>
    </template>
  </div>


  <!-- ════════════════════════════════════════════════════════════════════════
       MODAL ELIMINAR (CC.PP / CC.NN)
  ════════════════════════════════════════════════════════════════════════ -->
  <div x-data="modalEliminarJur()" @confirmar-eliminar-jur.window="abrir($event.detail)" @keydown.escape.window="open=false">
    <template x-if="open">
      <div class="fixed inset-0 z-[330] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
           @click.self="open=false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </div>
            <div>
              <h3 class="font-black text-gray-800">¿Eliminar registro?</h3>
              <p class="text-xs text-gray-400" x-text="tipo==='ccpp' ? 'Centro Poblado' : 'Comunidad Nativa'"></p>
            </div>
          </div>
          <p class="text-sm text-gray-600 mb-5">Se eliminará permanentemente <strong x-text="nombre"></strong>. Esta acción no se puede deshacer.</p>
          <div class="flex items-center justify-end gap-3">
            <button type="button" @click="open=false" class="text-sm font-bold text-gray-500 hover:text-gray-700 px-4 py-2">Cancelar</button>
            <button type="button" @click="eliminar()" :disabled="saving"
                    class="inline-flex items-center gap-2 bg-red-500 hover:bg-red-600 disabled:opacity-50 text-white text-sm font-bold px-5 py-2 rounded-xl transition-all">
              <svg x-show="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>

  </div><!-- /tab jurisdiccion -->

  <!-- ════════════════════════════════════════════════════════
       TAB: Configurar PDF Credencial
  ════════════════════════════════════════════════════════ -->
  <div x-show="mainTab==='configurar_pdf'" x-cloak x-data="configPdfApp()" x-init="cargar()" class="cred-config-page">
    <div class="space-y-5" x-show="!loading">

      <!-- Sub-pestañas -->
      <div class="cred-config-toolbar flex flex-wrap items-center justify-between gap-3">
        <div class="cred-config-tabs">
        <div class="cred-config-tabs-inner flex gap-1 bg-gray-100 p-1 rounded-2xl w-fit">
          <button type="button" @click="subTab='plantilla'"
                  :class="subTab==='plantilla' ? 'bg-white text-amber-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
                  class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm transition-all">
            <i class="ti ti-photo"></i> Plantilla
          </button>
          <button type="button" @click="subTab='titulos'"
                  :class="subTab==='titulos' ? 'bg-white text-amber-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
                  class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm transition-all">
            <i class="ti ti-heading"></i> Títulos
          </button>
          <button type="button" @click="subTab='textos'"
                  :class="subTab==='textos' ? 'bg-white text-amber-700 shadow-sm font-black' : 'text-gray-500 hover:text-gray-700 font-semibold'"
                  class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm transition-all">
            <i class="ti ti-signature"></i> Texto y Firmas
          </button>
        </div>
        </div>

        <div class="cred-config-actions flex items-center gap-2">
          <span x-show="msg" x-text="msg" class="text-xs font-bold" :class="msgOk ? 'text-emerald-600' : 'text-red-600'"></span>
          <button type="button" @click="vistaPrevia('a4')" :disabled="saving"
                  class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-bold px-4 py-2 rounded-xl transition-all">
            <i class="ti ti-eye"></i> Vista previa A4
          </button>
          <button type="button" @click="vistaPrevia('carnet')" :disabled="saving"
                  class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-bold px-4 py-2 rounded-xl transition-all">
            <i class="ti ti-id-badge-2"></i> Vista previa Carnet
          </button>
          <button type="button" @click="guardar()" :disabled="saving"
                  class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-sm font-black px-5 py-2 rounded-xl transition-all">
            <svg x-show="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <i class="ti ti-device-floppy" x-show="!saving"></i> Guardar configuración
          </button>
        </div>
      </div>

      <!-- PESTAÑA 1: Plantilla -->
      <div x-show="subTab==='plantilla'" class="cred-config-panel cred-upload-card bg-white rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h3 class="font-black text-gray-800 mb-1">Plantilla de fondo (SVG, PNG o JPG, tamaño A4)</h3>
          <p class="text-xs text-gray-400 mb-3">
            Sube una sola plantilla A4 con encabezado, pie de página y marca de agua. Sobre esta imagen se
            renderizarán los datos del credencial. El mismo fondo se usará para el formato Carnet,
            escalando todo el documento a 67&nbsp;mm × 95&nbsp;mm.
          </p>
          <div class="cred-upload-row flex items-start gap-5">
            <div class="cred-upload-preview w-48 h-64 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center overflow-hidden bg-gray-50">
              <img x-show="plantillaPreview" :src="plantillaPreview" class="w-full h-full object-contain">
              <span x-show="!plantillaPreview" class="text-xs text-gray-400 text-center px-2">Sin plantilla cargada</span>
            </div>
            <div class="flex-1 space-y-2">
              <input type="file" accept=".svg,.png,.jpg,.jpeg,image/svg+xml,image/png,image/jpeg" x-ref="plantillaInput" @change="onFile($event,'plantilla')"
                     class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-amber-50 file:text-amber-700 file:font-bold hover:file:bg-amber-100">
              <p class="text-xs text-gray-400">Formatos permitidos: SVG, PNG, JPG. Tamaño máximo 6&nbsp;MB.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- PESTAÑA 1B: Reverso / lado 2 -->
      <div x-show="subTab==='plantilla'" class="cred-config-panel cred-upload-card bg-white rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h3 class="font-black text-gray-800 mb-1">Imagen reverso / lado 2 (SVG, PNG o JPG, tama&ntilde;o A4)</h3>
          <p class="text-xs text-gray-400 mb-3">
            Esta imagen se imprimirá como la segunda cara del PDF. Para carnet se usará la misma imagen
            escalada al formato 67&nbsp;mm x 95&nbsp;mm.
          </p>
          <div class="cred-upload-row flex items-start gap-5">
            <div class="cred-upload-preview w-48 h-64 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center overflow-hidden bg-gray-50">
              <img x-show="reversoPreview" :src="reversoPreview" class="w-full h-full object-contain">
              <span x-show="!reversoPreview" class="text-xs text-gray-400 text-center px-2">Sin reverso cargado</span>
            </div>
            <div class="flex-1 space-y-2">
              <input type="file" accept=".svg,.png,.jpg,.jpeg,image/svg+xml,image/png,image/jpeg" x-ref="reversoInput" @change="onFile($event,'reverso')"
                     class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-blue-50 file:text-blue-700 file:font-bold hover:file:bg-blue-100">
              <p class="text-xs text-gray-400">Formatos permitidos: SVG, PNG, JPG. Tamaño máximo 6&nbsp;MB.</p>
            </div>
          </div>
        </div>
      </div>

      <div x-show="subTab==='titulos'" class="cred-config-panel bg-white rounded-2xl p-6 shadow-sm space-y-5">
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1">Mensaje del partido (centrado, mínimo 200 caracteres)</label>
          <textarea x-model="cfg.mensaje_partido" rows="4"
                    class="w-full rounded-xl border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400"></textarea>
          <p class="text-xs mt-1" :class="cfg.mensaje_partido.length >= 200 ? 'text-emerald-600' : 'text-red-500'">
            <span x-text="cfg.mensaje_partido.length"></span> / 200 caracteres mínimo
          </p>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1">Título</label>
          <input type="text" x-model="cfg.titulo_credencial" placeholder="CREDENCIAL"
                 class="w-full md:w-80 rounded-xl border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
        </div>

        <div class="border border-amber-100 bg-amber-50/40 rounded-xl p-4 space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h4 class="text-sm font-black text-gray-800">Estilo del título</h4>
              <p class="text-xs text-gray-500 mt-0.5">Controla la banda donde se imprime el texto principal de la credencial.</p>
            </div>
            <div class="min-w-[260px] max-w-md flex-1">
              <div class="rounded-lg px-5 py-2.5 text-center shadow-sm"
                   :style="{
                     backgroundColor: cfg.titulo_bg_color || '#1E3A8A',
                     color: cfg.titulo_text_color || '#FFFFFF',
                     fontFamily: cfg.titulo_font_family || 'DejaVu Sans',
                     fontSize: (cfg.titulo_font_size || 28) + 'px',
                     fontWeight: cfg.titulo_font_weight || 900,
                     fontStyle: Number(cfg.titulo_italic) ? 'italic' : 'normal',
                     letterSpacing: (cfg.titulo_letter_spacing || 0) + 'px',
                     borderRadius: (cfg.titulo_radius || 2) * 4 + 'px'
                   }"
                   x-text="cfg.titulo_credencial || 'CREDENCIAL'"></div>
            </div>
          </div>

          <div class="grid lg:grid-cols-4 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Tipo de letra</label>
              <select x-model="cfg.titulo_font_family" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
                <?php foreach (array_keys(titulo_font_catalog()) as $fontName): ?>
                  <option value="<?= htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($fontName, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Tamaño</label>
              <input type="number" min="16" max="42" x-model.number="cfg.titulo_font_size" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Negrita</label>
              <select x-model.number="cfg.titulo_font_weight" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
                <option :value="600">Semi bold</option>
                <option :value="700">Bold</option>
                <option :value="800">Extra bold</option>
                <option :value="900">Black</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Cursiva</label>
              <label class="flex items-center gap-2 h-10 px-3 rounded-lg border border-gray-200 bg-white text-sm font-bold text-gray-700">
                <input type="checkbox" x-model="cfg.titulo_italic" class="rounded text-amber-500 focus:ring-amber-400">
                Activar cursiva
              </label>
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Color texto</label>
              <input type="color" x-model="cfg.titulo_text_color" class="w-full h-10 rounded-lg border border-gray-200 bg-white p-1">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Color banda</label>
              <input type="color" x-model="cfg.titulo_bg_color" class="w-full h-10 rounded-lg border border-gray-200 bg-white p-1">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Alto banda (mm)</label>
              <input type="number" min="10" max="20" step="0.5" x-model.number="cfg.titulo_banner_height" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
            </div>
            <div>
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Redondeado / letras</label>
              <div class="grid grid-cols-2 gap-2">
                <input type="number" min="0" max="8" step="0.5" x-model.number="cfg.titulo_radius" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400" title="Radio">
                <input type="number" min="0" max="6" step="0.5" x-model.number="cfg.titulo_letter_spacing" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400" title="Espaciado de letras">
              </div>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-1">Código autonumerado — Sufijo</label>
          <div class="flex items-center gap-3">
            <input type="text" x-model="cfg.codigo_sufijo" maxlength="10" placeholder="IVCIS"
                   @input="cfg.codigo_sufijo = cfg.codigo_sufijo.toUpperCase().replace(/[^A-Z0-9]/g,'')"
                   class="w-40 rounded-xl border-gray-200 text-sm font-mono font-bold focus:ring-amber-400 focus:border-amber-400">
            <span class="text-xs text-gray-500">
              Ejemplo de código generado:
              <strong class="font-mono text-gray-800" x-text="(cfg.codigo_sufijo || 'IVCIS') + new Date().getFullYear() + '-0001'"></strong>
            </span>
          </div>
          <p class="text-xs text-gray-400 mt-1">Si cambias el sufijo, la numeración correlativa reinicia desde 0001.</p>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">Tamaño de credencial</label>
          <div class="flex items-center gap-6">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
              <input type="checkbox" value="a4" x-model="cfg.tamanos" class="rounded text-amber-500 focus:ring-amber-400">
              A4
            </label>
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
              <input type="checkbox" value="carnet" x-model="cfg.tamanos" class="rounded text-amber-500 focus:ring-amber-400">
              Carnet (67 × 95 mm)
            </label>
          </div>
        </div>
      </div>

      <!-- PESTAÑA 3: Texto y Firmas -->
      <div x-show="subTab==='textos'" class="space-y-6">
        <div class="cred-config-panel bg-white rounded-2xl p-6 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 pb-5">
            <div>
              <h3 class="font-black text-gray-800">Textos configurables</h3>
              <p class="text-xs text-gray-400 mt-1">Define los bloques que se imprimen sobre la credencial.</p>
            </div>
            <div class="flex flex-wrap gap-1.5 max-w-3xl">
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{nombres}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{dni}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{cargo}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{ccpp_ccnn}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{distrito}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{provincia}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{ciudad}}</code>
              <code class="bg-gray-100 text-gray-500 px-2 py-1 rounded-md text-[11px] font-bold">{{fecha}}</code>
            </div>
          </div>

          <div class="grid xl:grid-cols-[minmax(0,1fr)_340px] gap-5 pt-5">
            <div class="space-y-4">
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Bloque texto 1</label>
                <input type="text" x-model="cfg.texto1" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
              </div>
              <div class="max-w-xs">
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Tamaño del nombre ({{nombres}})</label>
                <input type="number" min="10" max="24" step="0.5" x-model.number="cfg.nombre_font_size" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
              </div>
              <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Bloque texto 2 (facultades)</label>
                <div class="flex flex-wrap gap-1.5 mb-2">
                  <template x-for="ph in placeholders" :key="ph.token">
                    <button type="button" @click="insertPlaceholder(ph.token)"
                            class="text-[11px] font-black bg-blue-50 text-blue-700 border border-blue-100 hover:bg-blue-100 px-2 py-1 rounded-md transition-colors"
                            x-text="ph.label"></button>
                  </template>
                  <button type="button" @click="insertCustomPlaceholder()"
                          class="text-[11px] font-black bg-amber-50 text-amber-700 border border-amber-100 hover:bg-amber-100 px-2 py-1 rounded-md transition-colors">
                    + Otro campo
                  </button>
                </div>
                <div class="cred-quill">
                  <div id="texto3-editor"></div>
                </div>
                <input type="hidden" x-model="cfg.texto3">
              </div>
            </div>

            <div class="bg-gray-50 border border-gray-100 rounded-lg p-4 h-fit">
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Ciudad</label>
              <input type="text" x-model="cfg.texto4_ciudad" class="w-full rounded-lg border-gray-200 text-sm focus:ring-amber-400 focus:border-amber-400">
              <div class="mt-3 rounded-lg bg-white border border-gray-100 px-3 py-2">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wide">Vista de fecha</p>
                <p class="text-sm font-black text-gray-700 mt-0.5" x-text="(cfg.texto4_ciudad || 'Satipo') + ', ' + fechaHoy()"></p>
              </div>
            </div>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 class="font-black text-gray-800">Sección Firmas</h3>
            <p class="text-sm text-gray-400 mt-0.5">Ordena los firmantes y carga sus firmas digitales.</p>
          </div>
          <div class="flex items-center gap-3 bg-white border border-gray-100 rounded-lg px-4 py-3 shadow-sm">
            <label class="text-xs font-black text-gray-500 uppercase tracking-wide">Cantidad</label>
            <select x-model.number="cfg.num_firmas" class="w-24 rounded-lg border-gray-200 text-sm font-bold focus:ring-amber-400 focus:border-amber-400">
              <option :value="1">1</option>
              <option :value="2">2</option>
              <option :value="3">3</option>
            </select>
          </div>
        </div>

        <div class="grid xl:grid-cols-3 md:grid-cols-2 gap-5">
          <template x-for="i in [0,1,2]" :key="i">
            <div x-show="i < cfg.num_firmas"
                 class="bg-white border-2 rounded-lg shadow-sm overflow-hidden transition-all"
                 :class="[
                   'border-amber-200 shadow-amber-100/80',
                   'border-sky-200 shadow-sky-100/80',
                   'border-emerald-200 shadow-emerald-100/80'
                 ][i]">
              <div class="flex items-center justify-between px-4 py-3 border-b"
                   :class="[
                     'bg-amber-50 border-amber-100',
                     'bg-sky-50 border-sky-100',
                     'bg-emerald-50 border-emerald-100'
                   ][i]">
                <div class="flex items-center gap-2">
                  <span class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-black shadow-sm"
                        :class="[
                          'bg-amber-500 text-white',
                          'bg-sky-600 text-white',
                          'bg-emerald-600 text-white'
                        ][i]"
                        x-text="i+1"></span>
                  <h4 class="text-sm font-black text-gray-800">Firma <span x-text="i+1"></span></h4>
                </div>
                <span class="text-[11px] font-black px-2 py-1 rounded-md"
                      :class="firmaPreviews[i] ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-400'"
                      x-text="firmaPreviews[i] ? 'Imagen cargada' : 'Sin imagen'"></span>
              </div>

              <div class="p-4 space-y-4">
                <div class="h-32 border border-dashed rounded-lg flex items-center justify-center overflow-hidden"
                     :class="[
                       'bg-amber-50/40 border-amber-200',
                       'bg-sky-50/40 border-sky-200',
                       'bg-emerald-50/40 border-emerald-200'
                     ][i]">
                  <img x-show="firmaPreviews[i]" :src="firmaPreviews[i]" class="max-w-full max-h-full object-contain p-3">
                  <div x-show="!firmaPreviews[i]" class="text-center px-4">
                    <i class="ti ti-signature text-2xl text-gray-300"></i>
                    <p class="text-xs font-semibold text-gray-400 mt-1">Firma digital</p>
                  </div>
                </div>

                <div class="space-y-3">
                  <div>
                    <label class="block text-[11px] font-black text-gray-500 uppercase tracking-wide mb-1.5">Nombre</label>
                    <input type="text" x-model="cfg.firmas[i].nombre"
                           placeholder="Nombre del firmante"
                           class="w-full h-11 rounded-lg border-2 border-gray-300 bg-white px-3 text-sm font-bold text-gray-900 shadow-inner outline-none placeholder:text-gray-300 focus:ring-4 focus:ring-amber-100 focus:border-amber-500">
                  </div>
                  <div>
                    <label class="block text-[11px] font-black text-gray-500 uppercase tracking-wide mb-1.5">Cargo</label>
                    <input type="text" x-model="cfg.firmas[i].cargo"
                           placeholder="Cargo del firmante"
                           class="w-full h-11 rounded-lg border-2 border-gray-300 bg-white px-3 text-sm font-bold text-gray-900 shadow-inner outline-none placeholder:text-gray-300 focus:ring-4 focus:ring-amber-100 focus:border-amber-500">
                  </div>
                </div>

                <label class="relative flex items-center justify-center gap-2 w-full rounded-lg border px-3 py-2.5 text-xs font-black cursor-pointer transition-colors"
                       :class="[
                         'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100',
                         'border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100',
                         'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                       ][i]">
                  <i class="ti ti-upload text-sm"></i>
                  <span x-text="firmaPreviews[i] ? 'Cambiar imagen' : 'Subir firma'"></span>
                  <input type="file" accept=".png,.jpg,.jpeg,.svg,.webp,image/*" @change="onFile($event,'firma',i)"
                         class="absolute inset-0 opacity-0 cursor-pointer">
                </label>
              </div>
            </div>
          </template>
        </div>
      </div>

    </div>

    <div x-show="loading" class="text-center py-20 text-gray-400 text-sm">Cargando configuración...</div>

    <!-- Modal vista previa PDF -->
    <template x-if="pdfModal.open">
      <div class="fixed inset-0 z-[220] flex items-center justify-center p-4 bg-slate-950/75 backdrop-blur-sm" @click.self="cerrarVistaPreviaPdf()">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[92vh] flex flex-col overflow-hidden border border-white/20">
          <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 text-white">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0">
                  <i class="ti ti-file-type-pdf text-xl"></i>
                </div>
                <div class="min-w-0">
                  <p class="text-[11px] font-black uppercase tracking-wide text-blue-100">Vista previa PDF</p>
                  <h3 class="font-black text-base truncate" x-text="pdfModal.titulo"></h3>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <a :href="pdfModal.url + '&download=1'"
                   class="inline-flex items-center gap-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black px-3 py-2 transition-colors">
                  <i class="ti ti-download"></i>
                  Descargar
                </a>
                <button type="button" @click="imprimirVistaPreviaPdf()"
                        class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                  <i class="ti ti-printer"></i>
                  Imprimir
                </button>
                <a :href="pdfModal.url" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-black px-3 py-2 transition-colors border border-white/15">
                  <i class="ti ti-external-link"></i>
                  Abrir
                </a>
                <button type="button" @click="cerrarVistaPreviaPdf()"
                        class="w-9 h-9 rounded-lg bg-white/10 hover:bg-red-500/80 text-white flex items-center justify-center transition-colors">
                  <i class="ti ti-x text-lg"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2 text-xs text-gray-500">
              <i class="ti ti-info-circle text-blue-500"></i>
              <span>La vista previa se actualiza con la configuracion actual antes de abrir el PDF.</span>
            </div>
            <button type="button" @click="vistaPrevia(pdfModal.formato)"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-black px-3 py-2 transition-colors">
              <i class="ti ti-refresh"></i>
              Actualizar vista
            </button>
          </div>

          <div class="flex-1 bg-slate-200 p-4 overflow-hidden">
            <div class="h-full rounded-xl bg-white shadow-inner border border-gray-200 overflow-hidden">
              <iframe :src="pdfModal.url" class="w-full h-full" x-ref="pdfPreviewFrame"></iframe>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div><!-- /tab configurar_pdf -->

</div><!-- /mainTab wrapper -->

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/ubigeo_peru.js"></script>
<script>
const CSRF_TOKEN     = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
const BASE_URL_JS    = '<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>';
const QR_PUBLIC_BASE_URL_JS = '<?= htmlspecialchars(QR_PUBLIC_BASE_URL, ENT_QUOTES) ?>';
const CREDENCIALES_DATA = <?= json_encode(array_values($credenciales), JSON_UNESCAPED_UNICODE) ?>;

function credencialApp() {
  return {
    credenciales: [],
    filtro: { busqueda: '', tipo: '', estado: '' },
    modal: { open: false, saving: false, error: '', id: 0, persona_tipo: '', persona_id: 0 },
    qrModal:  { open: false, url: '', nombre: '', codigo: '' },
    pdfModal: { open: false, url: '', titulo: '', formato: 'a4', codigo: '', credencial: null, emailEnviando: false, emailOk: null, whatsappEnviando: false },
    listadoPdfModal: { open: false, html: '', total: 0, filtros: 'Todos los registros' },
    confirmDel: { open: false, id: 0, nombre: '' },
    confirmNombre: { open: false, nombreInterno: '', nombreReniec: '' },
    cargoModal: { open: false, nombre: '', saving: false, error: '' },
    ccppModal:  { open: false, nombre: '', saving: false, error: '' },
    ccnnModal:  { open: false, nombre: '', saving: false, error: '' },
    validError: { open: false, titulo: '', mensaje: '' },
    cargosLista: <?= json_encode(array_column($cargos_catalogo, 'nombre'), JSON_UNESCAPED_UNICODE) ?>,
    dniRequestSeq: 0,

    init() {
      this.credenciales = CREDENCIALES_DATA;
      this.modal = this.modalVacio();
    },

    modalVacio() {
      const hoy = new Date();
      const fmt = d => d.toISOString().slice(0, 10);
      return {
        open: false, saving: false, error: '',
        id: 0, persona_tipo: '', persona_id: 0,
        consultandoDni: false, dniError: '',
        nombres_completos: '', dni: '', cargo: '', cargoSelect: '', correo: '', celular: '', whatsapp: '',
        centro_poblado: '', comunidad_nativa: '', distrito: '',
        provincia: '', region: '', direccion: '',
        fecha_emision: fmt(hoy), fecha_vencimiento: '2026-10-05', estado: 'activo',
        foto_preview: '', foto_actual: '', fotoArrastrando: false, fotoBlob: null, fotoError: '',
        ccppOpen: false, ccppQ: '', ccppLista: [], ccppLoaded: false, ccppLoading: false,
        ccnnOpen: false, ccnnQ: '', ccnnLista: [], ccnnLoaded: false, ccnnLoading: false,
      };
    },

    provinciasDeRegion() {
      if (!this.modal.region || !GEO_PERU[this.modal.region]) return [];
      return Object.keys(GEO_PERU[this.modal.region]).sort((a, b) => a.localeCompare(b, 'es'));
    },

    distritosDeProvinicia() {
      if (!this.modal.region || !this.modal.provincia) return [];
      const prov = (GEO_PERU[this.modal.region] || {})[this.modal.provincia];
      return prov ? [...prov].sort((a, b) => a.localeCompare(b, 'es')) : [];
    },

    // ── Cascada de jurisdicción ──────────────────────────────
    normalizarGeoTexto(valor) {
      return String(valor || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
    },

    coincidirGeo(valor, opciones) {
      const buscado = this.normalizarGeoTexto(valor);
      if (!buscado) return '';
      return (opciones || []).find(op => this.normalizarGeoTexto(op) === buscado) || '';
    },

    resolverJurisdiccionGuardada(c) {
      const regionGuardada = c?.region || '';
      const provinciaGuardada = c?.provincia || '';
      const distritoGuardado = c?.distrito || '';

      const region = this.coincidirGeo(regionGuardada, GEO_REGIONES) || regionGuardada;
      const provincias = region && GEO_PERU[region] ? Object.keys(GEO_PERU[region]) : [];
      const provincia = this.coincidirGeo(provinciaGuardada, provincias) || provinciaGuardada;
      const distritos = region && provincia && GEO_PERU[region]?.[provincia] ? GEO_PERU[region][provincia] : [];
      const distrito = this.coincidirGeo(distritoGuardado, distritos) || distritoGuardado;

      return { region, provincia, distrito };
    },

    resetCcppCcnn() {
      this.modal.centro_poblado   = '';
      this.modal.comunidad_nativa = '';
      this.modal.ccppLista  = []; this.modal.ccppLoaded = false; this.modal.ccppOpen = false;
      this.modal.ccnnLista  = []; this.modal.ccnnLoaded = false; this.modal.ccnnOpen = false;
    },
    onRegionChange()   { this.modal.provincia = ''; this.modal.distrito = ''; this.resetCcppCcnn(); },
    onProvinciaChange(){ this.modal.distrito = ''; this.resetCcppCcnn(); },
    onDistritoChange() { this.resetCcppCcnn(); },

    onCargoChange(e) {
      const val = e.target.value;
      if (val === '__nuevo__') {
        this.cargoModal.nombre = '';
        this.cargoModal.error  = '';
        this.cargoModal.open   = true;
        this.$nextTick(() => { this.modal.cargoSelect = this.modal.cargo; });
      } else {
        this.modal.cargo = val;
      }
    },

    async crearCargo() {
      const nombre = this.cargoModal.nombre.trim();
      if (!nombre) return;
      this.cargoModal.saving = true;
      this.cargoModal.error  = '';
      try {
        const res  = await fetch('credenciales-modulo.php?crear_cargo=1&nombre=' + encodeURIComponent(nombre));
        const data = await res.json();
        if (!data.ok) { this.cargoModal.error = data.msg; this.cargoModal.saving = false; return; }
        if (!this.cargosLista.includes(data.nombre)) this.cargosLista.push(data.nombre);
        this.modal.cargo      = data.nombre;
        this.modal.cargoSelect= data.nombre;
        this.cargoModal.open  = false;
      } catch (e) {
        this.cargoModal.error = 'Error de red.';
      }
      this.cargoModal.saving = false;
    },

    // ── Searchable Select — Centro Poblado ──────────────────────
    async cargarCcpp() {
      if (this.modal.ccppLoaded) return;
      this.modal.ccppLoading = true;
      try {
        const params = new URLSearchParams({ sugerir_jurisdiccion: '1', campo: 'centro_poblado', q: '', distrito: this.modal.distrito || '' });
        const data   = await (await fetch('credenciales-modulo.php?' + params)).json();
        this.modal.ccppLista  = data.sugerencias || [];
        this.modal.ccppLoaded = true;
      } catch(e) {}
      this.modal.ccppLoading = false;
    },

    async toggleCcpp() {
      if (!this.modal.distrito) return;
      if (this.modal.ccppOpen) { this.cerrarCcpp(); return; }
      this.modal.ccppOpen = true;
      this.modal.ccppQ    = '';
      await this.cargarCcpp();
      this.$nextTick(() => { this.$refs.ccppSearch?.focus(); });
    },

    cerrarCcpp() { this.modal.ccppOpen = false; },

    filtradosCcpp() {
      const q = this.modal.ccppQ.trim().toLowerCase();
      if (!q) return this.modal.ccppLista;
      return this.modal.ccppLista.filter(s => s.toLowerCase().includes(q));
    },

    seleccionarCcpp(nombre) {
      this.modal.centro_poblado = nombre;
      this.modal.ccppOpen       = false;
      this.modal.ccppQ          = '';
    },

    abrirModalNuevoCcpp() {
      this.modal.ccppOpen   = false;
      this.ccppModal.nombre = this.modal.ccppQ.trim();
      this.ccppModal.error  = '';
      this.ccppModal.saving = false;
      this.ccppModal.open   = true;
      this.$nextTick(() => { this.$refs.ccppModalInput?.focus(); });
    },

    async guardarNuevoCcpp() {
      const nombre = this.ccppModal.nombre.trim();
      if (!nombre || this.ccppModal.saving) return;
      this.ccppModal.saving = true;
      this.ccppModal.error  = '';
      try {
        const params = new URLSearchParams({ nombre, distrito: this.modal.distrito || '', provincia: this.modal.provincia || '', region: this.modal.region || '' });
        const data   = await (await fetch('credenciales-modulo.php?crear_ccpp=1&' + params)).json();
        if (!data.ok) { this.ccppModal.error = data.msg; this.ccppModal.saving = false; return; }
        if (!this.modal.ccppLista.includes(data.nombre)) this.modal.ccppLista.push(data.nombre);
        this.modal.centro_poblado = data.nombre;
        this.ccppModal.open = false;
      } catch(e) { this.ccppModal.error = 'Error de red al guardar.'; }
      this.ccppModal.saving = false;
    },

    // ── Searchable Select — Comunidad Nativa ────────────────────
    async cargarCcnn() {
      if (this.modal.ccnnLoaded) return;
      this.modal.ccnnLoading = true;
      try {
        const params = new URLSearchParams({ sugerir_jurisdiccion: '1', campo: 'comunidad_nativa', q: '', distrito: this.modal.distrito || '' });
        const data   = await (await fetch('credenciales-modulo.php?' + params)).json();
        this.modal.ccnnLista  = data.sugerencias || [];
        this.modal.ccnnLoaded = true;
      } catch(e) {}
      this.modal.ccnnLoading = false;
    },

    async toggleCcnn() {
      if (!this.modal.distrito) return;
      if (this.modal.ccnnOpen) { this.cerrarCcnn(); return; }
      this.modal.ccnnOpen = true;
      this.modal.ccnnQ    = '';
      await this.cargarCcnn();
      this.$nextTick(() => { this.$refs.ccnnSearch?.focus(); });
    },

    cerrarCcnn() { this.modal.ccnnOpen = false; },

    filtradosCcnn() {
      const q = this.modal.ccnnQ.trim().toLowerCase();
      if (!q) return this.modal.ccnnLista;
      return this.modal.ccnnLista.filter(s => s.toLowerCase().includes(q));
    },

    seleccionarCcnn(nombre) {
      this.modal.comunidad_nativa = nombre;
      this.modal.ccnnOpen         = false;
      this.modal.ccnnQ            = '';
    },

    abrirModalNuevoCcnn() {
      this.modal.ccnnOpen   = false;
      this.ccnnModal.nombre = this.modal.ccnnQ.trim();
      this.ccnnModal.error  = '';
      this.ccnnModal.saving = false;
      this.ccnnModal.open   = true;
      this.$nextTick(() => { this.$refs.ccnnModalInput?.focus(); });
    },

    async guardarNuevoCcnn() {
      const nombre = this.ccnnModal.nombre.trim();
      if (!nombre || this.ccnnModal.saving) return;
      this.ccnnModal.saving = true;
      this.ccnnModal.error  = '';
      try {
        const params = new URLSearchParams({ nombre, distrito: this.modal.distrito || '', provincia: this.modal.provincia || '', region: this.modal.region || '' });
        const data   = await (await fetch('credenciales-modulo.php?crear_ccnn=1&' + params)).json();
        if (!data.ok) { this.ccnnModal.error = data.msg; this.ccnnModal.saving = false; return; }
        if (!this.modal.ccnnLista.includes(data.nombre)) this.modal.ccnnLista.push(data.nombre);
        this.modal.comunidad_nativa = data.nombre;
        this.ccnnModal.open = false;
      } catch(e) { this.ccnnModal.error = 'Error de red al guardar.'; }
      this.ccnnModal.saving = false;
    },

    get filtrados() {
      const b = this.filtro.busqueda.toLowerCase();
      return this.credenciales.filter(c => {
        const nombre = (c.nombres_completos || '').toLowerCase();
        const dni    = (c.dni || '').toLowerCase();
        const codigo = (c.codigo || '').toLowerCase();
        if (b && !nombre.includes(b) && !dni.includes(b) && !codigo.includes(b)) return false;
        if (this.filtro.tipo   && c.persona_tipo !== this.filtro.tipo)   return false;
        if (this.filtro.estado && c.estado       !== this.filtro.estado) return false;
        return true;
      });
    },

    formatoFecha(f) {
      if (!f) return '—';
      const [y, m, d] = String(f).split(/[ T-]/);
      return `${d}/${m}/${y}`;
    },

    htmlEscape(v) {
      return String(v ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
      }[ch]));
    },

    filtrosListadoTexto() {
      const partes = [];
      if (this.filtro.busqueda.trim()) partes.push('Busqueda: ' + this.filtro.busqueda.trim());
      if (this.filtro.tipo) partes.push('Tipo: ' + (this.filtro.tipo === 'militante' ? 'Militantes' : 'Simpatizantes'));
      if (this.filtro.estado) partes.push('Estado: ' + this.filtro.estado);
      return partes.length ? partes.join(' / ') : 'Todos los registros';
    },

    buildListadoPdfHtml(rows) {
      const fecha = new Date().toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' });
      const filas = rows.map((c, i) => `
        <tr>
          <td class="num">${i + 1}</td>
          <td class="mono">${this.htmlEscape(c.codigo)}</td>
          <td><strong>${this.htmlEscape(c.nombres_completos)}</strong></td>
          <td>${this.htmlEscape(c.distrito || '—')}</td>
          <td class="mono">${this.htmlEscape(c.dni)}</td>
          <td>${this.htmlEscape(c.cargo || '')}</td>
          <td>${this.htmlEscape(c.persona_tipo === 'militante' ? 'Militante' : 'Simpatizante')}</td>
          <td>${this.htmlEscape(this.formatoFecha(c.fecha_emision))}</td>
          <td>${this.htmlEscape(this.formatoFecha(c.fecha_vencimiento))}</td>
          <td><span class="estado ${this.htmlEscape(c.estado)}">${this.htmlEscape(c.estado)}</span></td>
        </tr>
      `).join('');

      return `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
        <title>Listado de credenciales</title>
        <style>
          @page { size: A4 landscape; margin: 12mm 10mm 12mm 15mm; }
          * { box-sizing: border-box; }
          html { background: #E5E7EB; }
          body { margin: 0; color: #111827; font-family: Arial, sans-serif; font-size: 10.5px; background: #fff; padding: 12mm 10mm 12mm 15mm; min-height: 100vh; }
          .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1E3A8A; padding-bottom: 10px; margin-bottom: 12px; }
          h1 { margin: 0; font-size: 20px; color: #1E3A8A; letter-spacing: .02em; }
          .sub { margin-top: 4px; color: #6B7280; font-size: 10px; }
          .badge { background: #EFF6FF; color: #1E3A8A; border: 1px solid #BFDBFE; border-radius: 999px; padding: 5px 10px; font-weight: 700; }
          table { width: 100%; border-collapse: collapse; }
          th { background: #1E3A8A; color: white; padding: 8px 7px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
          td { padding: 7px; border-bottom: 1px solid #E5E7EB; vertical-align: middle; }
          tbody tr:nth-child(even) { background: #F8FAFC; }
          small { display: block; color: #6B7280; margin-top: 2px; }
          .mono { font-family: Consolas, monospace; font-weight: 700; }
          .num { width: 24px; color: #6B7280; text-align: center; }
          .estado { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
          .estado.activo { background: #D1FAE5; color: #065F46; }
          .estado.vencido { background: #FEF3C7; color: #92400E; }
          .estado.anulado { background: #FEE2E2; color: #991B1B; }
          .footer { margin-top: 12px; color: #9CA3AF; font-size: 9px; display: flex; justify-content: space-between; }
          @media print { html { background: #fff; } body { padding: 0; min-height: auto; print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
        </style></head><body>
          <div class="header">
            <div>
              <h1>Listado de credenciales generadas</h1>
              <div class="sub">${this.htmlEscape(this.filtrosListadoTexto())}</div>
              <div class="sub">Generado: ${this.htmlEscape(fecha)}</div>
            </div>
            <div class="badge">${rows.length} registros</div>
          </div>
          <table>
            <thead>
              <tr><th>#</th><th>Codigo</th><th>Titular</th><th>Distrito</th><th>DNI</th><th>Cargo</th><th>Origen</th><th>Emision</th><th>Vencimiento</th><th>Estado</th></tr>
            </thead>
            <tbody>${filas || '<tr><td colspan="10" style="text-align:center;padding:30px;color:#9CA3AF">Sin registros para imprimir</td></tr>'}</tbody>
          </table>
          <div class="footer"><span>${(window.BRAND_NAME || 'Credenciales App')} - Panel Admin</span><span>Credenciales</span></div>
        </body></html>`;
    },

    openListadoPdf() {
      const rows = this.filtrados;
      this.listadoPdfModal = {
        open: true,
        html: this.buildListadoPdfHtml(rows),
        total: rows.length,
        filtros: this.filtrosListadoTexto(),
      };
    },

    closeListadoPdf() {
      this.listadoPdfModal.open = false;
      this.listadoPdfModal.html = '';
    },

    imprimirListadoPdf() {
      const frame = this.$refs.listadoPdfFrame;
      if (!frame || !frame.contentWindow) {
        this.abrirListadoPdf();
        return;
      }
      frame.contentWindow.focus();
      frame.contentWindow.print();
    },

    async descargarListadoPdf() {
      const rows = this.filtrados;
      if (!rows.length) return;
      try {
        if (!window.jspdf || !window.jspdf.jsPDF || !window.jspdfAutoTableLoaded) {
          await this.loadPdfLibraries();
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const fecha = new Date().toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' });

        doc.setFillColor(30, 58, 138);
        doc.rect(0, 0, 297, 28, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text('Listado de credenciales generadas', 15, 12);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);
        doc.text(this.filtrosListadoTexto(), 15, 19);
        doc.text(`Generado: ${fecha} - Total: ${rows.length} registros`, 15, 24);

        doc.autoTable({
          startY: 34,
          head: [['#', 'Codigo', 'Titular', 'Distrito', 'DNI', 'Cargo', 'Origen', 'Emision', 'Vencimiento', 'Estado']],
          body: rows.map((c, i) => [
            i + 1,
            c.codigo || '',
            c.nombres_completos || '',
            c.distrito || '',
            c.dni || '',
            c.cargo || '',
            c.persona_tipo === 'militante' ? 'Militante' : 'Simpatizante',
            this.formatoFecha(c.fecha_emision),
            this.formatoFecha(c.fecha_vencimiento),
            (c.estado || '').toUpperCase(),
          ]),
          styles: { fontSize: 8, cellPadding: 3, font: 'helvetica', overflow: 'linebreak' },
          headStyles: { fillColor: [30, 58, 138], textColor: 255, fontStyle: 'bold' },
          alternateRowStyles: { fillColor: [248, 250, 252] },
          columnStyles: {
            0: { cellWidth: 9, halign: 'center' },
            1: { cellWidth: 26 },
            2: { cellWidth: 52 },
            3: { cellWidth: 28 },
            4: { cellWidth: 20 },
            5: { cellWidth: 36 },
            6: { cellWidth: 23 },
            7: { cellWidth: 23 },
            8: { cellWidth: 26 },
            9: { cellWidth: 20 },
          },
          margin: { left: 15, right: 10 },
        });

        const totalPages = doc.internal.getNumberOfPages();
        for (let i = 1; i <= totalPages; i++) {
          doc.setPage(i);
          doc.setFontSize(8);
          doc.setTextColor(150, 150, 150);
          doc.text((window.BRAND_NAME || 'Credenciales App') + ' - Panel Admin', 15, doc.internal.pageSize.height - 7);
          doc.text(`Pagina ${i} de ${totalPages}`, 287, doc.internal.pageSize.height - 7, { align: 'right' });
        }

        const stamp = new Date().toISOString().slice(0, 10);
        doc.save(`listado-credenciales-${stamp}.pdf`);
      } catch (e) {
        alert('No se pudo descargar el PDF. Intenta con el boton Imprimir y elige Guardar como PDF.');
        console.error(e);
      }
    },

    async loadPdfLibraries() {
      const load = src => new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${src}"]`);
        if (existing) { resolve(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
      });
      await load('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
      await load('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js');
      window.jspdfAutoTableLoaded = true;
    },

    abrirListadoPdf() {
      const w = window.open('', '_blank', 'width=1100,height=800');
      if (!w) return;
      w.document.open();
      w.document.write(this.listadoPdfModal.html || this.buildListadoPdfHtml(this.filtrados));
      w.document.close();
    },

    async buscarPorDni() {
      this.modal.dniError = '';
      this.modal.persona_tipo = '';
      this.modal.persona_id = 0;
      this.modal.nombres_completos = '';
      this.confirmNombre.open = false;
      if (this.modal.dni.length !== 8) return;

      // Cada tecleo dispara una consulta; este folio descarta respuestas
      // de búsquedas anteriores que lleguen tarde (evita nombres obsoletos).
      const folio = ++this.dniRequestSeq;
      this.modal.consultandoDni = true;
      try {
        const url = 'credenciales-modulo.php?consultar_dni=1&dni=' + encodeURIComponent(this.modal.dni);
        const res = await fetch(url);
        const data = await res.json();
        if (folio !== this.dniRequestSeq) return;

        if (!data.ok) {
          this.modal.dniError = data.msg || 'No se encontró información para este DNI.';
          this.modal.nombres_completos = '';
          return;
        }
        if (data.origen) {
          this.modal.persona_tipo = data.origen.tipo;
          this.modal.persona_id   = data.origen.id;
          this.modal.nombres_completos = data.origen.nombre;
          this.modal.cargo    = this.modal.cargo    || data.origen.cargo    || '';
          this.modal.correo   = this.modal.correo   || data.origen.correo   || '';
          this.modal.distrito = this.modal.distrito || data.origen.distrito || '';
        }
        if (data.reniec && data.reniec.nombre_completo) {
          const nombreReniec  = data.reniec.nombre_completo;
          const nombreInterno = data.origen ? data.origen.nombre : '';
          const normaliza = s => (s || '').trim().toUpperCase().replace(/\s+/g, ' ');

          if (nombreInterno && normaliza(nombreInterno) !== normaliza(nombreReniec)) {
            // El DNI ya existe internamente pero el nombre no coincide con RENIEC:
            // se pregunta al usuario si desea usar el nombre oficial de RENIEC.
            this.confirmNombre = { open: true, nombreInterno, nombreReniec };
          } else {
            this.modal.nombres_completos = nombreReniec;
          }
        }
      } catch (e) {
        if (folio !== this.dniRequestSeq) return;
        this.modal.dniError = 'Error de red al consultar el DNI.';
      } finally {
        if (folio === this.dniRequestSeq) this.modal.consultandoDni = false;
      }
    },

    aplicarNombreReniec(usarReniec) {
      this.modal.nombres_completos = usarReniec ? this.confirmNombre.nombreReniec : this.confirmNombre.nombreInterno;
      this.confirmNombre.open = false;
    },

    openModal(c = null) {
      if (c) {
        const jurisdiccion = this.resolverJurisdiccionGuardada(c);
        this.modal = {
          open: true, saving: false, error: '',
          id: c.id, persona_tipo: c.persona_tipo, persona_id: c.persona_id,
          consultandoDni: false, dniError: '',
          nombres_completos: c.nombres_completos, dni: c.dni,
          cargo: c.cargo || '', cargoSelect: c.cargo || '', correo: c.correo || '',
          celular: c.celular || '', whatsapp: c.whatsapp || '',
          centro_poblado: c.centro_poblado || '', comunidad_nativa: c.comunidad_nativa || '',
          distrito: jurisdiccion.distrito || '', provincia: jurisdiccion.provincia || 'Satipo', region: jurisdiccion.region || 'Junín',
          direccion: c.direccion || '',
          fecha_emision: c.fecha_emision, fecha_vencimiento: c.fecha_vencimiento, estado: c.estado,
          foto_preview: c.foto ? (BASE_URL_JS + '/' + c.foto) : '',
          foto_actual: c.foto || '',
          fotoArrastrando: false, fotoBlob: null, fotoError: '',
          ccppOpen: false, ccppQ: '', ccppLista: [], ccppLoaded: false, ccppLoading: false,
          ccnnOpen: false, ccnnQ: '', ccnnLista: [], ccnnLoaded: false, ccnnLoading: false,
        };
      } else {
        this.modal = this.modalVacio();
        this.modal.open = true;
      }
    },

    closeModal() { this.modal.open = false; },

    // ── Procesamiento de foto: resize a carnet + límite 1 MB ────
    procesarFoto(file) {
      const CARNET_W = 480, CARNET_H = 640, MAX_BYTES = 1024 * 1024;
      return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
          URL.revokeObjectURL(url);
          const canvas = document.createElement('canvas');
          canvas.width = CARNET_W; canvas.height = CARNET_H;
          const ctx = canvas.getContext('2d');
          // Centro-crop a proporción 3:4
          const srcRatio = img.width / img.height;
          const dstRatio = CARNET_W / CARNET_H;
          let sx, sy, sw, sh;
          if (srcRatio > dstRatio) {
            sh = img.height; sw = Math.round(img.height * dstRatio);
            sx = Math.round((img.width - sw) / 2); sy = 0;
          } else {
            sw = img.width; sh = Math.round(img.width / dstRatio);
            sx = 0; sy = Math.round((img.height - sh) / 2);
          }
          ctx.drawImage(img, sx, sy, sw, sh, 0, 0, CARNET_W, CARNET_H);
          const tryBlob = (q) => {
            canvas.toBlob((blob) => {
              if (!blob) { reject(new Error('Canvas error')); return; }
              if (blob.size <= MAX_BYTES || q <= 0.30) { resolve(blob); }
              else { tryBlob(Math.max(q - 0.10, 0.30)); }
            }, 'image/jpeg', q);
          };
          tryBlob(0.92);
        };
        img.onerror = () => reject(new Error('No se pudo leer la imagen'));
        img.src = url;
      });
    },

    async procesarYMostrarFoto(file) {
      if (!file) return;
      this.modal.fotoError = '';
      try {
        const blob = await this.procesarFoto(file);
        this.modal.fotoBlob     = blob;
        this.modal.foto_preview = URL.createObjectURL(blob);
      } catch(e) {
        this.modal.fotoError = 'No se pudo procesar la imagen. Intenta con otro archivo.';
      }
    },

    soltarFoto(e) {
      const file = e.dataTransfer.files[0];
      if (!file) return;
      this.procesarYMostrarFoto(file);
    },

    mostrarErrorValidacion(titulo, mensaje) {
      this.validError = { open: true, titulo, mensaje };
    },

    async guardar() {
      // ── Validaciones frontend ────────────────────────────────
      const v = this.modal;
      if (!v.dni || v.dni.length !== 8)
        return this.mostrarErrorValidacion('DNI obligatorio', 'Ingresa los 8 dígitos del DNI del titular antes de continuar.');
      if (!v.nombres_completos?.trim())
        return this.mostrarErrorValidacion('Nombre requerido', 'Consulta el DNI para obtener el nombre del titular automáticamente.');
      if (!v.persona_tipo)
        return this.mostrarErrorValidacion('Origen obligatorio', 'Selecciona si el titular es Militante o Simpatizante.');
      if (!v.cargo)
        return this.mostrarErrorValidacion('Cargo obligatorio', 'Selecciona o crea el cargo del titular.');
      if (!v.celular?.trim())
        return this.mostrarErrorValidacion('Celular obligatorio', 'Ingresa el número de celular del titular.');
      if (!v.whatsapp?.trim())
        return this.mostrarErrorValidacion('WhatsApp obligatorio', 'Ingresa el número de WhatsApp del titular.');
      if (!v.region)
        return this.mostrarErrorValidacion('Jurisdicción incompleta', 'Selecciona la región correspondiente al titular.');
      if (!v.provincia)
        return this.mostrarErrorValidacion('Jurisdicción incompleta', 'Selecciona la provincia correspondiente al titular.');
      if (!v.distrito)
        return this.mostrarErrorValidacion('Jurisdicción incompleta', 'Selecciona el distrito correspondiente al titular.');
      this.modal.saving = true;
      this.modal.error  = '';
      const form = document.getElementById('form-credencial');
      const fd   = new FormData(form);
      fd.set('correo', '');
      fd.set('direccion', '');

      // Si hay foto procesada, sustituye el file input
      if (this.modal.fotoBlob) {
        fd.set('foto', this.modal.fotoBlob, 'foto.jpg');
      }

      let data;
      try {
        const res = await fetch('credenciales-modulo.php', { method: 'POST', body: fd });
        data = await res.json();
      } catch (e) {
        this.modal.error = 'Error de red al guardar. Verifica tu conexión.';
        this.modal.saving = false;
        return;
      }
      if (!data.ok) { this.modal.error = data.msg; this.modal.saving = false; return; }

      let d2 = null;
      try {
        const r2 = await fetch('credenciales-modulo.php?json=1&_=' + Date.now());
        if (r2.ok) {
          d2 = await r2.json();
          if (d2.credenciales) this.credenciales = d2.credenciales;
        } else { location.reload(); return; }
      } catch (_) { location.reload(); return; }

      const esNueva = !this.modal.id;
      this.closeModal();
      if (esNueva && data.id && d2) {
        const nueva = (d2.credenciales || []).find(x => parseInt(x.id) === parseInt(data.id));
        if (nueva) this.openPdf(nueva, 'a4');
      }
    },

    verQr(c) {
      this.qrModal = {
        open: true,
        url: BASE_URL_JS + '/uploads/credenciales/qr/qr_' + c.qr_token + '.png',
        nombre: c.nombres_completos,
        codigo: c.codigo,
      };
    },

    openPdf(c, formato) {
      this.pdfModal = {
        open: true,
        url: 'exportar-credencial-pdf.php?id=' + c.id + '&formato=' + formato,
        titulo: (formato === 'a4' ? 'Credencial A4 — ' : 'Carnet 67×95mm — ') + c.nombres_completos,
        formato: formato,
        codigo: c.codigo,
        credencial: c,
        emailEnviando: false,
        emailOk: null,
        whatsappEnviando: false,
      };
    },
    closePdf() {
      if (this.pdfModal.open && this.$refs.pdfFrame) this.$refs.pdfFrame.src = '';
      this.pdfModal.open = false;
    },

    async enviarWhatsappPdf() {
      const c = this.pdfModal.credencial;
      if (!c || !c.whatsapp || this.pdfModal.whatsappEnviando) return;

      const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
      let whatsappWindow = null;

      if (!isMobile) {
        whatsappWindow = window.open('about:blank', '_blank');
      }
      if (whatsappWindow) whatsappWindow.opener = null;

      this.pdfModal.whatsappEnviando = true;
      let pdfUrl = '';

      try {
        const url = 'exportar-credencial-pdf.php?id=' + encodeURIComponent(c.id) +
          '&formato=' + encodeURIComponent(this.pdfModal.formato) +
          '&save=1&_=' + Date.now();
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 45000);
        const res = await fetch(url, {
          headers: { 'Accept': 'application/json' },
          signal: controller.signal
        });
        clearTimeout(timer);

        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }

        const data = await res.json();

        if (!data.ok || !data.url) {
          alert(data.msg || 'No se pudo generar el enlace del PDF para WhatsApp.');
          if (whatsappWindow) whatsappWindow.close();
          this.pdfModal.whatsappEnviando = false;
          return;
        }

        pdfUrl = data.url;
      } catch (e) {
        if (whatsappWindow) whatsappWindow.close();
        alert(e && e.name === 'AbortError'
          ? 'La generación del PDF demoró demasiado. Intenta nuevamente.'
          : 'Error de red al generar el PDF para WhatsApp.');
        this.pdfModal.whatsappEnviando = false;
        return;
      }

      let num = String(c.whatsapp).replace(/[\s\-().+]/g, '');
      if (num.startsWith('0')) num = num.substring(1);
      if (!num.startsWith('51')) num = '51' + num;

      const verifyUrl = QR_PUBLIC_BASE_URL_JS.replace(/\/+$/, '') + '/v/' + encodeURIComponent(c.qr_token || '');
      const nombre = c.nombres_completos || '';
      const codigo = c.codigo || '';
      const msg = encodeURIComponent(
        'Estimado/a ' + nombre + ',\n\n' +
        'Le hacemos llegar su *CREDENCIAL OFICIAL* emitida por el partido.\n\n' +
        'Codigo: *' + codigo + '*\n' +
        'Descargue su credencial en PDF aqui:\n' +
        pdfUrl + '\n\n' +
        'Tambien puede verificar su credencial aqui:\n' +
        verifyUrl + '\n\n' +
        '_Alianza Para el Progreso - Satipo_'
      );

      const appUrl = 'whatsapp://send?phone=' + num + '&text=' + msg;
      const webUrl = 'https://wa.me/' + num + '?text=' + msg;

      if (isMobile) {
        const startedAt = Date.now();
        window.location.href = appUrl;
        setTimeout(() => {
          if (Date.now() - startedAt < 2200 && !document.hidden) {
            window.location.href = webUrl;
          }
        }, 1200);
      } else if (whatsappWindow) {
        whatsappWindow.location.href = webUrl;
      } else {
        window.location.href = webUrl;
      }
      this.pdfModal.whatsappEnviando = false;
    },

    enviarWhatsapp() {
      const c = this.pdfModal.credencial;
      if (!c || !c.whatsapp) return;
      // Normalizar número: quitar espacios/guiones, asegurar código de país Perú (+51)
      let num = String(c.whatsapp).replace(/[\s\-().+]/g, '');
      if (num.startsWith('0')) num = num.substring(1);
      if (!num.startsWith('51')) num = '51' + num;
      // Link de verificación pública usando el token QR
      const verifyUrl = QR_PUBLIC_BASE_URL_JS.replace(/\/+$/, '') + '/v/' + encodeURIComponent(c.qr_token || '');
      const nombre = c.nombres_completos || '';
      const codigo = c.codigo || '';
      const msg = encodeURIComponent(
        '📋 Estimado/a ' + nombre + ',\n\n' +
        'Le hacemos llegar su *CREDENCIAL OFICIAL* emitida por el partido.\n\n' +
        '🔹 Código: *' + codigo + '*\n' +
        '🔗 Puede visualizar y verificar su credencial en el siguiente enlace:\n' +
        verifyUrl + '\n\n' +
        '_Alianza Para el Progreso — Satipo_'
      );
      window.open('https://wa.me/' + num + '?text=' + msg, '_blank', 'noopener');
    },

    async enviarCorreoCredencial() {
      const c = this.pdfModal.credencial;
      if (!c || !c.correo) return;
      if (this.pdfModal.emailEnviando) return;
      this.pdfModal.emailEnviando = true;
      this.pdfModal.emailOk = null;
      const fd = new FormData();
      fd.append('action', 'enviar_credencial_email');
      fd.append('_csrf', CSRF_TOKEN);
      fd.append('id', c.id);
      try {
        const pdfUrl = 'exportar-credencial-pdf.php?id=' + encodeURIComponent(c.id) +
          '&formato=' + encodeURIComponent(this.pdfModal.formato) +
          '&save=1&_=' + Date.now();
        const pdfRes = await fetch(pdfUrl, { headers: { 'Accept': 'application/json' } });
        const pdfData = await pdfRes.json();
        if (!pdfRes.ok || !pdfData.ok || !pdfData.url) {
          throw new Error(pdfData.msg || 'No se pudo generar el enlace PDF.');
        }
        fd.append('pdf_url', pdfData.url);
        fd.append('formato', this.pdfModal.formato);

        const res  = await fetch('credenciales-modulo.php', { method: 'POST', body: fd });
        const data = await res.json();
        this.pdfModal.emailOk = data.ok;
        alert(data.ok ? '✅ ' + data.msg : '❌ ' + data.msg);
      } catch (e) {
        this.pdfModal.emailOk = false;
        alert('❌ Error de red al enviar el correo.');
      }
      this.pdfModal.emailEnviando = false;
    },

    imprimirPdfModal() {
      const frame = this.$refs.pdfFrame;
      if (!frame || !frame.contentWindow) {
        window.open(this.pdfModal.url, '_blank', 'noopener');
        return;
      }
      try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      } catch (e) {
        window.open(this.pdfModal.url, '_blank', 'noopener');
      }
    },

    confirmarEliminar(c) {
      this.confirmDel = { open: true, id: c.id, nombre: c.nombres_completos };
    },

    async eliminar() {
      const fd = new FormData();
      fd.append('action', 'delete_credencial');
      fd.append('_csrf', CSRF_TOKEN);
      fd.append('id', this.confirmDel.id);
      try {
        const res = await fetch('credenciales-modulo.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          this.credenciales = this.credenciales.filter(c => c.id !== this.confirmDel.id);
          this.confirmDel.open = false;
        }
      } catch (e) {}
    },

    async anularToggle(c) {
      const nuevo = c.estado === 'anulado' ? 'activo' : 'anulado';
      const fd = new FormData();
      fd.append('action', 'cambiar_estado');
      fd.append('_csrf', CSRF_TOKEN);
      fd.append('id', c.id);
      fd.append('estado', nuevo);
      try {
        const res = await fetch('credenciales-modulo.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) c.estado = data.estado;
      } catch (e) {}
    },
  };
}

// ── Tab CC.PP y CC.NN: App principal ───────────────────────────────────────
const WS_KEY = 'jur_workspace_v1';

function jurisdiccionApp() {
  return {
    tab: 'ccpp',
    ws:         { region: '', provincia: '' },
    wsOpen:     false,
    wsGuardado: false,
    wsDraft:    { region: '', provincia: '' },
    ccpp: { rows: [], q: '', filtroDistrito: '', loading: false },
    ccnn: { rows: [], q: '', filtroDistrito: '', loading: false },

    init() {
      const saved = JSON.parse(localStorage.getItem(WS_KEY) || 'null');
      if (saved?.region && saved?.provincia) {
        this.ws = { region: saved.region, provincia: saved.provincia };
        this.wsDraft = { ...this.ws };
        this.wsGuardado = true;
        this.cargar('ccpp');
        this.cargar('ccnn');
      } else {
        this.wsDraft = { region: 'Junín', provincia: 'Satipo' };
        this.wsOpen  = true;
      }
    },

    cambiarWorkspace() {
      this.wsDraft = { ...this.ws };
      this.wsOpen  = true;
    },

    confirmarWorkspace() {
      if (!this.wsDraft.region || !this.wsDraft.provincia) return;
      this.ws = { region: this.wsDraft.region, provincia: this.wsDraft.provincia };
      localStorage.setItem(WS_KEY, JSON.stringify(this.ws));
      this.wsGuardado = true;
      this.wsOpen     = false;
      this.ccpp.filtroDistrito = '';
      this.ccnn.filtroDistrito = '';
      this.cargar('ccpp');
      this.cargar('ccnn');
    },

    distritosWorkspace() {
      if (!this.ws.region || !this.ws.provincia) return [];
      return ([...(GEO_PERU[this.ws.region]?.[this.ws.provincia] || [])]).sort((a,b) => a.localeCompare(b,'es'));
    },

    provinciasDraft() {
      if (!this.wsDraft.region || !GEO_PERU[this.wsDraft.region]) return [];
      return Object.keys(GEO_PERU[this.wsDraft.region]).sort((a,b) => a.localeCompare(b,'es'));
    },

    distritosDraft() {
      if (!this.wsDraft.region || !this.wsDraft.provincia) return [];
      return ([...(GEO_PERU[this.wsDraft.region]?.[this.wsDraft.provincia] || [])]).sort((a,b) => a.localeCompare(b,'es'));
    },

    async cargar(t) {
      if (!this.ws.provincia) return;
      const s = this[t];
      s.loading = true;
      try {
        const p = new URLSearchParams({ jur_listar:1, tabla:t, q:s.q, provincia:this.ws.provincia, distrito:s.filtroDistrito });
        const data = await (await fetch('credenciales-modulo.php?' + p)).json();
        s.rows = data.rows || [];
      } catch(e) {}
      s.loading = false;
    },

    abrirCrear(tipo) {
      window.dispatchEvent(new CustomEvent('abrir-modal-jur', {
        detail: { tipo, id:0, form:{ nombre:'', region:this.ws.region, provincia:this.ws.provincia, distrito:'' } }
      }));
    },

    abrirEditar(tipo, row) {
      window.dispatchEvent(new CustomEvent('abrir-modal-jur', {
        detail: { tipo, id:row.id, form:{ nombre:row.nombre, region:row.region||this.ws.region, provincia:row.provincia||this.ws.provincia, distrito:row.distrito||'' } }
      }));
    },

    confirmarEliminar(tipo, row) {
      window.dispatchEvent(new CustomEvent('confirmar-eliminar-jur', { detail:{ tipo, id:row.id, nombre:row.nombre } }));
    },

    abrirExportar(tipo) {
      window.dispatchEvent(new CustomEvent('abrir-exportar-jur', {
        detail: { tipo, rows: this[tipo].rows, ws: { ...this.ws } }
      }));
    },
  };
}

// ── Tab CC.PP y CC.NN: Modal Crear/Editar ───────────────────────────────────
function modalJurisdiccion() {
  return {
    open:false, tipo:'ccpp', id:0, saving:false, error:'', camposMarcados:false,
    form:{ nombre:'', region:'', provincia:'', distrito:'' },

    abrir(d) {
      this.tipo = d.tipo; this.id = d.id; this.form = { ...d.form };
      this.error = ''; this.saving = false; this.camposMarcados = false;
      this.open = true;
    },

    cerrar() { this.open = false; },

    provincias() {
      if (!this.form.region) return [];
      return Object.keys(GEO_PERU[this.form.region]||{}).sort((a,b)=>a.localeCompare(b,'es'));
    },

    distritos() {
      if (!this.form.region||!this.form.provincia) return [];
      return ([...(GEO_PERU[this.form.region]?.[this.form.provincia]||[])]).sort((a,b)=>a.localeCompare(b,'es'));
    },

    async guardar() {
      this.error = '';
      // Validación frontend
      if (!this.form.nombre.trim()) { this.error = 'El nombre es obligatorio.'; return; }
      if (!this.form.region || !this.form.provincia || !this.form.distrito) {
        this.camposMarcados = true;
        this.error = 'Debes seleccionar Región, Provincia y Distrito antes de guardar.';
        return;
      }
      this.saving = true;
      try {
        const fd = new FormData();
        fd.append('_csrf',     CSRF_TOKEN);
        fd.append('action',    this.id ? 'jur_editar' : 'jur_crear');
        fd.append('tabla',     this.tipo);
        fd.append('id',        this.id);
        fd.append('nombre',    this.form.nombre.trim());
        fd.append('distrito',  this.form.distrito);
        fd.append('provincia', this.form.provincia);
        fd.append('region',    this.form.region);

        const data = await (await fetch('credenciales-modulo.php', { method:'POST', body:fd })).json();
        if (!data.ok) { this.error = data.msg; this.saving = false; return; }

        window.dispatchEvent(new CustomEvent('registro-guardado', { detail:{ tipo:this.tipo, row:data, editar:!!this.id } }));
        this.open = false;
      } catch(e) { this.error = 'Error de red. Intenta nuevamente.'; }
      this.saving = false;
    },
  };
}

// ── Tab CC.PP y CC.NN: Modal Exportar PDF ───────────────────────────────────
function modalExportarJur() {
  return {
    open:false, tipo:'ccpp', rows:[], ws:{ region:'', provincia:'' }, pdfLoading:false,

    get titulo() {
      return this.tipo === 'ccpp' ? 'Centros Poblados' : 'Comunidades Nativas';
    },

    abrir(d) {
      this.tipo = d.tipo; this.rows = d.rows; this.ws = d.ws;
      this.pdfLoading = false; this.open = true;
    },

    fechaHoy() {
      return new Date().toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' });
    },

    imprimir() {
      const color = this.tipo === 'ccpp' ? '#059669' : '#d97706';
      const colorLight = this.tipo === 'ccpp' ? '#ecfdf5' : '#fffbeb';
      const filas = this.rows.map((r,i) =>
        `<tr style="background:${i%2===0?'#fff':colorLight}">
          <td>${i+1}</td><td><strong>${r.nombre}</strong></td>
          <td>${r.distrito||'—'}</td><td>${r.provincia||'—'}</td><td>${r.region||'—'}</td>
        </tr>`
      ).join('');

      const html = `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
        <title>${this.titulo} - ${this.ws.provincia}</title>
        <style>
          *{margin:0;padding:0;box-sizing:border-box}
          body{font-family:Arial,sans-serif;font-size:11px;color:#1f2937;padding:20px}
          .header{background:${color};color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;margin-bottom:0}
          .header h1{font-size:18px;font-weight:900;margin-bottom:2px}
          .header p{font-size:11px;opacity:.8}
          .meta{background:#f9fafb;border:1px solid #e5e7eb;border-top:none;padding:8px 20px;display:flex;justify-content:space-between;margin-bottom:16px;border-radius:0 0 4px 4px}
          .meta span{font-size:10px;color:#6b7280}
          table{width:100%;border-collapse:collapse}
          thead tr{background:${color};color:#fff}
          th{padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em}
          td{padding:7px 10px;border-bottom:1px solid #f3f4f6}
          .footer{margin-top:16px;text-align:center;font-size:9px;color:#9ca3af}
          @media print{body{padding:10px}.footer{position:fixed;bottom:10px;width:100%;text-align:center}}
        </style></head><body>
        <div class="header">
          <h1>${this.titulo}</h1>
          <p>Provincia de ${this.ws.provincia}, ${this.ws.region}</p>
        </div>
        <div class="meta">
          <span>Total: <strong>${this.rows.length} registros</strong></span>
          <span>Generado: ${this.fechaHoy()}</span>
        </div>
        <table>
          <thead><tr><th>#</th><th>Nombre</th><th>Distrito</th><th>Provincia</th><th>Región</th></tr></thead>
          <tbody>${filas}</tbody>
        </table>
        <div class="footer">${(window.BRAND_NAME || 'Credenciales App')} · Sistema de Gestión de Credenciales</div>
        <script>window.onload=()=>{ window.print(); }<\/script>
        </body></html>`;

      const w = window.open('', '_blank', 'width=900,height=700');
      if (w) { w.document.write(html); w.document.close(); }
    },

    async descargarPDF() {
      this.pdfLoading = true;
      try {
        if (!window.jspdf) await this._loadScripts();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation:'landscape', unit:'mm', format:'a4' });

        // Cabecera del PDF
        const [r,g,b] = this.tipo==='ccpp' ? [5,150,105] : [217,119,6];
        doc.setFillColor(r,g,b);
        doc.rect(0, 0, 297, 28, 'F');
        doc.setFont('helvetica','bold');
        doc.setFontSize(16);
        doc.setTextColor(255,255,255);
        doc.text(this.titulo, 14, 12);
        doc.setFontSize(9);
        doc.setFont('helvetica','normal');
        doc.text(`Provincia de ${this.ws.provincia}, ${this.ws.region}`, 14, 19);
        doc.text(`Generado: ${this.fechaHoy()} · Total: ${this.rows.length} registros`, 14, 24);

        // Tabla
        doc.autoTable({
          startY: 32,
          head: [['#','Nombre','Distrito','Provincia','Región']],
          body: this.rows.map((row,i) => [i+1, row.nombre, row.distrito||'—', row.provincia||'—', row.region||'—']),
          styles: { fontSize:9, cellPadding:4, font:'helvetica' },
          headStyles: { fillColor:[r,g,b], textColor:255, fontStyle:'bold' },
          alternateRowStyles: { fillColor:[248,250,252] },
          columnStyles: { 0:{ cellWidth:10, halign:'center' } },
          margin: { left:14, right:14 },
        });

        // Footer en cada página
        const totalPags = doc.internal.getNumberOfPages();
        for (let i=1; i<=totalPags; i++) {
          doc.setPage(i);
          doc.setFontSize(8);
          doc.setTextColor(150,150,150);
          doc.text((window.BRAND_NAME || 'Credenciales App') + ' · Sistema de Gestión de Credenciales', 14, doc.internal.pageSize.height - 8);
          doc.text(`Página ${i} de ${totalPags}`, 297-14, doc.internal.pageSize.height - 8, { align:'right' });
        }

        const fname = (this.tipo==='ccpp' ? 'centros-poblados' : 'comunidades-nativas')
                    + '-' + this.ws.provincia.toLowerCase().replace(/\s+/g,'-') + '.pdf';
        doc.save(fname);
      } catch(e) {
        alert('Error al generar el PDF. Verifica tu conexión a internet.');
        console.error(e);
      }
      this.pdfLoading = false;
    },

    async _loadScripts() {
      const load = src => new Promise((res,rej) => {
        if (document.querySelector(`script[src="${src}"]`)) { res(); return; }
        const s = document.createElement('script');
        s.src = src; s.onload = res; s.onerror = rej;
        document.head.appendChild(s);
      });
      await load('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
      await load('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js');
    },
  };
}

// ── Tab CC.PP y CC.NN: Modal Eliminar ───────────────────────────────────────
function modalEliminarJur() {
  return {
    open:false, tipo:'', id:0, nombre:'', saving:false,
    abrir(d) { this.tipo=d.tipo; this.id=d.id; this.nombre=d.nombre; this.saving=false; this.open=true; },
    async eliminar() {
      this.saving = true;
      try {
        const fd = new FormData();
        fd.append('_csrf',  CSRF_TOKEN);
        fd.append('action', 'jur_eliminar');
        fd.append('tabla',  this.tipo);
        fd.append('id',     this.id);
        const data = await (await fetch('credenciales-modulo.php', { method:'POST', body:fd })).json();
        if (data.ok) {
          window.dispatchEvent(new CustomEvent('registro-eliminado', { detail:{ tipo:this.tipo, id:this.id } }));
          this.open = false;
        }
      } catch(e) {}
      this.saving = false;
    },
  };
}

// ── Tab Configurar PDF Credencial: App principal ────────────────────────────
function configPdfApp() {
  return {
    subTab: 'plantilla',
    loading: true,
    saving: false,
    msg: '',
    msgOk: true,
    cfg: {
      plantilla_a4: '', plantilla_reverso_a4: '', mensaje_partido: '', titulo_credencial: 'CREDENCIAL',
      titulo_font_family: 'DejaVu Sans', titulo_font_size: 28, titulo_font_weight: 900,
      titulo_italic: 1, titulo_text_color: '#FFFFFF', titulo_bg_color: '#1E3A8A',
      titulo_banner_height: 13, titulo_radius: 2, titulo_letter_spacing: 2,
      codigo_sufijo: 'IVSIS', tamanos: ['a4'],
      texto1: '', texto3: '', texto4_ciudad: '', nombre_font_size: 13,
      num_firmas: 2, firmas: [{nombre:'',cargo:'',imagen:''},{nombre:'',cargo:'',imagen:''},{nombre:'',cargo:'',imagen:''}],
    },
    plantillaPreview: '',
    reversoPreview: '',
    firmaPreviews: ['','',''],
    texto3Editor: null,
    placeholders: [
      { label: 'Nombres', token: '{{nombres}}' },
      { label: 'DNI', token: '{{dni}}' },
      { label: 'Cargo', token: '{{cargo}}' },
      { label: 'CC.PP / CC.NN', token: '{{ccpp_ccnn}}' },
      { label: 'Distrito', token: '{{distrito}}' },
      { label: 'Provincia', token: '{{provincia}}' },
      { label: 'Región', token: '{{region}}' },
      { label: 'Correo', token: '{{correo}}' },
      { label: 'Celular', token: '{{celular}}' },
      { label: 'Código', token: '{{codigo}}' },
      { label: 'Emisión', token: '{{fecha_emision}}' },
      { label: 'Vencimiento', token: '{{fecha_vencimiento}}' },
      { label: 'Ciudad', token: '{{ciudad}}' },
      { label: 'Fecha', token: '{{fecha}}' },
    ],
    pdfModal: { open:false, url:'', titulo:'', formato:'a4' },

    async cargar() {
      this.loading = true;
      try {
        const data = await (await fetch('credenciales-modulo.php?cfg_pdf_get=1')).json();
        if (data.ok) {
          this.cfg = data.cfg;
          if (this.cfg.plantilla_a4) this.plantillaPreview = BASE_URL_JS + '/' + this.cfg.plantilla_a4;
          if (this.cfg.plantilla_reverso_a4) this.reversoPreview = BASE_URL_JS + '/' + this.cfg.plantilla_reverso_a4;
          this.firmaPreviews = this.cfg.firmas.map(f => f.imagen ? (BASE_URL_JS + '/' + f.imagen) : '');
        }
      } catch (e) {}
      this.loading = false;
      setTimeout(() => this.initTexto3Editor(), 0);
    },

    initTexto3Editor() {
      if (this.texto3Editor || typeof Quill === 'undefined') return;
      const editorEl = document.getElementById('texto3-editor');
      if (!editorEl) return;
      this.texto3Editor = new Quill(editorEl, {
        theme: 'snow',
        placeholder: 'Redacta el texto de facultades e inserta campos como {{dni}}, {{cargo}}...',
        modules: {
          toolbar: [
            ['bold', 'italic', 'underline'],
            [{ size: ['small', false, 'large'] }],
            [{ color: [] }],
            ['clean']
          ]
        }
      });
      this.texto3Editor.root.innerHTML = this.cfg.texto3 || '';
      this.texto3Editor.on('text-change', () => {
        this.cfg.texto3 = this.texto3Editor.root.innerHTML;
      });
    },

    insertPlaceholder(token) {
      this.initTexto3Editor();
      if (!this.texto3Editor) return;
      const range = this.texto3Editor.getSelection(true) || { index: this.texto3Editor.getLength(), length: 0 };
      this.texto3Editor.insertText(range.index, token, 'user');
      this.texto3Editor.setSelection(range.index + token.length, 0, 'user');
      this.cfg.texto3 = this.texto3Editor.root.innerHTML;
    },

    insertCustomPlaceholder() {
      const campo = prompt('Nombre del campo, sin llaves. Ejemplo: celular');
      if (!campo) return;
      const limpio = campo.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (!limpio) return;
      this.insertPlaceholder('{{' + limpio + '}}');
    },

    fechaHoy() {
      const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
      const d = new Date();
      return d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
    },

    onFile(ev, tipo, idx) {
      const file = ev.target.files[0];
      if (!file) return;
      const url = URL.createObjectURL(file);
      if (tipo === 'plantilla') this.plantillaPreview = url;
      else if (tipo === 'reverso') this.reversoPreview = url;
      else { this.firmaPreviews[idx] = url; this.cfg.firmas[idx]._file = file; }
    },

    async guardar() {
      this.saving = true;
      this.msg = '';
      try {
        if (this.texto3Editor) this.cfg.texto3 = this.texto3Editor.root.innerHTML;
        const fd = new FormData();
        fd.append('action', 'guardar_cfg_pdf');
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('mensaje_partido', this.cfg.mensaje_partido);
        fd.append('titulo_credencial', this.cfg.titulo_credencial);
        fd.append('titulo_font_family', this.cfg.titulo_font_family);
        fd.append('titulo_font_size', this.cfg.titulo_font_size);
        fd.append('titulo_font_weight', this.cfg.titulo_font_weight);
        fd.append('titulo_italic', this.cfg.titulo_italic ? '1' : '');
        fd.append('titulo_text_color', this.cfg.titulo_text_color);
        fd.append('titulo_bg_color', this.cfg.titulo_bg_color);
        fd.append('titulo_banner_height', this.cfg.titulo_banner_height);
        fd.append('titulo_radius', this.cfg.titulo_radius);
        fd.append('titulo_letter_spacing', this.cfg.titulo_letter_spacing);
        fd.append('codigo_sufijo', this.cfg.codigo_sufijo);
        fd.append('texto1', this.cfg.texto1);
        fd.append('nombre_font_size', this.cfg.nombre_font_size);
        fd.append('texto3', this.cfg.texto3);
        fd.append('texto4_ciudad', this.cfg.texto4_ciudad);
        fd.append('num_firmas', this.cfg.num_firmas);
        this.cfg.tamanos.forEach(t => fd.append('tamanos[]', t));
        this.cfg.firmas.forEach((f, i) => {
          fd.append(`firmas[${i}][nombre]`, f.nombre);
          fd.append(`firmas[${i}][cargo]`, f.cargo);
        });
        if (this.$refs.plantillaInput && this.$refs.plantillaInput.files[0]) {
          fd.append('plantilla_a4', this.$refs.plantillaInput.files[0]);
        }
        if (this.$refs.reversoInput && this.$refs.reversoInput.files[0]) {
          fd.append('plantilla_reverso_a4', this.$refs.reversoInput.files[0]);
        }
        for (let i = 0; i < 3; i++) {
          if (this.cfg.firmas[i]._file) fd.append('firma_imagen_' + i, this.cfg.firmas[i]._file);
        }
        const res = await fetch('credenciales-modulo.php', { method: 'POST', body: fd });
        const data = await res.json();
        this.msgOk = !!data.ok;
        this.msg = data.msg || (data.ok ? 'Guardado.' : 'Error al guardar.');
        if (data.ok && data.cfg) {
          this.cfg = data.cfg;
          if (this.texto3Editor) this.texto3Editor.root.innerHTML = this.cfg.texto3 || '';
          if (this.cfg.plantilla_a4) this.plantillaPreview = BASE_URL_JS + '/' + this.cfg.plantilla_a4 + '?t=' + Date.now();
          if (this.cfg.plantilla_reverso_a4) this.reversoPreview = BASE_URL_JS + '/' + this.cfg.plantilla_reverso_a4 + '?t=' + Date.now();
          this.firmaPreviews = this.cfg.firmas.map(f => f.imagen ? (BASE_URL_JS + '/' + f.imagen + '?t=' + Date.now()) : '');
        }
        this.saving = false;
        setTimeout(() => { this.msg = ''; }, 4000);
        return !!data.ok;
      } catch (e) {
        this.msgOk = false;
        this.msg = 'Error de conexión.';
      }
      this.saving = false;
      setTimeout(() => { this.msg = ''; }, 4000);
      return false;
    },

    async vistaPrevia(formato) {
      this.saving = true;
      this.msg = '';
      try {
        if (this.texto3Editor) this.cfg.texto3 = this.texto3Editor.root.innerHTML;
        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('mensaje_partido', this.cfg.mensaje_partido);
        fd.append('titulo_credencial', this.cfg.titulo_credencial);
        fd.append('titulo_font_family', this.cfg.titulo_font_family);
        fd.append('titulo_font_size', this.cfg.titulo_font_size);
        fd.append('titulo_font_weight', this.cfg.titulo_font_weight);
        fd.append('titulo_italic', this.cfg.titulo_italic ? '1' : '');
        fd.append('titulo_text_color', this.cfg.titulo_text_color);
        fd.append('titulo_bg_color', this.cfg.titulo_bg_color);
        fd.append('titulo_banner_height', this.cfg.titulo_banner_height);
        fd.append('titulo_radius', this.cfg.titulo_radius);
        fd.append('titulo_letter_spacing', this.cfg.titulo_letter_spacing);
        fd.append('codigo_sufijo', this.cfg.codigo_sufijo);
        fd.append('texto1', this.cfg.texto1);
        fd.append('nombre_font_size', this.cfg.nombre_font_size);
        fd.append('texto3', this.cfg.texto3);
        fd.append('texto4_ciudad', this.cfg.texto4_ciudad);
        fd.append('num_firmas', this.cfg.num_firmas);
        this.cfg.tamanos.forEach(t => fd.append('tamanos[]', t));
        this.cfg.firmas.forEach((f, i) => {
          fd.append(`firmas[${i}][nombre]`, f.nombre);
          fd.append(`firmas[${i}][cargo]`, f.cargo);
        });
        await fetch('credenciales-modulo.php?preview_cfg_set=1', { method: 'POST', body: fd });
      } catch(e) {}
      this.saving = false;
      this.pdfModal = {
        open: true,
        url: 'exportar-credencial-pdf.php?preview=1&formato=' + formato + '&_=' + Date.now(),
        titulo: (formato === 'a4' ? 'Vista previa A4' : 'Vista previa Carnet 67×95mm'),
        formato: formato,
      };
    },

    cerrarVistaPreviaPdf() {
      if (this.$refs.pdfPreviewFrame) this.$refs.pdfPreviewFrame.src = '';
      this.pdfModal.open = false;
    },

    imprimirVistaPreviaPdf() {
      const frame = this.$refs.pdfPreviewFrame;
      if (!frame || !frame.contentWindow) {
        window.open(this.pdfModal.url, '_blank', 'noopener');
        return;
      }
      try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      } catch (e) {
        window.open(this.pdfModal.url, '_blank', 'noopener');
      }
    },
  };
}
</script>

    </main>
  </div>
</body>
</html>
