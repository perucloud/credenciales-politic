<?php
function render_color_vars(array $cfg): string {
    $defs = [
        '--color-primary'                 => ['color_primary',                 '#1E3A8A'],
        '--color-accent'                  => ['color_accent',                  '#FACC15'],
        '--color-btn-hero-primary'        => ['color_btn_hero_primary',        '#CC1F2D'],
        '--color-btn-hero-primary-text'   => ['color_btn_hero_primary_text',   '#FFFFFF'],
        '--color-btn-hero-secondary'      => ['color_btn_hero_secondary',      '#FACC15'],
        '--color-btn-hero-secondary-text' => ['color_btn_hero_secondary_text', '#0039A6'],
        '--color-btn-download'            => ['color_btn_download',            '#CC1F2D'],
        '--color-btn-download-text'       => ['color_btn_download_text',       '#FFFFFF'],
        '--color-btn-cta-navbar'          => ['color_btn_cta_navbar',          '#FACC15'],
        '--color-btn-cta-navbar-text'     => ['color_btn_cta_navbar_text',     '#1E3A8A'],
        '--color-btn-join'                => ['color_btn_join',                '#FACC15'],
        '--color-btn-join-text'           => ['color_btn_join_text',           '#0039A6'],
    ];
    $lines = [];
    foreach ($defs as $var => [$key, $default]) {
        $val = trim(cfg_value($cfg, $key, $default));
        if (preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) {
            $lines[] = "$var:$val";
        }
    }
    return "<style>:root{" . implode(';', $lines) . "}"
         . ".btn-dyn:hover{filter:brightness(.88) saturate(1.1)}"
         . "</style>\n";
}
