<?php
// Generador de codigos QR (libreria endroid/qr-code via Composer).
// Devuelve los bytes PNG del QR, o false si falla.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;

function credencial_verify_url(string $token, bool $short = true): string
{
    $base = defined('QR_PUBLIC_BASE_URL')
        ? QR_PUBLIC_BASE_URL
        : (defined('BASE_URL') ? BASE_URL : '');
    $base = rtrim($base, '/');
    $token = rawurlencode($token);

    return $short
        ? $base . '/v/' . $token
        : $base . '/verificar-credencial.php?t=' . $token;
}

function generar_qr_png(string $contenido, int $size = 400, ?string $color_hex = null): string|false
{
    try {
        $color = $color_hex ? hex_to_qr_color($color_hex) : new Color(0, 0, 0);

        $qrCode = new QrCode(
            data: $contenido,
            size: $size,
            margin: 8,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            foregroundColor: $color,
            backgroundColor: new Color(255, 255, 255),
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return $result->getString();
    } catch (Throwable $e) {
        return false;
    }
}

// Genera el QR y lo guarda como archivo PNG en disco. Devuelve la ruta relativa o false.
function generar_qr_archivo(string $contenido, string $dir_absoluto, string $nombre_base, int $size = 400, ?string $color_hex = null): string|false
{
    $bytes = generar_qr_png($contenido, $size, $color_hex);
    if ($bytes === false) return false;

    if (!is_dir($dir_absoluto)) mkdir($dir_absoluto, 0755, true);

    $nombre = $nombre_base . '.png';
    file_put_contents($dir_absoluto . '/' . $nombre, $bytes);

    return $nombre;
}

function hex_to_qr_color(string $hex): Color
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return new Color($r, $g, $b);
}
