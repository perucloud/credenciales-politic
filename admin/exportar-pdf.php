<?php
// ============================================================
// exportar-pdf.php — Lista de simpatizantes para imprimir/PDF
// Página independiente (NO usa layout.php)
// ============================================================
session_start();
require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_modulo($pdo, 'simpatizantes');

// ── Consulta completa ordenada por distrito y nombre ────────
try {
    $stmt = $pdo->query("SELECT * FROM simpatizantes ORDER BY distrito, nombre");
    $simpatizantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $simpatizantes = [];
}

$total = count($simpatizantes);
$fecha_generado = date('d/m/Y H:i');
$embed    = isset($_GET['embed'])    && $_GET['embed']    === '1';
$download = isset($_GET['download']) && $_GET['download'] === '1';

// ── Nombre del partido desde configuracion ───────────────────
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

// ── Resumen por distrito ─────────────────────────────────────
$por_distrito = [];
foreach ($simpatizantes as $r) {
    $d = $r['distrito'] ?? 'Sin distrito';
    if (!$d) $d = 'Sin distrito';
    $por_distrito[$d] = ($por_distrito[$d] ?? 0) + 1;
}
arsort($por_distrito);

// ── Helper: valor o guion ────────────────────────────────────
function val(string|null $v): string {
    return (isset($v) && $v !== '') ? htmlspecialchars($v) : '—';
}

