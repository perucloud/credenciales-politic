<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config/db.php';

// Limpiar remember_token en la BD si existe la cookie
if (!empty($_COOKIE['remember_admin'])) {
    $raw_token = $_COOKIE['remember_admin'];
    if (strlen($raw_token) === 64 && ctype_alnum($raw_token)) {
        try {
            $pdo->prepare("UPDATE usuarios SET remember_token = NULL WHERE remember_token = ?")
                ->execute([$raw_token]);
        } catch (Exception $e) {}
    }
    setcookie('remember_admin', '', time() - 3600, '/', '', false, true);
}

session_destroy();
header('Location: login.php');
exit;
