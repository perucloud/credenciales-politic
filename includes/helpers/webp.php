<?php
// Convierte un archivo de imagen subido a WebP.
// Devuelve los bytes WebP como string, o false si falla.
// Soporta: jpg, jpeg, png, gif, webp (pass-through sin re-encodear).
function img_to_webp(string $tmp_path, string $ext, int $quality = 82): string|false
{
    $ext = strtolower($ext);

    // WebP ya subido → leer directo sin re-encodear
    if ($ext === 'webp') {
        return @file_get_contents($tmp_path) ?: false;
    }

    // ── Intento 1: GD ────────────────────────────────────────
    if (function_exists('imagewebp')) {
        $img = match($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($tmp_path),
            'png'         => @imagecreatefrompng($tmp_path),
            'gif'         => @imagecreatefromgif($tmp_path),
            default       => false,
        };

        if ($img !== false) {
            // Preservar transparencia PNG/GIF
            if (in_array($ext, ['png', 'gif'])) {
                $w   = imagesx($img);
                $h   = imagesy($img);
                $tmp = imagecreatetruecolor($w, $h);
                imagealphablending($tmp, false);
                imagesavealpha($tmp, true);
                $trans = imagecolorallocatealpha($tmp, 255, 255, 255, 127);
                imagefilledrectangle($tmp, 0, 0, $w, $h, $trans);
                imagealphablending($tmp, true);
                imagecopy($tmp, $img, 0, 0, 0, 0, $w, $h);
                imagedestroy($img);
                $img = $tmp;
            }

            ob_start();
            $ok = imagewebp($img, null, $quality);
            $data = ob_get_clean();
            imagedestroy($img);

            if ($ok && strlen($data) > 0) return $data;
        }
    }

    // ── Intento 2: Imagick ────────────────────────────────────
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $im = new Imagick($tmp_path);
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality($quality);
            $im->stripImage();
            $data = $im->getImageBlob();
            $im->clear();
            if (strlen($data) > 0) return $data;
        } catch (Throwable $e) {}
    }

    return false;
}
