<?php
// JSON para refrescar la Biblioteca de Medios desde pickers embebidos.
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_login();
require_rol('editor');

$files = [];
try {
    $stmt = $pdo->query("SELECT id, nombre, ruta, tipo, tamanio FROM media_files ORDER BY creado_en DESC, id DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tipo = (string)($row['tipo'] ?? '');
        $files[] = [
            'id'      => (int)$row['id'],
            'nombre'  => (string)$row['nombre'],
            'url'     => BASE_URL . '/uploads/media/' . $row['ruta'],
            'tipo'    => $tipo,
            'tamanio' => (int)($row['tamanio'] ?? 0),
            'es_img'  => str_starts_with($tipo, 'image/'),
            'es_pdf'  => $tipo === 'application/pdf',
        ];
    }
    echo json_encode(['ok' => true, 'files' => $files], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'files' => [], 'error' => 'No se pudo cargar la biblioteca.']);
}
