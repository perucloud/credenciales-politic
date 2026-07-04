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

function ce_image_data_uri(string $rel): string {
    $path = dirname(__DIR__) . '/' . ltrim($rel, '/');
    if (!is_file($path)) return '';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    $data = base64_encode((string)file_get_contents($path));
    return 'data:' . $mime . ';base64,' . $data;
}

$id = (int)($_GET['id'] ?? 0);
$download = (int)($_GET['download'] ?? 0) === 1;
$save_public = (int)($_GET['save'] ?? 0) === 1;

$stmt = $pdo->prepare("SELECT * FROM credenciales_escaneadas WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'Credencial escaneada no encontrada.';
    exit;
}

$img = ce_image_data_uri((string)$row['archivo']);
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
  @page { size: A4 portrait; margin: 0; }
  html, body { margin: 0; padding: 0; width: 210mm; height: 297mm; }
  body { font-family: DejaVu Sans, sans-serif; background: #fff; }
  .page { position: relative; width: 210mm; height: 297mm; overflow: hidden; }
  .scan-wrap { position: absolute; inset: 0; text-align: center; }
  .scan {
    width: 210mm;
    height: 297mm;
    display: block;
  }
</style></head><body>
  <div class="page">
    <div class="scan-wrap">' . ($img ? '<img class="scan" src="' . $img . '">' : '<p>Imagen no disponible.</p>') . '</div>
  </div>
</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$safe = preg_replace('/[^0-9A-Za-z_-]+/', '_', (string)$row['dni']);
$filename = 'credencial_escaneada_' . $safe . '.pdf';

if ($save_public) {
    $pdf_dir = dirname(__DIR__) . '/uploads/credenciales-escaneadas/pdf';
    if (!is_dir($pdf_dir) && !mkdir($pdf_dir, 0775, true) && !is_dir($pdf_dir)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'No se pudo crear la carpeta PDF.']);
        exit;
    }
    $pdf_path = $pdf_dir . '/' . $filename;
    if (@file_put_contents($pdf_path, $dompdf->output()) === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el PDF.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'url' => BASE_URL . '/uploads/credenciales-escaneadas/pdf/' . rawurlencode($filename),
        'file' => 'uploads/credenciales-escaneadas/pdf/' . $filename,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dompdf->stream($filename, ['Attachment' => $download]);
