<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/credenciales.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

require_login();
require_modulo($pdo, 'credenciales_modulo');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

use Dompdf\Dompdf;
use Dompdf\Options;

$id       = (int)($_GET['id'] ?? 0);
$formato  = ($_GET['formato'] ?? 'a4') === 'carnet' ? 'carnet' : 'a4';
$download = (int)($_GET['download'] ?? 0) === 1;
$preview  = (int)($_GET['preview'] ?? 0) === 1;
$save_public = (int)($_GET['save'] ?? 0) === 1;

if ($preview) {
    // Vista previa: usa el registro real más reciente como muestra
    $c = $pdo->query("SELECT * FROM credenciales ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        // Sin registros aún: datos de ejemplo de respaldo
        $c = [
            'codigo'             => '0001',
            'nombres_completos'  => 'JUAN CARLOS PÉREZ GARCÍA',
            'dni'                => '12345678',
            'cargo'              => 'Coordinador Distrital',
            'correo'             => 'ejemplo@correo.com',
            'direccion'          => 'Jr. Ejemplo 123',
            'centro_poblado'     => 'Centro Poblado Modelo',
            'comunidad_nativa'   => '',
            'distrito'           => 'Río Tambo',
            'provincia'          => 'Satipo',
            'region'             => 'Junín',
            'fecha_emision'      => date('Y-m-d'),
            'fecha_vencimiento'  => date('Y-m-d', strtotime('+1 year')),
            'estado'             => 'activo',
            'foto'               => '',
            'qr_token'           => '',
        ];
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM credenciales WHERE id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        http_response_code(404);
        echo 'Credencial no encontrada.';
        exit;
    }
}

// ── Datos del partido / branding ──────────────────────────────
function cfg_get(PDO $pdo, string $clave, string $default = ''): string {
    try {
        $v = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ? LIMIT 1");
        $v->execute([$clave]);
        $val = $v->fetchColumn();
        return $val !== false && $val !== null && $val !== '' ? $val : $default;
    } catch (Exception $e) { return $default; }
}

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

function font_file_uri(string $file): string {
    $path = realpath(dirname(__DIR__) . '/assets/fonts/tittle/' . $file);
    if (!$path) return '';
    return 'file://' . str_replace('\\', '/', $path);
}

// Lee ascent/descent (en em, relativos al tamaño de fuente) de un archivo TTF.
// Se usan para calcular el ajuste vertical del texto del banner CREDENCIAL,
// ya que distintas fuentes tienen distinta proporcion de espacio vacio
// arriba/abajo de las mayusculas dentro de su caja de linea.
function titulo_font_ascent_descent(string $ttfPath): array {
    $fallback = [0.928, 0.236]; // DejaVu Sans Bold
    try {
        $font = \FontLib\Font::load($ttfPath);
        $font->parse();
        $upm = $font->getData('head', 'unitsPerEm');
        $ascent = $font->getData('hhea', 'ascent');
        $descent = $font->getData('hhea', 'descent');
        if (!$upm || !$ascent) return $fallback;
        return [$ascent / $upm, abs($descent) / $upm];
    } catch (\Throwable $e) {
        return $fallback;
    }
}

$partido_nombre  = strtoupper(cfg_get($pdo, 'partido_nombre', 'ALIANZA PARA EL PROGRESO'));
$color_primario  = cfg_get($pdo, 'partido_color_primario', '#1E3A8A');
$color_acento    = cfg_get($pdo, 'partido_color_acento', '#FACC15');

