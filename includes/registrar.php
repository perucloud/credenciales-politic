<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

$nombre = trim($_POST['nombre'] ?? '');
$dni = preg_replace('/\D+/', '', $_POST['dni'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$distrito = trim($_POST['distrito'] ?? '');
$tipo_documento = trim($_POST['tipo_documento'] ?? 'DNI');
$correo = trim($_POST['correo'] ?? '') ?: null;
$celular = trim($_POST['celular'] ?? '') ?: null;
$whatsapp = trim($_POST['whatsapp'] ?? '') ?: null;
$formas_apoyo = trim($_POST['formas_apoyo'] ?? '') ?: null;

if ($nombre === '' || $dni === '' || $telefono === '' || $distrito === '') {
    echo json_encode(['ok' => false, 'error' => 'Completa nombre, DNI, telefono y distrito.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{8}$/', $dni)) {
    echo json_encode(['ok' => false, 'error' => 'El DNI debe tener 8 digitos.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'El correo ingresado no es valido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $dup = $pdo->prepare("SELECT id FROM simpatizantes WHERE dni = ? LIMIT 1");
    $dup->execute([$dni]);
    if ($dup->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Este DNI ya se encuentra registrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO simpatizantes
         (nombre, dni, telefono, distrito, tipo_documento, correo, celular, whatsapp, formas_apoyo, fecha_registro)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$nombre, $dni, $telefono, $distrito, $tipo_documento, $correo, $celular, $whatsapp, $formas_apoyo]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (function_exists('app_log')) {
        app_log('ERROR', 'Fallo registro publico de simpatizante: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => 'No se pudo completar el registro. Intentalo nuevamente.'], JSON_UNESCAPED_UNICODE);
}
