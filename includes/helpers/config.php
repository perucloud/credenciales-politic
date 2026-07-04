<?php
// ============================================================
// Helpers simples para configuracion key/value del sitio.
// ============================================================

if (!function_exists('cfg_load')) {
    function cfg_load(PDO $pdo): array {
        try {
            return $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('cfg_value')) {
    function cfg_value(array $config, string $key, string $default = ''): string {
        $raw = $config[$key] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) return $default;
        return is_scalar($raw) ? (string)$raw : $default;
    }
}

if (!function_exists('cfg_json')) {
    function cfg_json(array $config, string $key, array $default = []): array {
        $raw = $config[$key] ?? '';
        if (!is_string($raw) || trim($raw) === '') return $default;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('cfg_save_values')) {
    function cfg_save_values(PDO $pdo, array $values): void {
        $stmt = $pdo->prepare(
            "INSERT INTO configuracion (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $stmt->execute([(string)$key, (string)$value]);
        }
    }
}

if (!function_exists('cfg_default_work_axes')) {
    function cfg_default_work_axes(): array {
        return [
            [
                'id' => 'seguridad',
                'icon' => 'seguridad',
                'label' => 'Seguridad',
                'title' => 'Seguridad Ciudadana',
                'desc' => 'Serenazgo 24/7, camaras y coordinacion PNP para calles seguras en toda la provincia.',
                'grad' => 'from-red-500 to-rose-600',
                'nav_color' => 'bg-red-100 text-red-700',
                'nav_border' => 'border-red-300',
                'section_bg' => '#FEF2F2',
                'section_border' => '#FECACA',
                'img' => '/assets/img/candidato/seguridad.webp',
                'plan_desc' => "La seguridad es el primer derecho de todo ciudadano. En Satipo implementaremos un sistema integral de vigilancia y prevencion que involucre a vecinos, municipalidad y fuerzas del orden.\nAmpliaremos la cobertura del serenazgo hacia zonas periurbanas y rurales, con personal capacitado y equipamiento moderno.",
                'proposals' => "Instalacion de camaras de videovigilancia en zonas estrategicas\nAmpliar el servicio de serenazgo 24/7 con patrullaje mixto PNP-Municipio\nCentro de Control y Monitoreo Municipal\nJuntas vecinales de seguridad en todos los distritos",
            ],
            [
                'id' => 'infraestructura',
                'icon' => 'obra',
                'label' => 'Infraestructura',
                'title' => 'Infraestructura Vial',
                'desc' => 'Construccion y mejora de vias, puentes y accesos a comunidades rurales y nativas.',
                'grad' => 'from-orange-500 to-amber-600',
                'nav_color' => 'bg-orange-100 text-orange-700',
                'nav_border' => 'border-orange-300',
                'section_bg' => '#FFF7ED',
                'section_border' => '#FED7AA',
                'img' => '/assets/img/candidato/infraestructura.webp',
                'plan_desc' => "Satipo requiere vias de comunicacion que conecten sus distritos con la capital provincial y los mercados regionales.\nNuestra propuesta es un plan vial integral con ejecucion por etapas, priorizando los accesos a comunidades alejadas.",
                'proposals' => "Mejoramiento de vias y accesos principales\nConstruccion de puentes vehiculares en zonas criticas\nMantenimiento periodico de caminos rurales\nGestion de expedientes tecnicos ante entidades publicas",
            ],
            [
                'id' => 'agricultura',
                'icon' => 'campo',
                'label' => 'Agricultura',
                'title' => 'Agricultura y Campo',
                'desc' => 'Asistencia tecnica, centros de acopio y acceso a mercados para productores locales.',
                'grad' => 'from-green-500 to-emerald-600',
                'nav_color' => 'bg-green-100 text-green-700',
                'nav_border' => 'border-green-300',
                'section_bg' => '#F0FDF4',
                'section_border' => '#BBF7D0',
                'img' => '/assets/img/candidato/agricultura-cafe.webp',
                'plan_desc' => "La agricultura es la base de la economia de Satipo. Miles de familias dependen del cafe, cacao, citricos y otros productos.\nNuestro plan fortalece toda la cadena productiva: desde la asistencia tecnica hasta el acceso a mercados.",
                'proposals' => "Asistencia tecnica agropecuaria\nCentros de acopio y post-cosecha\nFerias para conectar productor y consumidor\nGestion de proyectos de riego tecnificado",
            ],
            [
                'id' => 'educacion',
                'icon' => 'educacion',
                'label' => 'Educacion',
                'title' => 'Educacion de Calidad',
                'desc' => 'Aulas nuevas, becas, internet en colegios y programas de lectura para ninos satipenos.',
                'grad' => 'from-blue-500 to-indigo-600',
                'nav_color' => 'bg-blue-100 text-blue-700',
                'nav_border' => 'border-blue-300',
                'section_bg' => '#EFF6FF',
                'section_border' => '#BFDBFE',
                'img' => '/assets/img/candidato/educacion.webp',
                'plan_desc' => "La educacion es la inversion mas importante que una sociedad puede hacer en su futuro.\nPromoveremos escuelas modernas, docentes fortalecidos y oportunidades reales para ninos y jovenes.",
                'proposals' => "Aulas nuevas y equipamiento educativo\nPrograma de becas municipales\nInternet en centros educativos publicos\nBiblioteca municipal y programas de lectura",
            ],
            [
                'id' => 'comunidades',
                'icon' => 'comunidad',
                'label' => 'Comunidades',
                'title' => 'Comunidades Nativas',
                'desc' => 'Titulacion, servicios basicos, salud y educacion intercultural para pueblos indigenas.',
                'grad' => 'from-emerald-500 to-teal-600',
                'nav_color' => 'bg-emerald-100 text-emerald-700',
                'nav_border' => 'border-emerald-300',
                'section_bg' => '#ECFDF5',
                'section_border' => '#A7F3D0',
                'img' => '/assets/img/candidato/comunidades.webp',
                'plan_desc' => "Los pueblos indigenas son parte esencial de la identidad de Satipo.\nTrabajaremos en coordinacion permanente para garantizar derechos, servicios basicos y desarrollo con respeto cultural.",
                'proposals' => "Gestion de titulos pendientes\nBrigadas de salud en comunidades remotas\nEducacion intercultural bilingue\nMesa de dialogo municipio-comunidades",
            ],
            [
                'id' => 'agua',
                'icon' => 'agua',
                'label' => 'Agua',
                'title' => 'Agua y Saneamiento',
                'desc' => 'Agua potable y alcantarillado en zonas urbanas y rurales sin cobertura actual.',
                'grad' => 'from-sky-500 to-cyan-600',
                'nav_color' => 'bg-sky-100 text-sky-700',
                'nav_border' => 'border-sky-300',
                'section_bg' => '#F0F9FF',
                'section_border' => '#BAE6FD',
                'img' => '/assets/img/candidato/saneamiento.webp',
                'plan_desc' => "El acceso al agua potable y saneamiento basico es un derecho fundamental.\nPriorizaremos proyectos de agua y desague como base de salud publica y dignidad familiar.",
                'proposals' => "Sistemas de agua potable en centros poblados\nMejoramiento de alcantarillado\nUnidades basicas de saneamiento rural\nMonitoreo de calidad del agua",
            ],
        ];
    }
}

if (!function_exists('cfg_axis_icon_path')) {
    function cfg_axis_icon_path(string $icon): string {
        $icons = [
            'seguridad' => 'M12 3l7 4v5c0 5-3 8-7 9-4-1-7-4-7-9V7l7-4zM9 12l2 2 4-4',
            'obra' => 'M3 21h18M6 21V9l6-4 6 4v12M9 21v-6h6v6M9 10h.01M15 10h.01',
            'campo' => 'M12 21c4.418-4.418 6-8 6-11a6 6 0 10-12 0c0 3 1.582 6.582 6 11zM9 10c2.5 0 4-1.5 4-4 2.5 1 4 3 4 5',
            'educacion' => 'M12 6.253v13M12 6.253C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253M12 6.253C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253',
            'comunidad' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M15 7a3 3 0 11-6 0 3 3 0 016 0z',
            'agua' => 'M12 3s6 6.1 6 10a6 6 0 11-12 0c0-3.9 6-10 6-10z',
            'salud' => 'M12 21s-7-4.35-7-10a4.5 4.5 0 018-2.8A4.5 4.5 0 0121 11c0 5.65-9 10-9 10zM12 8v6m-3-3h6',
            'transporte' => 'M5 16H3V8a2 2 0 012-2h9l4 4h1a2 2 0 012 2v4h-2M7 16h10M7 16a2 2 0 104 0M17 16a2 2 0 104 0',
            'economia' => 'M12 8c-2.21 0-4 .895-4 2s1.79 2 4 2 4 .895 4 2-1.79 2-4 2m0-8V5m0 14v-3m8-4a8 8 0 11-16 0 8 8 0 0116 0z',
            'turismo' => 'M3 21l6-18 6 18M9 3l6 18 6-18M6 12h12',
            'deporte' => 'M12 22a10 10 0 100-20 10 10 0 000 20zM4.93 4.93l14.14 14.14M12 2c2 2.5 3 5.8 3 10s-1 7.5-3 10M12 2C10 4.5 9 7.8 9 12s1 7.5 3 10',
            'ambiente' => 'M12 21C8 17 5 13.5 5 9.5A6.5 6.5 0 0117.5 7C19.5 11.5 16 17.5 12 21zM8 13c3-1 5-3 7-7',
            'juventud' => 'M16 11a4 4 0 10-8 0M4 21a8 8 0 0116 0M19 8v4m2-2h-4',
            'gestion' => 'M9 12h6m-6 4h6M9 8h6M5 5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5z',
            'compromiso' => 'M17 8h1a4 4 0 010 8h-1m-10 0H6a4 4 0 010-8h1m2 4h6M9 12a3 3 0 116 0',
            'experiencia' => 'M9 12l2 2 4-4M7 4h10a2 2 0 012 2v14l-7-3-7 3V6a2 2 0 012-2z',
            'cercania' => 'M12 21s-7-4.438-7-10a7 7 0 1114 0c0 5.562-7 10-7 10zM12 11a2 2 0 100-4 2 2 0 000 4z',
        ];
        return $icons[$icon] ?? 'M12 6v6l4 2M12 22a10 10 0 110-20 10 10 0 010 20z';
    }
}

if (!function_exists('cfg_axis_auto_icon')) {
    function cfg_axis_auto_icon(string $text): string {
        $source = strtolower(trim($text));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        $source = $ascii !== false ? strtolower($ascii) : $source;

        $rules = [
            'campo' => ['agricultura', 'agro', 'agrario', 'campo', 'cafe', 'cacao', 'productor', 'ganader'],
            'salud' => ['salud', 'posta', 'hospital', 'medic', 'sanitario'],
            'agua' => ['agua', 'saneamiento', 'desague', 'alcantarillado', 'potable'],
            'obra' => ['infraestructura', 'carretera', 'vial', 'vias', 'puente', 'obra', 'pista', 'camino'],
            'educacion' => ['educacion', 'colegio', 'escuela', 'beca', 'aula', 'docente'],
            'seguridad' => ['seguridad', 'serenazgo', 'policia', 'pnp', 'vigilancia'],
            'comunidad' => ['comunidad', 'comunidades', 'nativa', 'nativo', 'pueblo', 'intercultural'],
            'transporte' => ['transporte', 'movilidad', 'terminal', 'transito'],
            'economia' => ['economia', 'empleo', 'trabajo', 'mercado', 'emprend', 'comercio'],
            'turismo' => ['turismo', 'turistico', 'cultura', 'cultural'],
            'deporte' => ['deporte', 'recreacion', 'joven', 'juventud'],
            'ambiente' => ['ambiente', 'forestal', 'bosque', 'ecologia', 'residuo', 'limpieza'],
        ];

        foreach ($rules as $icon => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    return $icon;
                }
            }
        }

        return 'gestion';
    }
}

if (!function_exists('cfg_site_url')) {
    function cfg_site_url(string $url): string {
        $url = trim($url);
        if ($url === '' || $url[0] === '#' || preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'data:')) {
            return $url;
        }
        return (defined('BASE_URL') ? BASE_URL : '') . '/' . ltrim($url, '/');
    }
}
