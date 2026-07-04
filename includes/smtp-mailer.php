<?php

function smtp_mime_header(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_clean_header(string $value): string {
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function smtp_read_response($socket): array {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return [(int)substr($data, 0, 3), trim($data)];
}

function smtp_command($socket, ?string $command, array $expected, ?string &$error): bool {
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }

    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expected, true)) {
        $error = $response ?: 'Respuesta SMTP inesperada.';
        return false;
    }

    return true;
}

function smtp_send_mail(string $to_email, string $to_name, string $subject, string $body, ?string &$error = null, array $attachments = []): bool {
    $error = null;

    if (!defined('MAIL_PASS') || MAIL_PASS === '') {
        $error = 'SMTP no configurado: falta JOYER_MAIL_PASS.';
        return false;
    }

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Correo no valido.';
        return false;
    }

    $host = MAIL_HOST;
    $port = MAIL_PORT;
    $transport = MAIL_SECURE === 'ssl' ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 20);

    if (!$socket) {
        $error = 'No se pudo conectar al SMTP: ' . ($errstr ?: 'conexion fallida');
        return false;
    }

    stream_set_timeout($socket, 20);
    $local = $_SERVER['SERVER_NAME'] ?? 'localhost';

    if (!smtp_command($socket, null, [220], $error)
        || !smtp_command($socket, 'EHLO ' . $local, [250], $error)
        || !smtp_command($socket, 'AUTH LOGIN', [334], $error)
        || !smtp_command($socket, base64_encode(MAIL_USER), [334], $error)
        || !smtp_command($socket, base64_encode(MAIL_PASS), [235], $error)
        || !smtp_command($socket, 'MAIL FROM:<' . MAIL_FROM . '>', [250], $error)
        || !smtp_command($socket, 'RCPT TO:<' . $to_email . '>', [250, 251], $error)
        || !smtp_command($socket, 'DATA', [354], $error)) {
        fclose($socket);
        return false;
    }

    $from_name = smtp_mime_header(MAIL_FROM_NAME);
    $to_label = $to_name !== '' ? smtp_mime_header(smtp_clean_header($to_name)) . ' <' . $to_email . '>' : $to_email;
    $safe_subject = smtp_mime_header(smtp_clean_header($subject));
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)), preg_replace('/^www\./', '', $local));

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $from_name . ' <' . MAIL_FROM . '>',
        'To: ' . $to_label,
        'Subject: ' . $safe_subject,
        'Message-ID: ' . $message_id,
        'MIME-Version: 1.0',
    ];

    $normalized_body = str_replace(["\r\n", "\r"], "\n", $body);
    $normalized_body = preg_replace('/^\./m', '..', $normalized_body);

    if (empty($attachments)) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalized_body);
    } else {
        $boundary = 'b_' . bin2hex(random_bytes(14));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = [];
        $parts[] = '--' . $boundary . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
            . str_replace("\n", "\r\n", $normalized_body) . "\r\n";

        foreach ($attachments as $attachment) {
            $path = $attachment['path'] ?? '';
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $filename = smtp_clean_header($attachment['name'] ?? basename($path));
            $mime = smtp_clean_header($attachment['mime'] ?? 'application/octet-stream');
            $encoded = chunk_split(base64_encode((string)file_get_contents($path)), 76, "\r\n");
            $parts[] = '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $mime . '; name="' . addslashes($filename) . '"' . "\r\n"
                . 'Content-Transfer-Encoding: base64' . "\r\n"
                . 'Content-Disposition: attachment; filename="' . addslashes($filename) . '"' . "\r\n\r\n"
                . $encoded . "\r\n";
        }

        $parts[] = '--' . $boundary . '--';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . implode('', $parts);
    }

    fwrite($socket, $payload . "\r\n.\r\n");
    $ok = smtp_command($socket, null, [250], $error);
    $quit_error = null;
    smtp_command($socket, 'QUIT', [221], $quit_error);
    fclose($socket);

    return $ok;
}
