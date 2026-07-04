<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_modulo($pdo, 'militantes');

$buscar = trim($_GET['q'] ?? '');
$estado_filter = $_GET['estado'] ?? '';
$cargo_filter = (int)($_GET['cargo'] ?? 0);
$orden = $_GET['orden'] ?? 'fecha';
$dir = strtolower($_GET['dir'] ?? 'desc');
$embed = (int)($_GET['embed'] ?? 0) === 1;
$download = (int)($_GET['download'] ?? 0) === 1;

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
$militantes = [];

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
    $militantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $militantes = [];
}

$total = count($militantes);
$activos = count(array_filter($militantes, fn($m) => ($m['estado'] ?? '') === 'activo'));
$inactivos = count(array_filter($militantes, fn($m) => ($m['estado'] ?? '') === 'inactivo'));
$fecha_generado = date('d/m/Y H:i');

$partido_nombre = 'ALIANZA PARA EL PROGRESO';
try {
    $v = $pdo->query("SELECT valor FROM configuracion WHERE clave='partido_nombre' LIMIT 1")->fetchColumn();
    if ($v) $partido_nombre = strtoupper($v);
} catch (Exception $e) {}

$brand_name = 'Sistema de Gestión de Credenciales';
try {
    $bn = $pdo->query("SELECT valor FROM configuracion WHERE clave='site_brand_name' LIMIT 1")->fetchColumn();
    if ($bn) $brand_name = $bn;
} catch (Exception $e) {}

$titulo = 'Relacion de Militantes';
if ($estado_filter === 'activo') {
    $titulo = 'Relacion de Militantes Activos';
} elseif ($estado_filter === 'inactivo') {
    $titulo = 'Relacion de Militantes Inactivos';
}

