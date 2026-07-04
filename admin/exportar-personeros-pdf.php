<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_rol('editor');
require_modulo($pdo, 'personeros');

$cargo_filter  = $_GET['cargo']  ?? '';
$estado_filter = $_GET['estado'] ?? '';
$embed         = (int)($_GET['embed']    ?? 0) === 1;
$download      = (int)($_GET['download'] ?? 0) === 1;

if (!in_array($cargo_filter,  ['', 'titular', 'alterno'],  true)) $cargo_filter  = '';
if (!in_array($estado_filter, ['', 'activo',  'inactivo'], true)) $estado_filter = '';

$where  = [];
$params = [];
if ($cargo_filter  !== '') { $where[] = "cargo  = ?"; $params[] = $cargo_filter;  }
if ($estado_filter !== '') { $where[] = "estado = ?"; $params[] = $estado_filter; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$personeros = [];
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM personeros $where_sql ORDER BY cargo ASC, apellidos ASC, nombres ASC"
    );
    $stmt->execute($params);
    $personeros = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $personeros = [];
}

$total     = count($personeros);
$titulares = count(array_filter($personeros, fn($p) => $p['cargo'] === 'titular'));
$alternos  = $total - $titulares;
$activos   = count(array_filter($personeros, fn($p) => $p['estado'] === 'activo'));
$inactivos = $total - $activos;
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

$titulo = 'Relación de Personeros';
if ($cargo_filter === 'titular')       $titulo = 'Personeros Titulares';
elseif ($cargo_filter === 'alterno')   $titulo = 'Personeros Alternos';
if ($estado_filter === 'activo')       $titulo .= ' — Activos';
elseif ($estado_filter === 'inactivo') $titulo .= ' — Inactivos';

// ── Helpers ───────────────────────────────────────────────────
function h(?string $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function sv(?string $v): string {
    $v = trim((string)$v);
    return $v !== '' ? h($v) : '<span class="muted">—</span>';
}

// ── PDF binario ───────────────────────────────────────────────
function pdf_txt(string $t): string {
    $t = str_replace(["\r","\n","\t"], ' ', $t);
    $t = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t) ?: $t;
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $t);
}
function pdf_cell(?string $t, int $max): string {
    $t = trim((string)$t);
    if ($t === '') return '-';
    if (mb_strlen($t, 'UTF-8') > $max) return mb_substr($t, 0, max(0, $max - 3), 'UTF-8') . '...';
    return $t;
}

