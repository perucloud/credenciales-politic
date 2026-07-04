<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config/db.php';

header('Location: ' . BASE_URL . '/admin/' . (!empty($_SESSION['admin_id']) ? 'dashboard.php' : 'login.php'));
exit;
