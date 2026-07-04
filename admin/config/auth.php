<?php
// ============================================================
// auth.php - Helper centralizado de autenticacion y roles
// Incluir DESPUES de session_start() y db.php
// (Version simplificada para credenciales-app: solo roles editor/admin)
// ============================================================

function require_login(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function get_rol(): string {
    return $_SESSION['admin_rol'] ?? 'editor';
}

function rol_jerarquia(): array {
    return ['editor' => 1, 'admin' => 2, 'superadmin' => 3];
}

function is_admin_or_above(): bool {
    return in_array(get_rol(), ['admin', 'superadmin'], true);
}

function is_superadmin(): bool {
    return get_rol() === 'superadmin';
}

/**
 * Devuelve null si el usuario tiene acceso sin restriccion (superadmin o editor),
 * o el array de modulos asignados si es admin.
 */
function usuario_modulos_permitidos(PDO $pdo, int $usuario_id): ?array {
    if (get_rol() !== 'admin') return null;
    try {
        $stmt = $pdo->prepare("SELECT modulo FROM usuario_permisos_modulo WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return null;
    }
}

function require_modulo(PDO $pdo, string $modulo): void {
    $modulos = usuario_modulos_permitidos($pdo, (int)($_SESSION['admin_id'] ?? 0));
    if ($modulos !== null && !in_array($modulo, $modulos, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
        <script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-gray-50 flex items-center justify-center">
        <div class="text-center p-10">
          <div class="text-6xl mb-4">!</div>
          <h1 class="text-2xl font-black text-gray-800 mb-2">Acceso denegado</h1>
          <p class="text-gray-500 mb-6">No tienes permisos para acceder a este modulo.</p>
          <a href="' . BASE_URL . '/admin/dashboard.php"
             class="bg-[#1E3A8A] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-900 transition-all">
            Volver al dashboard
          </a>
        </div></body></html>';
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_request_token(): string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    return (string)($_POST['_csrf'] ?? $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '');
}

function csrf_verify(bool $json = false): void {
    $token = csrf_request_token();
    if ($token !== '' && hash_equals(csrf_token(), $token)) return;
    http_response_code(419);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido.']);
    } else {
        echo 'Token CSRF invalido. Recarga la pagina e intenta nuevamente.';
    }
    exit;
}

function require_rol(string $min_rol): void {
    $jerarquia = rol_jerarquia();
    $user_nivel = $jerarquia[get_rol()] ?? 0;
    $req_nivel  = $jerarquia[$min_rol]  ?? 99;
    if ($user_nivel < $req_nivel) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
        <script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-gray-50 flex items-center justify-center">
        <div class="text-center p-10">
          <div class="text-6xl mb-4">!</div>
          <h1 class="text-2xl font-black text-gray-800 mb-2">Acceso denegado</h1>
          <p class="text-gray-500 mb-6">No tienes permisos para acceder a esta seccion.</p>
          <a href="' . BASE_URL . '/admin/dashboard.php"
             class="bg-[#1E3A8A] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-blue-900 transition-all">
            Volver al dashboard
          </a>
        </div></body></html>';
        exit;
    }
}

function log_activity(PDO $pdo, string $accion, string $modulo = ''): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (usuario_id, usuario_nombre, accion, modulo, ip)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['admin_id']     ?? 0,
            $_SESSION['admin_nombre'] ?? 'Desconocido',
            $accion,
            $modulo,
            $_SERVER['REMOTE_ADDR']   ?? ''
        ]);
    } catch (Exception $e) {}
}

function rol_badge(string $rol): string {
    $map = [
        'superadmin' => '<span class="bg-purple-100 text-purple-700 text-xs font-bold px-2 py-0.5 rounded-full">Superadmin</span>',
        'admin'      => '<span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-0.5 rounded-full">Admin</span>',
        'editor'     => '<span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded-full">Editor</span>',
    ];
    return $map[$rol] ?? '<span class="bg-gray-100 text-gray-600 text-xs font-bold px-2 py-0.5 rounded-full">' . htmlspecialchars($rol) . '</span>';
}