function pdf_gen_personeros(array $rows, string $title, string $fecha): string {
    $w = 842; $h = 595; $mg = 34;
    $row_h = 18; $header_h = 98; $footer_h = 28;
    $table_top  = $h - $header_h - 32;
    $rows_pp    = 20;
    $pages      = array_chunk($rows, $rows_pp);
    if (empty($pages)) $pages = [[]];

    $objects = [];
    $font_r = 3; $font_b = 4;
    $objects[$font_r] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica        /Encoding /WinAnsiEncoding >>";
    $objects[$font_b] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold   /Encoding /WinAnsiEncoding >>";

    $page_ids = [];
    $next_id  = 5;

    // Columnas: Nro | Apellidos y Nombres | DNI/CE | Cargo | Local Votación | Mesa | Estado | Origen
    $headers = ['Nro.', 'Apellidos y Nombres', 'DNI / C.E.', 'Cargo', 'Local de Votacion', 'Mesa', 'Estado', 'Origen'];
    $xs      = [$mg, 70, 215, 285, 345, 505, 565, 635];
    $maxc    = [5,   25,  10,   7,   24,   7,   8,   11];

    foreach ($pages as $pidx => $page_rows) {
        $cid = $next_id++; $pgid = $next_id++;
        $page_ids[] = $pgid;

        $ops = [];
        // Cabecera azul
        $ops[] = "q 0.117 0.227 0.541 rg 0 " . ($h - 86) . " $w 86 re f Q";
        $ops[] = "BT /F2 22 Tf 1 1 1 rg $mg " . ($h - 46) . " Td (" . pdf_txt($brand_name)    . ") Tj ET";
        $ops[] = "BT /F2 9  Tf 1 0.8 0 rg $mg " . ($h - 64) . " Td (" . pdf_txt($partido_nombre) . ") Tj ET";
        $right = $w - 280;
        $ops[] = "BT /F2 15 Tf 1 1 1 rg $right " . ($h - 44) . " Td (" . pdf_txt($title)               . ") Tj ET";
        $ops[] = "BT /F1 9  Tf 1 1 1 rg $right " . ($h - 62) . " Td (" . pdf_txt('Generado: ' . $fecha) . ") Tj ET";
        $ops[] = "BT /F1 9  Tf 1 1 1 rg $right " . ($h - 76) . " Td (" . pdf_txt('Registros: ' . count($rows)) . ") Tj ET";

        $y = $table_top;
        $ops[] = "q 0.117 0.227 0.541 rg $mg " . ($y - 4) . " " . ($w - $mg * 2) . " 20 re f Q";
        foreach ($headers as $i => $hd) {
            $ops[] = "BT /F2 7 Tf 1 1 1 rg {$xs[$i]} " . ($y + 2) . " Td (" . pdf_txt($hd) . ") Tj ET";
        }
        $y -= $row_h;

        foreach ($page_rows as $li => $row) {
            $gi = ($pidx * $rows_pp) + $li + 1;
            if ($li % 2 === 0) {
                $ops[] = "q 0.975 0.98 0.99 rg $mg " . ($y - 4) . " " . ($w - $mg * 2) . " 18 re f Q";
            }
            $doc = ($row['dni'] !== '' && $row['dni'] !== null) ? $row['dni'] : ($row['carnet_extranjeria'] ?? '');
            $values = [
                (string)$gi,
                trim(($row['apellidos'] ?? '') . ', ' . ($row['nombres'] ?? '')),
                (string)$doc,
                ucfirst($row['cargo'] ?? ''),
                (string)($row['local_votacion'] ?? ''),
                (string)($row['numero_mesa']    ?? ''),
                ($row['estado'] ?? '') === 'inactivo' ? 'Inactivo' : 'Activo',
                ucfirst($row['origen'] ?? ''),
            ];
            foreach ($values as $i => $val) {
                $font = $i === 1 ? '/F2' : '/F1';
                $ops[] = "BT $font 7 Tf 0.08 0.12 0.20 rg {$xs[$i]} " . ($y + 2) . " Td (" . pdf_txt(pdf_cell((string)$val, $maxc[$i])) . ") Tj ET";
            }
            $y -= $row_h;
        }

        $pg_label = 'Pagina ' . ($pidx + 1) . ' de ' . count($pages) . ' - Documento confidencial';
        $ops[] = "BT /F1 8 Tf 0.45 0.50 0.60 rg $mg $footer_h Td (" . pdf_txt($pg_label) . ") Tj ET";

        $stream = implode("\n", $ops) . "\n";
        $objects[$cid]  = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $objects[$pgid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /Font << /F1 $font_r 0 R /F2 $font_b 0 R >> >> /Contents $cid 0 R >>";
    }

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(fn($id) => "$id 0 R", $page_ids)) . "] /Count " . count($page_ids) . " >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "$id 0 obj\n$body\nendobj\n";
    }
    $xref_pos = strlen($pdf);
    $max_id   = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max_id + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max_id; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\nstartxref\n$xref_pos\n%%EOF";
    return $pdf;
}

