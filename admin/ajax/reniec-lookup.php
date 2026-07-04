<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../../includes/helpers/reniec.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$dni = preg_replace('/\D/', '', $_GET['dni'] ?? $_POST['dni'] ?? '');
if (strlen($dni) !== 8) {
    echo json_encode(['ok' => false, 'msg' => 'DNI debe tener 8 dígitos.']);
    exit;
}

$datos = consultar_reniec_dni($dni);
if (!$datos) {
    echo json_encode(['ok' => false, 'msg' => 'No se encontraron datos para ese DNI.']);
    exit;
}

echo json_encode(['ok' => true, 'data' => $datos]);
