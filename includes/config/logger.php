<?php
// ============================================================
// SISTEMA DE LOGS - Credenciales App
// Archivos en /logs/ (fuera de acceso web via .htaccess)
// Funciona igual en localhost y produccion.
// ============================================================

define('LOG_DIR', dirname(__DIR__, 2) . '/logs');

// Redirige los errores nativos de PHP al archivo php-errors.log
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php-errors.log');
ini_set('display_errors', '0');       // nunca mostrar en pantalla
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);               // capturar todo, pero solo al log

// ── Escritura al log de aplicacion ──────────────────────────
function app_log(string $nivel, string $mensaje, array $contexto = []): void {
    $archivo = LOG_DIR . '/app-errors.log';
    $url = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' '
         . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . ($_SERVER['REQUEST_URI'] ?? '');
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $linea = sprintf(
        "[%s] [%s] %s | URL: %s | IP: %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($nivel),
        $mensaje,
        $url,
        $ip,
        $contexto ? ' | ' . json_encode($contexto, JSON_UNESCAPED_UNICODE) : ''
    );
    _log_write($archivo, $linea);
}

// ── Handler global de excepciones no capturadas ─────────────
set_exception_handler(function (Throwable $e) {
    app_log('EXCEPTION', get_class($e) . ': ' . $e->getMessage(), [
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'trace' => _log_trace($e->getTraceAsString()),
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor.']);
    exit;
});

// ── Handler global de errores fatales/advertencias ──────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    $niveles = [
        E_ERROR        => 'ERROR',    E_WARNING     => 'WARNING',
        E_NOTICE       => 'NOTICE',   E_DEPRECATED  => 'DEPRECATED',
        E_USER_ERROR   => 'ERROR',    E_USER_WARNING=> 'WARNING',
        E_USER_NOTICE  => 'NOTICE',
    ];
    $nivel = $niveles[$errno] ?? 'UNKNOWN';
    app_log($nivel, $errstr, ['file' => $errfile . ':' . $errline]);
    return false; // deja que PHP siga su flujo normal
});

// ── Captura fatales que no llegan al error_handler ──────────
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('FATAL', $e['message'], ['file' => $e['file'] . ':' . $e['line']]);
    }
});

// ── Helpers internos ─────────────────────────────────────────
function _log_write(string $archivo, string $linea): void {
    // Rota si supera 2 MB: guarda las ultimas 1000 lineas
    if (is_file($archivo) && filesize($archivo) > 2 * 1024 * 1024) {
        $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        file_put_contents($archivo, implode("\n", array_slice($lineas, -1000)) . "\n");
    }
    file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}

function _log_trace(string $trace): string {
    // Recorta el trace a las primeras 5 lineas para no inflar el log
    $lineas = explode("\n", $trace);
    return implode(' | ', array_slice($lineas, 0, 5));
}
