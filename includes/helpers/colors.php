<?php
function render_color_vars(array $cfg): string {
    $defs = [
        '--color-primary'                 => ['color_primary',                 '#049CD4'],
        '--color-accent'                  => ['color_accent',                  '#028FB7'],
        '--color-btn-hero-primary'        => ['color_btn_hero_primary',        '#049CD4'],
        '--color-btn-hero-primary-text'   => ['color_btn_hero_primary_text',   '#FFFFFF'],
        '--color-btn-hero-secondary'      => ['color_btn_hero_secondary',      '#028FB7'],
        '--color-btn-hero-secondary-text' => ['color_btn_hero_secondary_text', '#FFFFFF'],
        '--color-btn-download'            => ['color_btn_download',            '#049CD4'],
        '--color-btn-download-text'       => ['color_btn_download_text',       '#FFFFFF'],
        '--color-btn-cta-navbar'          => ['color_btn_cta_navbar',          '#028FB7'],
        '--color-btn-cta-navbar-text'     => ['color_btn_cta_navbar_text',     '#FFFFFF'],
        '--color-btn-join'                => ['color_btn_join',                '#028FB7'],
        '--color-btn-join-text'           => ['color_btn_join_text',           '#FFFFFF'],
    ];
    $lines = [];
    foreach ($defs as $var => [$key, $default]) {
        $val = trim(cfg_value($cfg, $key, $default));
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $val)) {
            $lines[] = "$var:$val";
        }
    }
    return "<style>:root{" . implode(';', $lines) . "}"
         . ".btn-dyn:hover{filter:brightness(.88) saturate(1.1)}"
         . "</style>\n";
}
