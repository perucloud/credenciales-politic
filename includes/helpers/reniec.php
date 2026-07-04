<?php
/**
 * Integracion con API de consulta RENIEC por DNI (proveedor json.pe).
 *
 * El token se lee de includes/config/reniec.local.php (no versionado, ver
 * reniec.local.php.example) o de la variable de entorno IVAN_RENIEC_API_TOKEN.
 * Mientras no haya token configurado, consultar_reniec_dni() retorna null y
 * el flujo sigue funcionando solo con los datos de la base interna.
 */

$reniec_local_file = __DIR__ . '/../config/reniec.local.php';
$reniec_local = is_file($reniec_local_file) ? require $reniec_local_file : [];
if (!is_array($reniec_local)) $reniec_local = [];

define('RENIEC_API_URL', getenv('IVAN_RENIEC_API_URL') ?: ($reniec_local['api_url'] ?? 'https://api.json.pe/api/dni'));
define('RENIEC_API_TOKEN', getenv('IVAN_RENIEC_API_TOKEN') ?: ($reniec_local['api_token'] ?? ''));

/**
 * Consulta los datos de una persona por DNI.
 * Retorna:
 * [
 *   'nombres' => ...,
 *   'apellido_paterno' => ...,
 *   'apellido_materno' => ...,
 *   'nombre_completo' => ...
 * ]
 * o null si no hay configuracion, no responde, o el DNI no existe.
 */
function consultar_reniec_dni(string $dni): ?array
{
    if (RENIEC_API_URL === '' || RENIEC_API_TOKEN === '') {
        if (function_exists('app_log')) {
            app_log('RENIEC', 'Token o URL RENIEC no configurado.');
        }
        return null;
    }
    if (!preg_match('/^\d{8}$/', $dni)) return null;

    $ch = curl_init(RENIEC_API_URL);
    if ($ch === false) {
        if (function_exists('app_log')) {
            app_log('RENIEC', 'No se pudo inicializar cURL.');
        }
        return null;
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['dni' => $dni]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RENIEC_API_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];

    // En produccion Linux se deja que cURL use su bundle CA del servidor.
    // En Laragon/Windows solo se fuerza CAINFO si el archivo existe realmente.
    $caInfo = (string)ini_get('curl.cainfo');
    if ($caInfo !== '' && is_file($caInfo)) {
        $curlOptions[CURLOPT_CAINFO] = $caInfo;
    } elseif (PHP_OS_FAMILY === 'Windows' && is_file('C:/laragon/etc/ssl/cacert.pem')) {
        $curlOptions[CURLOPT_CAINFO] = 'C:/laragon/etc/ssl/cacert.pem';
    }

    curl_setopt_array($ch, $curlOptions);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        if (function_exists('app_log')) {
            app_log('RENIEC', 'Fallo consulta API RENIEC.', [
                'dni' => $dni,
                'http_code' => $code,
                'curl_error' => $error,
                'body' => is_string($body) ? mb_substr($body, 0, 500, 'UTF-8') : null,
            ]);
        }
        return null;
    }

    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['success']) || !isset($json['data']) || !is_array($json['data'])) {
        if (function_exists('app_log')) {
            app_log('RENIEC', 'Respuesta RENIEC no contiene datos validos.', [
                'dni' => $dni,
                'body' => mb_substr($body, 0, 500, 'UTF-8'),
            ]);
        }
        return null;
    }
    $data = $json['data'];

    $nombres    = trim((string)($data['nombres'] ?? ''));
    $ap_paterno = trim((string)($data['apellido_paterno'] ?? $data['apellidoPaterno'] ?? ''));
    $ap_materno = trim((string)($data['apellido_materno'] ?? $data['apellidoMaterno'] ?? ''));

    $nombre_completo = trim((string)($data['nombre_completo'] ?? $data['nombreCompleto'] ?? ''));
    if ($nombre_completo === '') {
        $nombre_completo = trim("$ap_paterno $ap_materno $nombres");
    }
    if ($nombre_completo === '') return null;

    return [
        'nombres'           => $nombres,
        'apellido_paterno'  => $ap_paterno,
        'apellido_materno'  => $ap_materno,
        'nombre_completo'   => $nombre_completo,
    ];
}