// ── Configuración del PDF de credencial ────────────────────────
function cfg_pdf_default(): array {
    return [
        'plantilla_a4'     => '',
        'plantilla_reverso_a4' => '',
        'mensaje_partido'  => '',
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
        'texto4_ciudad'    => '',
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
// En preview, si hay config temporal en session (del editor sin guardar), úsala
if ($preview && !empty($_SESSION['preview_cfg_tmp']) && is_array($_SESSION['preview_cfg_tmp'])) {
    $cfg = $_SESSION['preview_cfg_tmp'];
    unset($_SESSION['preview_cfg_tmp']);
} else {
    $cfg = cfg_pdf_get($pdo);
}

// ── Helpers de imagen → data URI (dompdf trabaja mejor con base64) ─
function img_to_data_uri(string $path_or_url): string {
    $bytes = false;
    if (preg_match('#^https?://#i', $path_or_url)) {
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $bytes = @file_get_contents($path_or_url, false, $ctx);
    } else {
        $bytes = is_file($path_or_url) ? @file_get_contents($path_or_url) : false;
    }
    if ($bytes === false || $bytes === '') return '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($bytes) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

$foto_uri = '';
if (!empty($c['foto'])) {
    $foto_uri = img_to_data_uri(dirname(__DIR__) . '/' . $c['foto']);
}
if ($foto_uri === '') {
    $silueta = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#E5E7EB"/><circle cx="50" cy="38" r="18" fill="#9CA3AF"/><path d="M20 90c0-19 13-32 30-32s30 13 30 32" fill="#9CA3AF"/></svg>';
    $foto_uri = 'data:image/svg+xml;base64,' . base64_encode($silueta);
}

$plantilla_uri = '';
if (!empty($cfg['plantilla_a4'])) {
    $plantilla_uri = img_to_data_uri(dirname(__DIR__) . '/' . $cfg['plantilla_a4']);
}

$plantilla_reverso_uri = '';
if (!empty($cfg['plantilla_reverso_a4'])) {
    $plantilla_reverso_uri = img_to_data_uri(dirname(__DIR__) . '/' . $cfg['plantilla_reverso_a4']);
}
$carnet_duplex = $formato === 'carnet' && $plantilla_reverso_uri !== '';

$qr_path = !empty($c['qr_token']) ? dirname(__DIR__) . '/uploads/credenciales/qr/qr_' . $c['qr_token'] . '.png' : '';
$qr_uri  = $qr_path && is_file($qr_path) ? img_to_data_uri($qr_path) : '';

// ── Helpers de presentación ───────────────────────────────────
function h(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fecha_larga_es(): string {
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return (int)date('j') . ' de ' . $meses[(int)date('n') - 1] . ' de ' . date('Y');
}
function reemplazar_placeholders(string $texto, array $c, string $ciudad): string {
    $ccpp_ccnn = trim((string)($c['centro_poblado'] ?? '')) ?: trim((string)($c['comunidad_nativa'] ?? ''));
    $map = [
        '{{nombres}}'   => $c['nombres_completos'] ?? '',
        '{{dni}}'       => $c['dni'] ?? '',
        '{{cargo}}'     => $c['cargo'] ?: '—',
        '{{ccpp_ccnn}}' => $ccpp_ccnn ?: '—',
        '{{distrito}}'  => $c['distrito'] ?? '—',
        '{{provincia}}' => $c['provincia'] ?? '—',
        '{{region}}'    => $c['region'] ?? '—',
        '{{correo}}'    => $c['correo'] ?? '—',
        '{{celular}}'   => $c['celular'] ?? '—',
        '{{whatsapp}}'  => $c['whatsapp'] ?? '—',
        '{{direccion}}' => $c['direccion'] ?? '—',
        '{{codigo}}'    => $c['codigo'] ?? '—',
        '{{fecha_emision}}' => fecha_es($c['fecha_emision'] ?? null),
        '{{fecha_vencimiento}}' => fecha_es($c['fecha_vencimiento'] ?? null),
        '{{ciudad}}'    => $ciudad ?: '—',
        '{{fecha}}'     => fecha_larga_es(),
    ];
    return strtr($texto, $map);
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

function uppercase_html_text(string $html): string {
    $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) return mb_strtoupper($html, 'UTF-8');
    foreach ($parts as &$part) {
        if ($part !== '' && $part[0] !== '<') {
            $part = mb_strtoupper($part, 'UTF-8');
        }
    }
    unset($part);
    return implode('', $parts);
}

$estado_label = match ($c['estado']) {
    'activo'  => 'ACTIVO',
    'vencido' => 'VENCIDO',
    'anulado' => 'ANULADO',
    default   => strtoupper($c['estado']),
};
$estado_color = match ($c['estado']) {
    'activo'  => '#059669',
    'vencido' => '#D97706',
    'anulado' => '#DC2626',
    default   => '#6B7280',
};

$titulo_credencial = $cfg['titulo_credencial'] ?: 'CREDENCIAL';
$titulo_font_catalog = titulo_font_catalog();
$titulo_font_family = array_key_exists(($cfg['titulo_font_family'] ?? 'DejaVu Sans'), $titulo_font_catalog)
    ? $cfg['titulo_font_family'] : 'DejaVu Sans';
$titulo_font_file = $titulo_font_catalog[$titulo_font_family] ?? '';
$titulo_uses_custom_font = $titulo_font_file !== '';
$titulo_font_size = max(16, min(42, (float)($cfg['titulo_font_size'] ?? 28)));
$titulo_font_weight = in_array((int)($cfg['titulo_font_weight'] ?? 900), [600, 700, 800, 900], true)
    ? (int)$cfg['titulo_font_weight'] : 900;
$titulo_italic = !empty($cfg['titulo_italic']);
$titulo_text_color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($cfg['titulo_text_color'] ?? ''))
    ? (string)$cfg['titulo_text_color'] : '#FFFFFF';
$titulo_bg_color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($cfg['titulo_bg_color'] ?? ''))
    ? (string)$cfg['titulo_bg_color'] : $color_primario;
$titulo_banner_height = max(10, min(20, (float)($cfg['titulo_banner_height'] ?? 13)));
$titulo_radius = max(0, min(8, (float)($cfg['titulo_radius'] ?? 2)));
$titulo_letter_spacing = max(0, min(6, (float)($cfg['titulo_letter_spacing'] ?? 2)));
$mensaje_partido   = $cfg['mensaje_partido'] ?? '';
$texto1 = reemplazar_placeholders($cfg['texto1'], $c, $cfg['texto4_ciudad']);
$texto3_html = uppercase_html_text(sanitize_credencial_rich_html(reemplazar_placeholders($cfg['texto3'], $c, $cfg['texto4_ciudad'])));
$texto3 = trim(strip_tags($texto3_html));
$texto4 = trim(($cfg['texto4_ciudad'] ?: '') . ', ' . fecha_larga_es(), ', ');

$num_firmas = max(1, min(3, (int)$cfg['num_firmas']));
$firmas = array_slice($cfg['firmas'], 0, $num_firmas);
foreach ($firmas as &$f) {
    $f['imagen_uri'] = !empty($f['imagen']) ? img_to_data_uri(dirname(__DIR__) . '/' . $f['imagen']) : '';
}
unset($f);

// ── Escala para formato Carnet (mismo documento A4 reducido a 67×95mm) ──
$escala = $formato === 'carnet' ? round(67 / 210, 5) : 1;
$qr_overlay_size = $formato === 'carnet' ? round((45 * $escala) + 5, 4) . 'mm' : null;
$qr_fallback_size = $formato === 'carnet' ? round((34 * $escala) + 5, 4) . 'mm' : null;
// Convierte un valor en mm (base A4) a la unidad escalada para el formato actual.
function em(float $mm): string {
    global $escala;
    return round($mm * $escala, 4) . 'mm';
}
// Convierte un valor en px (base A4) a la unidad escalada para el formato actual.
function ep(float $px): string {
    global $escala;
    return round($px * $escala, 3) . 'px';
}
function ep_carnet_plus(float $px, float $plus = 2): string {
    global $escala, $formato;
    return round(($px * $escala) + ($formato === 'carnet' ? $plus : 0), 3) . 'px';
}

// Permite agrandar solo el nombre dentro de "AL SEÑOR(A): {{nombres}}" sin tocar el resto del texto.
$nombre_font_size = max(10, min(24, (float)($cfg['nombre_font_size'] ?? 13)));
$texto1_sentinel = "\x00NOMBRE\x00";
$texto1_resolved = reemplazar_placeholders(str_replace('{{nombres}}', $texto1_sentinel, $cfg['texto1']), $c, $cfg['texto4_ciudad']);
$nombre_span = sprintf('<span style="font-size:%s;font-weight:800;">%s</span>', ep($nombre_font_size), h($c['nombres_completos'] ?? ''));
$texto1_html = str_replace($texto1_sentinel, $nombre_span, h($texto1_resolved));

$titulo_pdf_weight = $titulo_uses_custom_font ? 'normal' : ($titulo_font_weight >= 700 ? 'bold' : 'normal');
$titulo_pdf_style = $titulo_uses_custom_font ? 'normal' : ($titulo_italic ? 'italic' : 'normal');
$titulo_box_style_inline = sprintf(
    'background:%s;border-radius:%s;height:%s;line-height:%s;text-align:center;',
    h($titulo_bg_color),
    em($titulo_radius),
    em($titulo_banner_height),
    em($titulo_banner_height)
);
$titulo_text_style_inline = sprintf(
    "font-family:'%s','DejaVu Sans',sans-serif;font-size:%s;font-weight:%s;font-style:%s;color:%s;letter-spacing:%s;text-transform:uppercase;white-space:nowrap;",
    h($titulo_font_family),
    round($titulo_font_size * $escala, 3) . 'pt',
    $titulo_pdf_weight,
    $titulo_pdf_style,
    h($titulo_text_color),
    ep($titulo_letter_spacing)
);

// Banner sobre plantilla (.ov-banner): centrado real con display:table /
// table-cell;vertical-align:middle, que en Dompdf centra el texto dentro de
// la celda sin necesidad de desplazamientos calibrados a mano por fuente.
// El alto de la banda se calcula a partir de las metricas reales (ascent +
// descent) del TTF usado, para que se ajuste tanto a fuentes grandes como
// pequeñas y nunca quede mas chico que el texto ni desproporcionado.
$titulo_ttf_path = $titulo_uses_custom_font
    ? dirname(__DIR__) . '/assets/fonts/tittle/' . $titulo_font_file
    : dirname(__DIR__) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
[$titulo_ascent_em, $titulo_descent_em] = titulo_font_ascent_descent($titulo_ttf_path);
// 1.32 ~ factor de "line-height normal" que aplica Dompdf sobre ascent+descent.
$titulo_banner_natural_mm = ($titulo_ascent_em + $titulo_descent_em) * 1.32 * $titulo_font_size * 0.352778;
$titulo_banner_height_fit = max($titulo_banner_height, round($titulo_banner_natural_mm + 1, 2));
$titulo_codigo_top = 86 + $titulo_banner_height_fit + 4;

// Nota: .ov tiene overflow:hidden; combinar overflow:hidden + display:table en el
// MISMO elemento hace que Dompdf no renderice el contenido. Por eso el display:table
// va en un div interno, con el mismo alto explicito (sin porcentajes) que el externo.
$titulo_banner_box_style = sprintf(
    'background:%s;border-radius:%s;height:%s;',
    h($titulo_bg_color),
    em($titulo_radius),
    em($titulo_banner_height_fit)
);
$titulo_banner_table_style = sprintf('display:table;width:100%%;height:%s;', em($titulo_banner_height_fit));
$titulo_banner_cell_style = 'display:table-cell;vertical-align:middle;text-align:center;';
// El reset universal `* { font-family: ... }` tiene mayor prioridad que la herencia,
// por lo que las propiedades de fuente deben declararse explicitamente en el <span>
// del texto (no basta con heredarlas del contenedor .ov-banner).
$titulo_banner_text_style = sprintf(
    "font-family:'%s','DejaVu Sans',sans-serif;font-size:%s;font-weight:%s;font-style:%s;color:%s;letter-spacing:%s;text-transform:uppercase;",
    h($titulo_font_family),
    round($titulo_font_size * $escala, 3) . 'pt',
    $titulo_pdf_weight,
    $titulo_pdf_style,
    h($titulo_text_color),
    ep($titulo_letter_spacing)
);

// ════════════════════════════════════════════════════════════
// HTML del documento
// ════════════════════════════════════════════════════════════
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  <?php foreach ($titulo_font_catalog as $fontName => $fontFile): ?>
  <?php if ($fontFile !== ''): ?>
  <?php $fontUri = font_file_uri($fontFile); ?>
  <?php if ($fontUri !== ''): ?>
  @font-face {
    font-family: '<?= h($fontName) ?>';
    src: url('<?= h($fontUri) ?>');
    font-weight: normal;
    font-style: normal;
  }
  <?php endif; ?>
  <?php endif; ?>
  <?php endforeach; ?>
  @page { size: <?= $carnet_duplex ? '134mm 95mm' : ($formato === 'carnet' ? '67mm 95mm' : 'A4') ?>; margin: 0; }
  * { box-sizing: border-box; font-family: 'DejaVu Sans', Arial, sans-serif; }
  body { margin: 0; padding: 0; <?php if ($carnet_duplex): ?>width: 134mm; height: 95mm; overflow: hidden;<?php endif; ?> }

  .pagina {
    width: <?= em(210) ?>;
    height: <?= em(297) ?>;
    overflow: hidden; position: relative;
    <?php if ($carnet_duplex): ?>
    float: left;
    page-break-after: auto;
    <?php else: ?>
    page-break-after: always;
    <?php endif; ?>
  }
  .pagina:last-child { page-break-after: auto; }
  .hoja {
    width: <?= em(210) ?>; height: <?= em(297) ?>; position: relative;
  }
  .plantilla-fondo {
    position: absolute;
    left: 0;
    top: 0;
    width: <?= em(210) ?>;
    height: <?= em(297) ?>;
    z-index: 0;
  }
  .hoja-reverso {
    width: <?= em(210) ?>; height: <?= em(297) ?>; position: relative;
    background-color: #ffffff;
  }
  .hoja-reverso img {
    position: absolute;
    left: 0;
    top: <?= em(297) ?>;
    width: <?= em(297) ?>;
    height: <?= em(210) ?>;
    transform-origin: top left;
    transform: rotate(-90deg);
  }
  <?php if (!$plantilla_uri): ?>
  .marca-agua {
    position: absolute; top: 130mm; left: 0; right: 0; text-align: center;
    transform: rotate(-22deg);
    font-size: 56px; font-weight: 900; color: rgba(30,58,138,0.06);
    letter-spacing: 6px; white-space: nowrap;
  }
  .borde { position: absolute; top: 12mm; left: 12mm; right: 12mm; bottom: 12mm; border: 2.5px solid <?= h($color_primario) ?>; border-radius: 10px; }
  <?php endif; ?>

  .contenido {
    position: relative; z-index: 1;
    padding: 20mm 22mm;
  }

  /* ── Overlay sobre plantilla (grid base div.svg, posiciones absolutas en mm 210x297) ── */
  .ov { position: absolute; overflow: hidden; z-index: 1; }

  /* Celda B: foto del portador */
  .ov-foto {
    left: <?= em(159) ?>; top: <?= em(64) ?>; width: <?= em(35) ?>; height: <?= em(45) ?>;
    border: <?= ep(3) ?> solid #1E3A8A; padding: <?= ep(2) ?>; background: #fff;
  }
  .ov-foto img { width: 100%; height: 100%; object-fit: cover; display: block; }

  /* Celda C: mensaje del partido (Arial 14, centrado) — limitado a la columna izquierda (no invade foto/QR) */
  .ov-mensaje {
    left: <?= em(25) ?>; top: <?= em(59) ?>; width: <?= em(125.2) ?>; height: <?= em(25) ?>;
    display: table; text-align: center; font-family: Arial, 'DejaVu Sans', sans-serif;
    font-size: <?= ep(14) ?>; font-weight: 700; color: #1f2937; line-height: 1.4;
  }
  .ov-mensaje > div { display: table-cell; vertical-align: middle; }

  /* Celda E: banner CREDENCIAL + código — limitado a la columna izquierda */
  /* Ancho reducido ~40px (10.58mm) por lado respecto a la columna (125.2mm) */
  .ov-banner {
    left: <?= em(35.58) ?>; top: <?= em(86) ?>; width: <?= em(104.04) ?>;
  }
  .ov-codigo-banner {
    left: <?= em(25) ?>; top: <?= em($titulo_codigo_top) ?>; width: <?= em(125.2) ?>; height: <?= em(6) ?>;
    text-align: center; font-size: <?= ep(11) ?>; font-weight: 700; color: #374151;
    font-family: 'DejaVu Sans Mono', monospace;
  }

  /* Celda F: cuerpo (AL SEÑOR(A), facultades, fecha) */
  .ov-cuerpo {
    left: <?= em(25) ?>; top: <?= em(117) ?>; width: <?= em(165) ?>; height: <?= em(84) ?>;
    font-size: <?= ep_carnet_plus(11) ?>; line-height: <?= $formato === 'carnet' ? '1.28' : '1.8' ?>; color: #1f2937; text-align: justify;
  }
  .ov-cuerpo .al-senor { font-size: <?= ep_carnet_plus(13) ?>; font-weight: 700; text-align: left; margin-bottom: <?= $formato === 'carnet' ? em(0.8) : em(2) ?>; }
  .ov-cuerpo .facultades { font-size: <?= ep_carnet_plus(14) ?>; line-height: <?= $formato === 'carnet' ? '1.32' : '1.85' ?>; }
  .ov-cuerpo .facultades p { margin: 0 0 <?= $formato === 'carnet' ? em(0.35) : em(1.5) ?> 0; }
  .ov-cuerpo .facultades .ql-size-small { font-size: 0.86em; }
  .ov-cuerpo .facultades .ql-size-large { font-size: 1.18em; }
  .ov-cuerpo .texto-fecha { font-size: <?= ep_carnet_plus(12) ?>; text-align: right; font-weight: 700; margin-top: <?= $formato === 'carnet' ? em(1.4) : em(4) ?>; color: #000000; text-transform: uppercase; }

  /* Celdas H/I/J: firmas + QR (QR al costado, ocupando la columna derecha) */
  .ov-firmas { left: <?= em(25) ?>; top: <?= em(211.8) ?>; width: <?= em(180) ?>; height: <?= em(85.5) ?>; }
  .ov-firmas-tabla { width: 100%; height: 100%; border-collapse: collapse; }
  .ov-firmas-tabla td { text-align: center; padding: 0 <?= $formato === 'carnet' ? em(3.5) : em(6) ?>; }
  .ov-firma { vertical-align: bottom; }
  .ov-firma img { max-width: <?= em(35) ?>; max-height: <?= $formato === 'carnet' ? em(15) : em(18) ?>; object-fit: contain; margin: 0 auto <?= $formato === 'carnet' ? '1px' : '2px' ?>; display: block; }
  .ov-firma .linea { border-top: 1.4px solid #9CA3AF; margin-top: <?= $formato === 'carnet' ? ep(9) : ep(18) ?>; padding-top: <?= $formato === 'carnet' ? '2px' : '4px' ?>; font-size: <?= ep_carnet_plus(10) ?>; line-height: <?= $formato === 'carnet' ? '1.05' : '1.2' ?>; font-weight: 700; color: #374151; }
  .ov-firma .cargo-firma { font-size: <?= ep_carnet_plus(9) ?>; line-height: <?= $formato === 'carnet' ? '1.05' : '1.2' ?>; color: #9CA3AF; }
  .ov-firmas-qr { vertical-align: middle; }
  .ov-firmas-qr img { width: <?= $qr_overlay_size ?: em(45) ?>; height: <?= $qr_overlay_size ?: em(45) ?>; }

  .encabezado { text-align: center; border-bottom: 3px solid <?= h($color_primario) ?>; padding-bottom: 8px; margin-bottom: 10px; }
  .encabezado .partido { font-size: 12px; font-weight: 700; color: <?= h($color_primario) ?>; letter-spacing: 1.5px; }
  .encabezado .titulo  {
    font-size: <?= round($titulo_font_size * $escala, 3) ?>pt; font-weight: <?= $titulo_pdf_weight ?>;
    color: <?= h($titulo_text_color) ?>; letter-spacing: <?= ep($titulo_letter_spacing) ?>; margin-top: 3px;
    font-style: <?= $titulo_pdf_style ?>; font-family: '<?= h($titulo_font_family) ?>', 'DejaVu Sans', sans-serif;
    background: <?= h($titulo_bg_color) ?>; border-radius: <?= em($titulo_radius) ?>; padding: 4px 10px;
    text-transform: uppercase;
  }
  .encabezado .codigo  { font-size: 12px; font-weight: 800; color: <?= h($color_primario) ?>; font-family: 'DejaVu Sans Mono', monospace; margin-top: 4px; }
  .encabezado .estado-badge {
    display: inline-block; margin-top: 6px; padding: 3px 12px; border-radius: 999px;
    font-size: 10px; font-weight: 800; letter-spacing: 1.5px; color: #fff; background: <?= h($estado_color) ?>;
  }

  .mensaje-partido { text-align: center; font-size: 10.5px; color: #374151; line-height: 1.6; padding: 8px 6mm; margin-bottom: 10px; }

  .cuerpo { display: table; width: 100%; }
  .col-foto { display: table-cell; width: 40mm; vertical-align: top; }
  .col-foto img { width: 36mm; height: 44mm; object-fit: cover; border: 3px solid <?= h($color_primario) ?>; border-radius: 6px; }
  .col-datos { display: table-cell; vertical-align: top; padding-left: 14px; }

  .nombre { font-size: <?= $formato === 'carnet' ? '21px' : '19px' ?>; font-weight: 900; color: #111827; line-height: 1.2; margin-bottom: 8px; }
  .texto-linea { font-size: <?= $formato === 'carnet' ? '13px' : '11px' ?>; font-weight: 700; color: #111827; margin-bottom: 6px; }
  .texto-parrafo { font-size: <?= $formato === 'carnet' ? '12.5px' : '10.5px' ?>; color: #374151; line-height: <?= $formato === 'carnet' ? '1.28' : '1.6' ?>; text-align: justify; margin-bottom: <?= $formato === 'carnet' ? '3px' : '6px' ?>; }
  .texto-parrafo p { margin: 0 0 <?= $formato === 'carnet' ? '2px' : '4px' ?> 0; }
  .texto-parrafo .ql-size-small { font-size: 0.86em; }
  .texto-parrafo .ql-size-large { font-size: 1.18em; }
  .texto-fecha { font-size: <?= $formato === 'carnet' ? '12.5px' : '10.5px' ?>; font-weight: 700; color: <?= h($color_primario) ?>; margin-top: 8px; }

  .pie { display: table; width: 100%; margin-top: 24px; }
  .col-qr { display: table-cell; width: 30mm; vertical-align: bottom; text-align: center; }
  .col-qr img { width: <?= $qr_fallback_size ?: '34mm' ?>; height: <?= $qr_fallback_size ?: '34mm' ?>; }
  .col-qr p { font-size: 7px; color: #6B7280; margin: 4px 4mm 0 0; line-height: 1.4; }
  .col-firmas { display: table-cell; vertical-align: bottom; }
  .firmas-tabla { width: 100%; }
  .firma { display: table-cell; text-align: center; padding: 0 <?= $formato === 'carnet' ? '3px' : '6px' ?>; }
  .firma img { max-width: 28mm; max-height: <?= $formato === 'carnet' ? '12mm' : '14mm' ?>; object-fit: contain; margin-bottom: <?= $formato === 'carnet' ? '1px' : '2px' ?>; }
  .firma .linea { border-top: 1.4px solid #9CA3AF; margin-top: <?= $formato === 'carnet' ? '9px' : '18px' ?>; padding-top: <?= $formato === 'carnet' ? '2px' : '4px' ?>; font-size: <?= $formato === 'carnet' ? '11px' : '9px' ?>; line-height: <?= $formato === 'carnet' ? '1.05' : '1.2' ?>; font-weight: 700; color: #374151; }
  .firma .cargo-firma { font-size: <?= $formato === 'carnet' ? '10px' : '8px' ?>; line-height: <?= $formato === 'carnet' ? '1.05' : '1.2' ?>; color: #9CA3AF; }
</style>
</head>
<body>
  <div class="pagina">
  <div class="hoja">
    <?php if ($plantilla_uri): ?>
      <img class="plantilla-fondo" src="<?= $plantilla_uri ?>" alt="plantilla">

      <!-- Overlay sobre la plantilla A4 (encabezado/pie/marca de agua incluidos en la imagen) -->
      <div class="ov ov-foto"><img src="<?= $foto_uri ?>" alt="foto"></div>
      <?php if ($mensaje_partido !== ''): ?><div class="ov ov-mensaje"><div><?= nl2br(h($mensaje_partido)) ?></div></div><?php endif; ?>
      <div class="ov ov-banner" style="<?= $titulo_banner_box_style ?>"><div style="<?= $titulo_banner_table_style ?>"><div style="<?= $titulo_banner_cell_style ?>"><span style="<?= $titulo_banner_text_style ?>"><?= h($titulo_credencial) ?></span></div></div></div>
      <div class="ov ov-codigo-banner"><?= h($c['codigo']) ?></div>
      <div class="ov ov-cuerpo">
        <div class="al-senor"><?= $texto1_html ?></div>
        <div class="facultades"><?= $texto3_html ?></div>
        <div class="texto-fecha"><?= h($texto4) ?></div>
      </div>
      <?php
        $render_firma = function ($f) {
            echo '<td class="ov-firma">';
            if ($f['imagen_uri']) echo '<img src="' . $f['imagen_uri'] . '" alt="firma">';
            echo '<div class="linea">' . h($f['nombre'] ?: ' ') . '</div>';
            echo '<div class="cargo-firma">' . h($f['cargo']) . '</div>';
            echo '</td>';
        };
        $render_qr = function ($rowspan = 1) use ($qr_uri) {
            echo '<td class="ov-firmas-qr"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>';
            if ($qr_uri) echo '<img src="' . $qr_uri . '" alt="QR">';
            echo '</td>';
        };
        $n = count($firmas);
      ?>
      <div class="ov ov-firmas">
        <table class="ov-firmas-tabla">
          <?php if ($n >= 3): ?>
            <tr>
              <?php $render_firma($firmas[0]); $render_firma($firmas[1]); $render_qr(2); ?>
            </tr>
            <tr>
              <td class="ov-firma" colspan="2">
                <?php
                  $f = $firmas[2];
                  if ($f['imagen_uri']) echo '<img src="' . $f['imagen_uri'] . '" alt="firma">';
                ?>
                <div class="linea"><?= h($f['nombre'] ?: ' ') ?></div>
                <div class="cargo-firma"><?= h($f['cargo']) ?></div>
              </td>
            </tr>
          <?php else: ?>
            <tr>
              <?php foreach ($firmas as $f) $render_firma($f); $render_qr(); ?>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php else: ?>
      <div class="marca-agua"><?= h($partido_nombre) ?></div>
      <div class="borde"></div>

    <div class="contenido">

      <div class="encabezado">
        <div class="partido"><?= h($partido_nombre) ?></div>
        <div class="titulo" style="<?= $titulo_box_style_inline . $titulo_text_style_inline ?>"><?= h($titulo_credencial) ?></div>
        <div class="codigo">N.° <?= h($c['codigo']) ?></div>
        <div class="estado-badge"><?= h($estado_label) ?></div>
      </div>

      <?php if ($mensaje_partido !== ''): ?>
        <div class="mensaje-partido"><?= nl2br(h($mensaje_partido)) ?></div>
      <?php endif; ?>

      <div class="cuerpo">
        <div class="col-foto">
          <img src="<?= $foto_uri ?>" alt="foto">
        </div>
        <div class="col-datos">
          <div class="nombre"><?= h($c['nombres_completos']) ?></div>
          <div class="texto-linea"><?= h($texto1) ?></div>
          <div class="texto-parrafo"><?= $texto3_html ?></div>
          <div class="texto-fecha"><?= h($texto4) ?></div>
        </div>
      </div>

      <div class="pie">
        <div class="col-qr">
          <?php if ($qr_uri): ?><img src="<?= $qr_uri ?>" alt="QR"><?php endif; ?>
          <p>Escanea para verificar la autenticidad de esta credencial en línea.</p>
        </div>
        <div class="col-firmas">
          <div class="firmas-tabla">
            <?php foreach ($firmas as $f): ?>
              <div class="firma">
                <?php if ($f['imagen_uri']): ?><img src="<?= $f['imagen_uri'] ?>" alt="firma"><?php endif; ?>
                <div class="linea"><?= h($f['nombre'] ?: ' ') ?></div>
                <div class="cargo-firma"><?= h($f['cargo']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
    <?php endif; ?>
  </div>
  </div>
  <?php if ($plantilla_reverso_uri): ?>
  <div class="pagina">
    <div class="hoja-reverso"><img src="<?= $plantilla_reverso_uri ?>" alt="reverso"></div>
  </div>
  <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

// ════════════════════════════════════════════════════════════
// Render con Dompdf
// ════════════════════════════════════════════════════════════
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('chroot', dirname(__DIR__));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
if ($carnet_duplex) {
    $dompdf->setPaper([0, 0, 379.84, 269.29], 'portrait'); // 134 x 95 mm
} else {
    $dompdf->setPaper($formato === 'carnet' ? [0, 0, 189.92, 269.29] : 'a4', 'portrait');
}
$dompdf->render();

$nombre_archivo = ($formato === 'carnet' ? 'carnet_' : 'credencial_') . $c['codigo'] . '.pdf';
if ($save_public && !$preview) {
    $pdf_dir = dirname(__DIR__) . '/uploads/credenciales/pdf';
    if (!is_dir($pdf_dir) && !mkdir($pdf_dir, 0775, true) && !is_dir($pdf_dir)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'No se pudo crear la carpeta pública de PDFs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safe_codigo = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$c['codigo']);
    $nombre_archivo = ($formato === 'carnet' ? 'carnet_' : 'credencial_') . $safe_codigo . '.pdf';
    $pdf_path = $pdf_dir . '/' . $nombre_archivo;
    $bytes = $dompdf->output();

    if (@file_put_contents($pdf_path, $bytes) === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el PDF público.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'url' => BASE_URL . '/uploads/credenciales/pdf/' . rawurlencode($nombre_archivo),
        'file' => 'uploads/credenciales/pdf/' . $nombre_archivo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dompdf->stream($nombre_archivo, ['Attachment' => $download]);
exit;
