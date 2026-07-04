<?php
require_once __DIR__ . '/helpers/config.php';
if (!isset($cfg_camp)) {
    $cfg_camp = cfg_load($pdo);
}

if (!function_exists('nav_url')) {
    function nav_url(string $url): string {
        if ($url === '' || $url[0] === '#' || preg_match('#^(https?:)?//#i', $url)) return $url;
        return BASE_URL . '/' . ltrim($url, '/');
    }
}

$nav_brand_name = cfg_value($cfg_camp, 'site_header_signature', cfg_value($cfg_camp, 'site_brand_name', 'Credenciales App'));
$nav_logo = cfg_value($cfg_camp, 'site_header_logo', '/assets/img/logos/logorp.webp');

$nav_items = [
    ['label' => 'Inicio',            'url' => '/index.php'],
    ['label' => 'Quien es?',         'url' => '/index.php#quien-es'],
    ['label' => 'Nuestro Plan de Accion', 'url' => '/index.php#plan'],
    ['label' => 'Noticias',          'url' => '/index.php#noticias'],
    ['label' => 'Local de Campaña',  'url' => '/index.php#contacto'],
];

$nav_cta_text = cfg_value($cfg_camp, 'site_header_cta_text', 'Unete al equipo');
$nav_cta_url  = cfg_value($cfg_camp, 'site_header_cta_url', '/index.php#unete');

if (empty($GLOBALS['__color_vars_printed'])) {
    require_once __DIR__ . '/helpers/colors.php';
    echo render_color_vars($cfg_camp);
    $GLOBALS['__color_vars_printed'] = true;
}
?>
<style>
  .site-navbar { position: sticky; top: 0; z-index: 90; }
  @media (max-width: 1023px) { .site-navbar { position: fixed; left: 0; right: 0; } }
</style>

<header class="site-navbar bg-white/95 backdrop-blur border-b border-gray-100 shadow-sm" x-data="{ open: false }">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between h-16">

      <a href="<?= BASE_URL ?>/index.php" class="flex items-center gap-3 min-w-0">
        <img src="<?= htmlspecialchars(nav_url($nav_logo)) ?>" alt="Logo" class="h-9 w-auto flex-shrink-0" onerror="this.style.display='none'">
        <span class="font-black text-[#049CD4] text-sm sm:text-base truncate"><?= htmlspecialchars($nav_brand_name) ?></span>
      </a>

      <nav class="hidden lg:flex items-center gap-6">
        <?php foreach ($nav_items as $item): ?>
          <a href="<?= htmlspecialchars(nav_url($item['url'])) ?>" class="text-sm font-semibold text-gray-600 hover:text-[#049CD4] transition-colors"><?= htmlspecialchars($item['label']) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="hidden lg:flex items-center gap-3">
        <button type="button"
                onclick="window.dispatchEvent(new CustomEvent('abrir-verificador-credencial')); if (!window.__credencialVerifierReady) window.location.href='<?= BASE_URL ?>/verificar-credencial.php';"
                class="text-xs font-bold text-gray-500 hover:text-[#049CD4] transition-colors flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Verificar credencial
        </button>
        <a href="<?= BASE_URL ?>/admin/login.php" class="text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors">Intranet</a>
        <a href="<?= htmlspecialchars(nav_url($nav_cta_url)) ?>" class="btn-dyn rounded-full px-5 py-2.5 text-sm font-bold shadow"
           style="background-color:var(--color-btn-cta-navbar);color:var(--color-btn-cta-navbar-text)">
          <?= htmlspecialchars($nav_cta_text) ?>
        </a>
      </div>

      <button @click="open = !open" class="lg:hidden p-2 rounded-lg hover:bg-gray-100">
        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path x-show="!open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div x-show="open" x-cloak x-transition class="lg:hidden pb-4 space-y-1">
      <?php foreach ($nav_items as $item): ?>
        <a href="<?= htmlspecialchars(nav_url($item['url'])) ?>" class="block px-3 py-2.5 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50"><?= htmlspecialchars($item['label']) ?></a>
      <?php endforeach; ?>
      <a href="<?= htmlspecialchars(nav_url($nav_cta_url)) ?>" class="btn-dyn block text-center rounded-full px-5 py-2.5 text-sm font-bold mt-2"
         style="background-color:var(--color-btn-cta-navbar);color:var(--color-btn-cta-navbar-text)">
        <?= htmlspecialchars($nav_cta_text) ?>
      </a>
      <a href="<?= BASE_URL ?>/verificar-credencial.php" class="block px-3 py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:bg-gray-50">Verificar credencial</a>
      <a href="<?= BASE_URL ?>/admin/login.php" class="block px-3 py-2.5 rounded-xl text-xs font-semibold text-gray-400 hover:bg-gray-50">Intranet</a>
    </div>
  </div>
</header>
<?php if (!isset($GLOBALS['__navbar_fixed_spacer_done'])): $GLOBALS['__navbar_fixed_spacer_done'] = true; ?>
<div class="lg:hidden" style="height:64px"></div>
<?php endif; ?>
