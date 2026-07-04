<?php
// ============================================================
// CONFIGURACION SMTP - Credenciales App
// Usar variables de entorno en produccion para no guardar claves
// dentro del codigo fuente.
// ============================================================

$mail_local_file = __DIR__ . '/mail.local.php';
$mail_local = is_file($mail_local_file) ? require $mail_local_file : [];
if (!is_array($mail_local)) {
    $mail_local = [];
}

define('MAIL_HOST', getenv('JOYER_MAIL_HOST') ?: ($mail_local['host'] ?? 'sistemaonline.net.pe'));
define('MAIL_PORT', (int)(getenv('JOYER_MAIL_PORT') ?: ($mail_local['port'] ?? 465)));
define('MAIL_SECURE', getenv('JOYER_MAIL_SECURE') ?: ($mail_local['secure'] ?? 'ssl'));
define('MAIL_USER', getenv('JOYER_MAIL_USER') ?: ($mail_local['user'] ?? 'pruebacorreo@sistemaonline.net.pe'));
define('MAIL_PASS', getenv('JOYER_MAIL_PASS') ?: ($mail_local['pass'] ?? ''));
define('MAIL_FROM', getenv('JOYER_MAIL_FROM') ?: ($mail_local['from'] ?? MAIL_USER));
define('MAIL_FROM_NAME', getenv('CRED_MAIL_FROM_NAME') ?: ($mail_local['from_name'] ?? 'Credenciales App'));