// ── Descarga directa ──────────────────────────────────────────
if ($download) {
    $fname = 'personeros_' . date('Ymd_His') . '.pdf';
    $pdf   = pdf_gen_personeros($personeros, $titulo, $fecha_generado);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
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
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      font-size: 12px;
      color: #1a1a1a;
      background: #eef2f7;
    }
    .toolbar {
      position: sticky; top: 0; z-index: 10;
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; padding: 12px 18px;
      background: #1E3A8A; color: #fff;
      box-shadow: 0 8px 20px rgba(15,32,87,.18);
    }
    .toolbar strong { font-size: 14px; }
    .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .toolbar button, .toolbar a {
      border: 0; border-radius: 10px; padding: 8px 14px;
      font-size: 12px; font-weight: 800; cursor: pointer; text-decoration: none;
    }
    .toolbar button { background: #FACC15; color: #1E3A8A; }
    .toolbar a      { background: rgba(255,255,255,.14); color: #fff; }
    .toolbar .dl-btn { background: #059669; color: #fff; }

    .sheet {
      width: min(1120px, calc(100% - 32px));
      margin: 22px auto 32px;
      background: #fff; border-radius: 14px; overflow: hidden;
      box-shadow: 0 18px 45px rgba(15,32,87,.12);
    }
    .doc-head {
      display: flex; justify-content: space-between; gap: 24px;
      padding: 30px 36px; background: #1E3A8A; color: #fff;
    }
    .brand h1 { margin: 0; font-size: 25px; font-weight: 900; line-height: 1.05; }
    .brand p  { margin: 6px 0 0; color: #FACC15; font-size: 11px; letter-spacing: 1.6px; text-transform: uppercase; font-weight: 800; }
    .meta { text-align: right; color: rgba(255,255,255,.78); line-height: 1.7; min-width: 240px; }
    .meta strong { display: block; color: #fff; font-size: 19px; line-height: 1.15; margin-bottom: 6px; }

    .summary {
      display: flex; gap: 10px; flex-wrap: wrap;
      padding: 16px 36px; background: #f8fafc; border-bottom: 1px solid #e5e7eb;
    }
    .pill {
      border: 1px solid #dbeafe; background: #eff6ff; color: #1E3A8A;
      border-radius: 999px; padding: 7px 13px; font-weight: 800; font-size: 11px;
    }
    .pill-green  { border-color: #bbf7d0; background: #dcfce7; color: #15803d; }
    .pill-red    { border-color: #fecaca; background: #fee2e2; color: #b91c1c; }

    .table-wrap { padding: 22px 36px 32px; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    thead th {
      background: #1E3A8A; color: #fff;
      text-transform: uppercase; letter-spacing: .4px;
      font-size: 10px; padding: 10px 9px; text-align: left;
      white-space: nowrap;
    }
    tbody td {
      border-bottom: 1px solid #e5e7eb; padding: 9px 9px;
      vertical-align: middle; word-break: break-word;
    }
    tbody tr:nth-child(even) { background: #f9fafb; }
    tbody tr:hover { background: #eff6ff; }
    .col-num  { width: 42px; text-align: center; color: #9ca3af; font-size: 11px; }
    .col-name { font-weight: 700; color: #111827; }
    .badge {
      display: inline-block; border-radius: 999px;
      padding: 3px 9px; font-size: 10px; font-weight: 800; white-space: nowrap;
    }
    .b-titular  { background: #dbeafe; color: #1E3A8A; }
    .b-alterno  { background: #d1fae5; color: #065f46; }
    .b-activo   { background: #dcfce7; color: #15803d; }
    .b-inactivo { background: #fee2e2; color: #b91c1c; }
    .b-manual        { background: #f3f4f6; color: #374151; }
    .b-militante     { background: #ede9fe; color: #6d28d9; }
    .b-simpatizante  { background: #fef3c7; color: #92400e; }
    .muted { color: #9ca3af; }

    .footer {
      padding: 12px 36px; background: #1E3A8A;
      color: rgba(255,255,255,.72); font-size: 10px; text-align: center;
    }

    @media print {
      @page { size: A4 landscape; margin: 9mm; }
      body { background: #fff; font-size: 9px; }
      .toolbar { display: none !important; }
      .sheet { width: 100%; margin: 0; border-radius: 0; box-shadow: none; }
      .doc-head  { padding: 18px 20px; }
      .brand h1  { font-size: 18px; }
      .meta strong { font-size: 14px; }
      .summary   { padding: 10px 20px; }
      .table-wrap{ padding: 12px 20px 18px; overflow-x: visible; }
      thead th   { padding: 7px 6px; font-size: 8px; }
      tbody td   { padding: 5px 6px; }
      tbody tr   { page-break-inside: avoid; break-inside: avoid; }
      .footer    { padding: 8px 20px; }
    }
  </style>
</head>
<body>
<?php if (!$embed): ?>
<div class="toolbar">
  <strong>Vista previa — <?= h($titulo) ?></strong>
  <div class="toolbar-actions">
    <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    <?php
      $dl_params = array_filter(['cargo' => $cargo_filter, 'estado' => $estado_filter, 'download' => '1']);
      $dl_qs = http_build_query($dl_params);
    ?>
    <a href="exportar-personeros-pdf.php?<?= $dl_qs ?>" class="dl-btn">Descargar PDF</a>
    <a href="personeros.php">Volver</a>
  </div>
</div>
<?php endif; ?>

<main class="sheet">
  <section class="doc-head">
    <div class="brand">
      <h1><?= h($brand_name) ?></h1>
      <p><?= h($partido_nombre) ?></p>
    </div>
    <div class="meta">
      <strong><?= h($titulo) ?></strong>
      Generado el: <?= h($fecha_generado) ?><br>
      Total registros: <?= $total ?><br>
      Uso interno — Confidencial
    </div>
  </section>

  <section class="summary">
    <span class="pill">Total: <?= $total ?></span>
    <span class="pill">Titulares: <?= $titulares ?></span>
    <span class="pill">Alternos: <?= $alternos ?></span>
    <span class="pill pill-green">Activos: <?= $activos ?></span>
    <span class="pill pill-red">Inactivos: <?= $inactivos ?></span>
  </section>

  <section class="table-wrap">
    <table>
      <thead>
        <tr>
          <th class="col-num">Nro.</th>
          <th style="width:22%">Apellidos y Nombres</th>
          <th style="width:9%">DNI / C.E.</th>
          <th style="width:8%">Cargo</th>
          <th style="width:19%">Local de Votación</th>
          <th style="width:7%">Mesa</th>
          <th style="width:8%">Estado</th>
          <th style="width:8%">Origen</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($personeros)): ?>
        <tr>
          <td colspan="8" style="text-align:center;padding:28px;color:#9ca3af;">
            No hay personeros con los filtros seleccionados.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($personeros as $i => $p): ?>
        <tr>
          <td class="col-num"><?= $i + 1 ?></td>
          <td class="col-name"><?= h(trim($p['apellidos'] . ', ' . $p['nombres'])) ?></td>
          <td>
            <?php if (!empty($p['dni'])): ?>
              <?= h($p['dni']) ?>
            <?php elseif (!empty($p['carnet_extranjeria'])): ?>
              <span class="muted" style="font-size:10px">CE:</span> <?= h($p['carnet_extranjeria']) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $p['cargo'] === 'alterno' ? 'b-alterno' : 'b-titular' ?>">
              <?= ucfirst(h($p['cargo'])) ?>
            </span>
          </td>
          <td><?= sv($p['local_votacion'] ?? '') ?></td>
          <td><?= sv($p['numero_mesa']    ?? '') ?></td>
          <td>
            <span class="badge <?= $p['estado'] === 'inactivo' ? 'b-inactivo' : 'b-activo' ?>">
              <?= $p['estado'] === 'inactivo' ? 'Inactivo' : 'Activo' ?>
            </span>
          </td>
          <td>
            <?php
              $oc = match($p['origen'] ?? '') {
                'militante'    => 'b-militante',
                'simpatizante' => 'b-simpatizante',
                default        => 'b-manual',
              };
            ?>
            <span class="badge <?= $oc ?>"><?= ucfirst(h($p['origen'] ?? 'manual')) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <footer class="footer">
    Documento generado el <?= h($fecha_generado) ?> · Portal <?= h($brand_name) ?> · Confidencial
  </footer>
</main>
</body>
</html>
