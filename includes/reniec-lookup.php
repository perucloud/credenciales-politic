<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers/reniec.php';

header('Content-Type: application/json; charset=utf-8');

$dni = preg_replace('/\D/', '', $_GET['dni'] ?? '');
if (strlen($dni) !== 8) {
    echo json_encode(['ok' => false, 'msg' => 'DNI debe tener 8 dígitos.']);
    exit;
}

$datos = consultar_reniec_dni($dni);
if (!$datos) {
    echo json_encode(['ok' => false, 'msg' => 'No se encontraron datos.']);
    exit;
}

echo json_encode(['ok' => true, 'data' => $datos]);
