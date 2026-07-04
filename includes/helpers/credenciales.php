<?php
// ============================================================
// Helpers compartidos para mostrar/enmascarar datos de credenciales.
// Usado por verificar-credencial.php (publico) y los exportadores PDF (admin).
// ============================================================

if (!function_exists('fecha_es')) {
    function fecha_es(?string $f): string {
        if (!$f) return '—';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('enmascarar_nombre')) {
    // Nombre: primer nombre visible, segundo nombre como inicial y apellidos parcialmente enmascarados.
    function enmascarar_nombre(string $nombres_completos): string {
        $normalizado = str_replace(',', ' ', trim($nombres_completos));
        $partes = preg_split('/\s+/', $normalizado);
        $partes = array_values(array_filter($partes, fn($p) => $p !== ''));
        if (count($partes) === 0) return '---';
        if (count($partes) === 1) return $partes[0];

        $out = [];
        foreach ($partes as $idx => $parte) {
            $parte_fmt = mb_convert_case($parte, MB_CASE_TITLE, 'UTF-8');
            if ($idx === 0) {
                $out[] = $parte_fmt;
            } elseif ($idx === 1 && count($partes) >= 4) {
                $out[] = mb_substr($parte_fmt, 0, 1, 'UTF-8') . '.';
            } else {
                $out[] = mb_substr($parte_fmt, 0, 4, 'UTF-8') . '****';
            }
        }

        return implode(' ', $out);
    }
}

if (!function_exists('enmascarar_dni')) {
    // DNI: primeros 3 dígitos + asteriscos
    function enmascarar_dni(string $dni): string {
        $dni = trim($dni);
        if (mb_strlen($dni) < 3) return str_repeat('*', mb_strlen($dni));
        return mb_substr($dni, 0, 3) . str_repeat('*', max(0, mb_strlen($dni) - 3));
    }
}

if (!function_exists('estado_info')) {
    function estado_info(string $estado): array {
        return match ($estado) {
            'activo'  => ['label' => 'ACTIVA',  'color' => '#059669', 'bg' => '#D1FAE5', 'desc' => 'Esta credencial se encuentra vigente.'],
            'vencido' => ['label' => 'VENCIDA', 'color' => '#D97706', 'bg' => '#FEF3C7', 'desc' => 'Esta credencial superó su fecha de vencimiento.'],
            'anulado' => ['label' => 'ANULADA', 'color' => '#DC2626', 'bg' => '#FEE2E2', 'desc' => 'Esta credencial fue anulada y ya no es válida.'],
            default   => ['label' => strtoupper($estado), 'color' => '#6B7280', 'bg' => '#F3F4F6', 'desc' => ''],
        };
    }
}

if (!function_exists('credencial_payload_publico')) {
    function credencial_payload_publico(array $row, string $partido_nombre): array {
        $info = estado_info((string)$row['estado']);
        return [
            'partido'             => $partido_nombre,
            'apellidos_nombres'   => enmascarar_nombre((string)$row['nombres_completos']),
            'cargo'               => $row['cargo'] ?: 'Acreditado',
            'dni'                 => enmascarar_dni((string)$row['dni']),
            'caduca'              => fecha_es($row['fecha_vencimiento'] ?? null),
            'provincia'           => $row['provincia'] ?: '---',
            'distrito'            => $row['distrito'] ?: '---',
            'codigo'              => $row['codigo'] ?? '',
            'estado'              => $info['label'],
            'estado_color'        => $info['color'],
            'estado_bg'           => $info['bg'],
            'estado_descripcion'  => $info['desc'],
        ];
    }
}