// ── Descarga real PDF con dompdf ─────────────────────────────
if ($download) {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Construir filas de la tabla
    $filas_html = '';
    foreach ($simpatizantes as $i => $r) {
        $cel  = htmlspecialchars($r['celular'] ?: ($r['telefono'] ?? ''));
        $bg   = ($i % 2 === 0) ? '#ffffff' : '#eff6ff';
        $filas_html .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#9ca3af;font-size:9px">' . ($i + 1) . '</td>
            <td style="font-weight:700;color:#111827">' . htmlspecialchars($r['nombre']) . '</td>
            <td style="font-family:monospace">' . htmlspecialchars($r['dni'] ?? '') . '</td>
            <td>' . ($cel ?: '—') . '</td>
            <td>' . ($r['whatsapp'] ? htmlspecialchars($r['whatsapp']) : '—') . '</td>
            <td style="font-size:9px">' . ($r['correo']   ? htmlspecialchars($r['correo'])   : '—') . '</td>
            <td><span style="background:#eff6ff;color:#1E3A8A;border-radius:10px;padding:2px 7px;font-size:9px;font-weight:600">' . htmlspecialchars($r['distrito'] ?? '—') . '</span></td>
            <td style="font-size:9px;color:#4b5563">' . htmlspecialchars($r['formas_apoyo'] ?? '—') . '</td>
            <td style="font-size:9px;color:#6b7280;white-space:nowrap">' . ($r['fecha_registro'] ? date('d/m/Y', strtotime($r['fecha_registro'])) : '—') . '</td>
        </tr>';
    }

    // Badges de distrito
    $dist_badges = '';
    foreach ($por_distrito as $dist => $cnt) {
        $dist_badges .= '<span style="background:#eff6ff;color:#1E3A8A;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px;font-size:9px;font-weight:600;margin-right:4px">'
            . htmlspecialchars($dist) . ' <strong>' . $cnt . '</strong></span>';
    }

    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#1a1a1a; background:#fff; }
  .header { background:#1E3A8A; color:#fff; padding:18px 24px; }
  .header-name { font-size:18px; font-weight:900; }
  .header-partido { font-size:9px; color:#FACC15; text-transform:uppercase; letter-spacing:1px; margin-top:3px; }
  .header-meta { font-size:9px; color:rgba(255,255,255,.75); text-align:right; }
  .header-meta strong { font-size:14px; color:#fff; display:block; margin-bottom:2px; }
  .header-inner { display:table; width:100%; }
  .header-left  { display:table-cell; vertical-align:middle; }
  .header-right { display:table-cell; vertical-align:middle; text-align:right; }
  .stats { background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:10px 24px; }
  .table-wrap { padding:0 24px 20px; }
  table { width:100%; border-collapse:collapse; font-size:10px; margin-top:14px; }
  thead tr { background:#1E3A8A; color:#fff; }
  thead th { padding:8px 7px; text-align:left; font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
  tbody td { padding:6px 7px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
  .footer { background:#1E3A8A; color:rgba(255,255,255,.65); text-align:center; font-size:9px; padding:10px 24px; }
  .footer strong { color:#fff; }
</style>
</head>
<body>
<div class="header">
  <div class="header-inner">
    <div class="header-left">
      <div class="header-name">' . htmlspecialchars($brand_name) . '</div>
      <div class="header-partido">' . htmlspecialchars($partido_nombre) . '</div>
    </div>
    <div class="header-right header-meta">
      <strong>Lista de Simpatizantes</strong>
      Generado el: ' . $fecha_generado . '<br>
      Total registros: ' . $total . '<br>
      Uso interno — Confidencial
    </div>
  </div>
</div>
<div class="stats">
  <span style="background:#1E3A8A;color:#fff;border-radius:8px;padding:4px 14px;font-weight:900;font-size:16px;margin-right:10px">' . $total . ' <span style="font-size:8px;color:#FACC15;font-weight:700">SIMP.</span></span>
  Por distrito: ' . $dist_badges . '
</div>
<div class="table-wrap">
  <table>
    <thead><tr>
      <th style="width:26px">N°</th>
      <th>Apellidos y nombres</th>
      <th>DNI</th>
      <th>Cel</th>
      <th>WhatsApp</th>
      <th>Correo</th>
      <th>Distrito</th>
      <th>Cómo apoya</th>
      <th>Fecha</th>
    </tr></thead>
    <tbody>' . $filas_html . '</tbody>
  </table>
</div>
<div class="footer">
  Documento generado el <strong>' . $fecha_generado . '</strong> — Portal <strong>' . htmlspecialchars($brand_name) . '</strong> — <strong>CONFIDENCIAL</strong>
</div>
</body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'simpatizantes_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $dompdf->output();
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lista de Simpatizantes — <?= htmlspecialchars($brand_name) ?></title>
  <style>
    /* ── Reset & base ─────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      font-size: 12px;
      color: #1a1a1a;
      background: #f3f4f6;
    }

    a { color: inherit; text-decoration: none; }

    /* ── Toolbar (solo pantalla) ──────────────────────────── */
    .toolbar {
      background: #1E3A8A;
      color: white;
      padding: 14px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }

    .toolbar-title {
      font-size: 14px;
      font-weight: 700;
      letter-spacing: .3px;
    }

    .toolbar-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .btn-print {
      background: #FACC15;
      color: #1E3A8A;
      font-size: 13px;
      font-weight: 700;
      padding: 9px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      transition: background .15s;
    }
    .btn-print:hover { background: #fde047; }

    .btn-back {
      background: rgba(255,255,255,.15);
      color: white;
      font-size: 13px;
      font-weight: 600;
      padding: 9px 18px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background .15s;
    }
    .btn-back:hover { background: rgba(255,255,255,.28); }

    /* ── Contenedor de impresión ──────────────────────────── */
    .print-wrap {
      max-width: 1100px;
      margin: 28px auto;
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 24px rgba(0,0,0,.10);
    }

    /* ── Encabezado del documento ─────────────────────────── */
    .doc-header {
      background: #1E3A8A;
      color: white;
      padding: 28px 36px 24px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
    }

    .logo-area {}

    .logo-name {
      font-size: 22px;
      font-weight: 900;
      letter-spacing: -.3px;
      line-height: 1.1;
    }

    .logo-partido {
      font-size: 11px;
      font-weight: 600;
      color: #FACC15;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      margin-top: 5px;
    }

    .logo-accent {
      width: 44px;
      height: 4px;
      background: #FACC15;
      border-radius: 2px;
      margin-top: 10px;
    }

    .doc-meta {
      text-align: right;
      font-size: 11px;
      color: rgba(255,255,255,.75);
      line-height: 1.7;
    }

    .doc-meta strong {
      display: block;
      font-size: 18px;
      font-weight: 800;
      color: white;
      letter-spacing: -.2px;
      margin-bottom: 4px;
    }

    /* ── Stats ────────────────────────────────────────────── */
    .stats-bar {
      background: #f8fafc;
      border-bottom: 1px solid #e5e7eb;
      padding: 16px 36px;
      display: flex;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
    }

    .stat-total {
      background: #1E3A8A;
      color: white;
      border-radius: 10px;
      padding: 10px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 110px;
      flex-shrink: 0;
    }

    .stat-total .num {
      font-size: 28px;
      font-weight: 900;
      line-height: 1;
    }

    .stat-total .lbl {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-top: 4px;
      color: #FACC15;
    }

    .distritos-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
    }

    .distritos-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: #6b7280;
      margin-right: 4px;
      align-self: center;
    }

    .dist-badge {
      background: #eff6ff;
      color: #1E3A8A;
      border: 1px solid #bfdbfe;
      border-radius: 20px;
      padding: 3px 10px;
      font-size: 10.5px;
      font-weight: 600;
      white-space: nowrap;
    }

    .dist-badge span {
      background: #1E3A8A;
      color: white;
      border-radius: 10px;
      padding: 1px 6px;
      margin-left: 5px;
      font-size: 10px;
    }

    /* ── Tabla ────────────────────────────────────────────── */
    .table-wrap {
      padding: 0 36px 28px;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
      margin-top: 20px;
    }

    thead tr {
      background: #1E3A8A;
      color: white;
    }

    thead th {
      padding: 10px 9px;
      text-align: left;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .7px;
      white-space: nowrap;
    }

    thead th:first-child { border-radius: 0; }

    tbody tr:nth-child(even) { background: #f0f5ff; }
    tbody tr:nth-child(odd)  { background: #ffffff; }

    tbody tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }

    tbody td {
      padding: 8px 9px;
      vertical-align: top;
      border-bottom: 1px solid #e5e7eb;
      color: #374151;
      word-break: break-word;
    }

    .td-num {
      color: #9ca3af;
      font-size: 10px;
      font-weight: 600;
      text-align: center;
      white-space: nowrap;
    }

    .td-nombre {
      font-weight: 700;
      color: #111827;
      font-size: 12px;
    }

    .td-dni { font-family: 'Courier New', monospace; color: #374151; }

    .td-tipo {
      font-size: 10px;
      color: #6b7280;
      display: block;
      margin-top: 2px;
    }

    .td-empty { color: #d1d5db; }

    .dist-chip {
      background: #eff6ff;
      color: #1E3A8A;
      border-radius: 12px;
      padding: 2px 8px;
      font-size: 10.5px;
      font-weight: 600;
      white-space: nowrap;
      display: inline-block;
    }

    .apoyo-text { color: #4b5563; font-size: 11px; }

    .fecha-text { color: #6b7280; font-size: 10.5px; white-space: nowrap; }

    /* ── Footer del documento ─────────────────────────────── */
    .doc-footer {
      background: #1E3A8A;
      color: rgba(255,255,255,.65);
      text-align: center;
      font-size: 10px;
      padding: 12px 36px;
      letter-spacing: .3px;
    }

    .doc-footer strong { color: white; }

    /* ── Estilos de impresión ─────────────────────────────── */
    @media print {
      @page {
        size: A4 landscape;
        margin: 12mm 10mm;
      }

      body {
        background: white;
        font-size: 10px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
      }

      /* Ocultar barra de herramientas */
      .toolbar { display: none !important; }

      .print-wrap {
        max-width: 100%;
        margin: 0;
        border-radius: 0;
        box-shadow: none;
      }

      .doc-header { padding: 16px 20px; }
      .logo-name { font-size: 16px; }
      .doc-meta strong { font-size: 14px; }

      .stats-bar { padding: 10px 20px; }

      .table-wrap { padding: 0 20px 16px; }

      table { font-size: 9px; margin-top: 12px; }
      thead th { padding: 7px 6px; font-size: 8px; }
      tbody td { padding: 5px 6px; }

      .logo-accent { display: none; }

      tbody tr { page-break-inside: avoid; break-inside: avoid; }

      .doc-footer { padding: 8px 20px; font-size: 9px; }
    }
  </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- TOOLBAR (oculta al imprimir)                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($download): ?>
<script>window.addEventListener('load', function() { window.print(); });</script>
<?php endif; ?>
<div class="toolbar" <?= $embed ? 'style="display:none"' : '' ?>>
  <span class="toolbar-title">Vista previa para imprimir / PDF</span>
  <div class="toolbar-actions">
    <button class="btn-print" onclick="window.print()">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
      </svg>
      Imprimir / Guardar PDF
    </button>
    <a class="btn-back" href="simpatizantes.php">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Volver
    </a>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- DOCUMENTO                                                  -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="print-wrap">

  <!-- Encabezado -------------------------------------------- -->
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-name"><?= htmlspecialchars($brand_name) ?></div>
      <div class="logo-partido"><?= htmlspecialchars($partido_nombre) ?></div>
      <div class="logo-accent"></div>
    </div>
    <div class="doc-meta">
      <strong>Lista de Simpatizantes</strong>
      Generado el: <?= $fecha_generado ?><br>
      Total registros: <?= $total ?><br>
      Uso interno — Confidencial
    </div>
  </div>

  <!-- Stats ------------------------------------------------- -->
  <div class="stats-bar">
    <div class="stat-total">
      <span class="num"><?= $total ?></span>
      <span class="lbl">Simpatizantes</span>
    </div>
    <div class="distritos-wrap">
      <span class="distritos-label">Por distrito:</span>
      <?php foreach ($por_distrito as $dist => $cnt): ?>
      <span class="dist-badge">
        <?= htmlspecialchars($dist) ?><span><?= $cnt ?></span>
      </span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tabla ------------------------------------------------- -->
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:34px">N°</th>
          <th>Apellidos y nombres</th>
          <th>DNI</th>
          <th>Cel</th>
          <th>WhatsApp</th>
          <th>Correo</th>
          <th>Distrito</th>
          <th>Cómo apoya</th>
          <th>Fecha registro</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($simpatizantes)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;">
            No hay simpatizantes registrados.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($simpatizantes as $i => $r): ?>
        <tr>
          <td class="td-num"><?= $i + 1 ?></td>
          <td class="td-nombre"><?= htmlspecialchars($r['nombre']) ?></td>
          <td class="td-dni"><?= htmlspecialchars($r['dni'] ?? '') ?></td>
          <td>
            <?php
              $cel = $r['celular'] ?: ($r['telefono'] ?? '');
              echo $cel !== '' ? htmlspecialchars($cel) : '<span class="td-empty">—</span>';
            ?>
          </td>
          <td><?= $r['whatsapp'] !== '' && $r['whatsapp'] !== null ? htmlspecialchars($r['whatsapp']) : '<span class="td-empty">—</span>' ?></td>
          <td><?= $r['correo']   !== '' && $r['correo']   !== null ? htmlspecialchars($r['correo'])   : '<span class="td-empty">—</span>' ?></td>
          <td>
            <?php if (!empty($r['distrito'])): ?>
            <span class="dist-chip"><?= htmlspecialchars($r['distrito']) ?></span>
            <?php else: ?>
            <span class="td-empty">—</span>
            <?php endif; ?>
          </td>
          <td class="apoyo-text">
            <?= $r['formas_apoyo'] !== '' && $r['formas_apoyo'] !== null ? htmlspecialchars($r['formas_apoyo']) : '<span class="td-empty">—</span>' ?>
          </td>
          <td class="fecha-text">
            <?php
              if (!empty($r['fecha_registro'])) {
                  echo date('d/m/Y', strtotime($r['fecha_registro']));
              } else {
                  echo '<span class="td-empty">—</span>';
              }
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Footer ------------------------------------------------ -->
  <div class="doc-footer">
    Documento generado el <strong><?= $fecha_generado ?></strong>
    &nbsp;—&nbsp; Portal <strong><?= htmlspecialchars($brand_name) ?></strong>
    &nbsp;—&nbsp; <strong>CONFIDENCIAL</strong>
  </div>

</div><!-- /.print-wrap -->

</body>
</html>