function h(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function show_value(?string $value): string {
    $value = trim((string)$value);
    return $value !== '' ? h($value) : '<span class="muted">-</span>';
}

function pdf_text(string $text): string {
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: $text;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_cell_text(?string $text, int $max): string {
    $text = trim((string)$text);
    if ($text === '') return '-';
    if (mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, max(0, $max - 3), 'UTF-8') . '...';
    }
    return $text;
}

function pdf_stream(array $ops): string {
    return implode("\n", $ops) . "\n";
}

function pdf_generate_militantes(array $rows, string $title, string $fecha_generado): string {
    $w = 842;
    $h = 595;
    $margin = 34;
    $row_h = 18;
    $header_h = 98;
    $footer_h = 28;
    $table_top = $h - $header_h - 32;
    $rows_per_page = 20;
    $pages = array_chunk($rows, $rows_per_page);
    if (empty($pages)) $pages = [[]];

    $objects = [];
    $page_ids = [];
    $font_regular = 3;
    $font_bold = 4;

    $objects[$font_regular] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objects[$font_bold] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    $next_id = 5;
    foreach ($pages as $page_index => $page_rows) {
        $content_id = $next_id++;
        $page_id = $next_id++;
        $page_ids[] = $page_id;

        $ops = [];
        $ops[] = "q";
        $ops[] = "0.117 0.227 0.541 rg";
        $ops[] = "0 " . ($h - 86) . " $w 86 re f";
        $ops[] = "Q";
        $ops[] = "BT /F2 22 Tf 1 1 1 rg " . $margin . " " . ($h - 46) . " Td (" . pdf_text($brand_name) . ") Tj ET";
        $ops[] = "BT /F2 9 Tf 1 0.8 0 rg " . $margin . " " . ($h - 64) . " Td (" . pdf_text($partido_nombre) . ") Tj ET";
        $ops[] = "BT /F2 15 Tf 1 1 1 rg " . ($w - 280) . " " . ($h - 44) . " Td (" . pdf_text($title) . ") Tj ET";
        $ops[] = "BT /F1 9 Tf 1 1 1 rg " . ($w - 280) . " " . ($h - 62) . " Td (" . pdf_text('Generado: ' . $fecha_generado) . ") Tj ET";
        $ops[] = "BT /F1 9 Tf 1 1 1 rg " . ($w - 280) . " " . ($h - 76) . " Td (" . pdf_text('Registros: ' . count($rows)) . ") Tj ET";

        $y = $table_top;
        $headers = ['Nro.', 'Apellidos y nombres', 'Fecha', 'DNI', 'Celular', 'WhatsApp', 'Correo', 'Cargo', 'Estado'];
        $xs = [$margin, 70, 215, 280, 340, 410, 490, 640, 740];
        $max = [5, 30, 10, 10, 12, 12, 32, 18, 10];
        $ops[] = "q 0.117 0.227 0.541 rg $margin " . ($y - 4) . " " . ($w - ($margin * 2)) . " 20 re f Q";
        foreach ($headers as $i => $head) {
            $ops[] = "BT /F2 7 Tf 1 1 1 rg " . $xs[$i] . " " . ($y + 2) . " Td (" . pdf_text($head) . ") Tj ET";
        }
        $y -= $row_h;

        foreach ($page_rows as $local_i => $row) {
            $global_i = ($page_index * $rows_per_page) + $local_i + 1;
            if ($local_i % 2 === 0) {
                $ops[] = "q 0.975 0.98 0.99 rg $margin " . ($y - 4) . " " . ($w - ($margin * 2)) . " 18 re f Q";
            }
            $values = [
                (string)$global_i,
                $row['nombre'] ?? '',
                !empty($row['fecha_ingreso']) ? date('d/m/Y', strtotime($row['fecha_ingreso'])) : '',
                $row['dni'] ?? '',
                $row['celular'] ?? '',
                $row['whatsapp'] ?? '',
                $row['correo'] ?? '',
                $row['cargo'] ?: 'Sin cargo',
                ($row['estado'] ?? '') === 'inactivo' ? 'Inactivo' : 'Activo',
            ];
            foreach ($values as $i => $value) {
                $font = $i === 1 ? '/F2' : '/F1';
                $ops[] = "BT $font 7 Tf 0.08 0.12 0.20 rg " . $xs[$i] . " " . ($y + 2) . " Td (" . pdf_text(pdf_cell_text((string)$value, $max[$i])) . ") Tj ET";
            }
            $y -= $row_h;
        }

        $ops[] = "BT /F1 8 Tf 0.45 0.50 0.60 rg " . $margin . " " . $footer_h . " Td (" . pdf_text('Pagina ' . ($page_index + 1) . ' de ' . count($pages) . ' - Documento confidencial') . ") Tj ET";
        $stream = pdf_stream($ops);
        $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $objects[$page_id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /Font << /F1 $font_regular 0 R /F2 $font_bold 0 R >> >> /Contents $content_id 0 R >>";
    }

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(fn($id) => "$id 0 R", $page_ids)) . "] /Count " . count($page_ids) . " >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "$id 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= max(array_keys($objects)); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    return $pdf;
}

if ($download) {
    $filename = 'militantes_' . date('Ymd_His') . '.pdf';
    $pdf = pdf_generate_militantes($militantes, $titulo, $fecha_generado);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($titulo) ?></title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #eef2f7;
      color: #172033;
      font-family: "Segoe UI", Arial, sans-serif;
      font-size: 12px;
    }
    .toolbar {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 18px;
      background: #1E3A8A;
      color: #fff;
      box-shadow: 0 8px 20px rgba(15, 32, 87, .18);
    }
    .toolbar strong { font-size: 14px; }
    .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .toolbar button,
    .toolbar a {
      border: 0;
      border-radius: 10px;
      padding: 8px 14px;
      font-size: 12px;
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
    }
    .toolbar button { background: #FACC15; color: #1E3A8A; }
    .toolbar a { background: rgba(255,255,255,.14); color: #fff; }
    .sheet {
      width: min(1120px, calc(100% - 32px));
      margin: 22px auto;
      background: #fff;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 18px 45px rgba(15, 32, 87, .12);
    }
    .doc-head {
      display: flex;
      justify-content: space-between;
      gap: 24px;
      padding: 30px 36px;
      background: #1E3A8A;
      color: #fff;
    }
    .brand h1 {
      margin: 0;
      font-size: 25px;
      line-height: 1.05;
      font-weight: 900;
    }
    .brand p {
      margin: 6px 0 0;
      color: #FACC15;
      font-size: 11px;
      letter-spacing: 1.6px;
      text-transform: uppercase;
      font-weight: 800;
    }
    .meta {
      text-align: right;
      color: rgba(255,255,255,.78);
      line-height: 1.7;
      min-width: 240px;
    }
    .meta strong {
      display: block;
      color: #fff;
      font-size: 19px;
      line-height: 1.15;
      margin-bottom: 6px;
    }
    .summary {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      padding: 18px 36px;
      background: #f8fafc;
      border-bottom: 1px solid #e5e7eb;
    }
    .pill {
      border: 1px solid #dbeafe;
      background: #eff6ff;
      color: #1E3A8A;
      border-radius: 999px;
      padding: 8px 12px;
      font-weight: 800;
      font-size: 11px;
    }
    .table-wrap { padding: 22px 36px 30px; }
    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    thead th {
      background: #1E3A8A;
      color: #fff;
      text-transform: uppercase;
      letter-spacing: .4px;
      font-size: 10px;
      padding: 10px 8px;
      text-align: left;
    }
    tbody td {
      border-bottom: 1px solid #e5e7eb;
      padding: 9px 8px;
      vertical-align: top;
      word-break: break-word;
    }
    tbody tr:nth-child(even) { background: #f9fafb; }
    .num { width: 38px; text-align: center; color: #6b7280; }
    .name { font-weight: 800; }
    .chip {
      display: inline-block;
      border-radius: 999px;
      padding: 3px 8px;
      background: #eff6ff;
      color: #1E40AF;
      font-size: 10px;
      font-weight: 800;
    }
    .state-active { background: #dcfce7; color: #15803d; }
    .state-inactive { background: #fee2e2; color: #b91c1c; }
    .muted { color: #9ca3af; }
    .footer {
      padding: 12px 36px;
      background: #1E3A8A;
      color: rgba(255,255,255,.78);
      font-size: 10px;
      text-align: center;
    }
    @media print {
      @page { size: A4 landscape; margin: 9mm; }
      body { background: #fff; font-size: 9px; }
      .toolbar { display: none !important; }
      .sheet {
        width: 100%;
        margin: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .doc-head { padding: 18px 20px; }
      .brand h1 { font-size: 18px; }
      .meta strong { font-size: 14px; }
      .summary { padding: 12px 20px; }
      .table-wrap { padding: 14px 20px 18px; }
      thead th { padding: 7px 6px; font-size: 8px; }
      tbody td { padding: 5px 6px; }
      tbody tr { page-break-inside: avoid; break-inside: avoid; }
      .footer { padding: 8px 20px; }
    }
  </style>
</head>
<body>
<?php if (!$embed): ?>
<div class="toolbar">
  <strong>Vista previa para imprimir / PDF</strong>
  <div class="toolbar-actions">
    <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    <a href="militantes.php">Volver</a>
  </div>
</div>
<?php endif; ?>

<main class="sheet">
  <section class="doc-head">
    <div class="brand">
      <h1><?= htmlspecialchars($brand_name) ?></h1>
      <p><?= htmlspecialchars($partido_nombre) ?></p>
    </div>
    <div class="meta">
      <strong><?= h($titulo) ?></strong>
      Generado el: <?= h($fecha_generado) ?><br>
      Total registros: <?= $total ?><br>
      Uso interno - Confidencial
    </div>
  </section>

  <section class="summary">
    <span class="pill">Total: <?= $total ?></span>
    <span class="pill">Activos: <?= $activos ?></span>
    <span class="pill">Inactivos: <?= $inactivos ?></span>
    <?php if ($buscar !== ''): ?><span class="pill">Busqueda: <?= h($buscar) ?></span><?php endif; ?>
  </section>

  <section class="table-wrap">
    <table>
      <thead>
        <tr>
          <th class="num">Nro.</th>
          <th style="width:18%">Apellidos y nombres</th>
          <th style="width:8%">Fecha ingreso</th>
          <th style="width:8%">DNI</th>
          <th style="width:9%">Celular</th>
          <th style="width:9%">WhatsApp</th>
          <th style="width:18%">Correo</th>
          <th style="width:12%">Cargo</th>
          <th style="width:9%">Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($militantes)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;">No hay militantes con los filtros actuales.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($militantes as $i => $m): ?>
        <tr>
          <td class="num"><?= $i + 1 ?></td>
          <td class="name"><?= h($m['nombre']) ?></td>
          <td><?= !empty($m['fecha_ingreso']) ? date('d/m/Y', strtotime($m['fecha_ingreso'])) : '<span class="muted">-</span>' ?></td>
          <td><?= show_value($m['dni'] ?? '') ?></td>
          <td><?= show_value($m['celular'] ?? '') ?></td>
          <td><?= show_value($m['whatsapp'] ?? '') ?></td>
          <td><?= show_value($m['correo'] ?? '') ?></td>
          <td><span class="chip"><?= h($m['cargo'] ?: 'Sin cargo') ?></span></td>
          <td>
            <?php if (($m['estado'] ?? '') === 'inactivo'): ?>
            <span class="chip state-inactive">Inactivo</span>
            <?php else: ?>
            <span class="chip state-active">Activo</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <footer class="footer">
    Documento generado el <?= h($fecha_generado) ?> - Portal <?= htmlspecialchars($brand_name) ?> - Confidencial
  </footer>
</main>
</body>
</html>
