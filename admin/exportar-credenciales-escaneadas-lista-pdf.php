<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

require_login();
require_modulo($pdo, 'credenciales_escaneadas');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$brand_name = 'Sistema de Gestión de Credenciales';
try {
    $bn = $pdo->query("SELECT valor FROM configuracion WHERE clave='site_brand_name' LIMIT 1")->fetchColumn();
    if ($bn) $brand_name = $bn;
} catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS credenciales_escaneadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dni CHAR(8) NOT NULL,
    nombres_completos VARCHAR(220) NOT NULL,
    lugar VARCHAR(180) NULL,
    archivo VARCHAR(300) NOT NULL,
    archivo_nombre VARCHAR(180) NULL,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL,
    INDEX idx_ce_dni (dni),
    INDEX idx_ce_nombre (nombres_completos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$download = (int)($_GET['download'] ?? 0) === 1;
$rows = $pdo->query("SELECT dni, nombres_completos, lugar, creado_en FROM credenciales_escaneadas ORDER BY id DESC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$fecha = date('d/m/Y H:i');

$trs = '';
$i = 1;
foreach ($rows as $r) {
    $trs .= '<tr>
      <td class="num">' . $i++ . '</td>
      <td class="mono">' . h($r['dni']) . '</td>
      <td class="name">' . h($r['nombres_completos']) . '</td>
      <td>' . h($r['lugar']) . '</td>
      <td class="date">' . h($r['creado_en']) . '</td>
    </tr>';
}
if ($trs === '') {
    $trs = '<tr><td colspan="5" class="empty">No hay credenciales escaneadas registradas.</td></tr>';
}

$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
  @page { margin: 24px 24px 36px; }
  body { font-family: DejaVu Sans, sans-serif; color:#111827; font-size: 11px; }
  .head { background:#1E3A8A; color:white; padding:16px 18px; border-radius:10px; margin-bottom:14px; }
  .head h1 { margin:0; font-size:18px; font-weight:900; }
  .head p { margin:4px 0 0; font-size:10px; color:#DBEAFE; }
  .summary { margin-bottom:12px; color:#475569; font-size:10px; }
  table { width:100%; border-collapse:collapse; }
  th { background:#F1F5F9; color:#475569; text-transform:uppercase; font-size:9px; text-align:left; padding:8px; border:1px solid #E2E8F0; }
  td { padding:8px; border:1px solid #E5E7EB; vertical-align:top; }
  tr:nth-child(even) td { background:#FAFAFA; }
  .num { width:28px; text-align:center; color:#64748B; }
  .mono { font-family: DejaVu Sans Mono, monospace; width:70px; }
  .name { font-weight:800; }
  .date { width:110px; color:#64748B; font-size:9px; }
  .empty { text-align:center; padding:32px; color:#94A3B8; font-weight:800; }
  .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color:#94A3B8; border-top:1px solid #E5E7EB; padding-top: 6px; }
</style></head><body>
  <div class="head">
    <h1>Lista detallada de credenciales escaneadas</h1>
    <p>Credenciales fisicas entregadas y registradas en el panel administrativo</p>
  </div>
  <div class="summary"><strong>Total:</strong> ' . count($rows) . ' registros &nbsp; | &nbsp; <strong>Generado:</strong> ' . h($fecha) . '</div>
  <table>
    <thead><tr><th>N</th><th>DNI</th><th>Nombres y apellidos</th><th>Lugar</th><th>Registrado</th></tr></thead>
    <tbody>' . $trs . '</tbody>
  </table>
  <div class="footer">' . h($brand_name) . ' - Panel Admin | ' . h($fecha) . '</div>
</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('lista_credenciales_escaneadas_' . date('Ymd_His') . '.pdf', ['Attachment' => $download]);
