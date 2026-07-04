<?php
// ============================================================
// layout.php - Base del panel admin con flyout submenus
// ob_start() permite usar header() incluso despues de output
// ============================================================
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Prevenir que LiteSpeed u otros proxies cacheen páginas del admin
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();

$admin_nombre = htmlspecialchars($_SESSION['admin_nombre'] ?? 'Admin');
$admin_rol    = get_rol();
$page_title   = $page_title ?? 'Panel Admin';
$current      = basename($_SERVER['PHP_SELF']);

// -- Iconos reutilizables (Tabler Icons) --------------------
$ic_list  = 'ti-list';
$ic_plus  = 'ti-plus';
$ic_pdf   = 'ti-file-type-pdf';
$ic_excel = 'ti-file-type-xls';
$ic_eye   = 'ti-eye';
$ic_tag   = 'ti-tag';

function admin_sidebar_icon_url(string $file): string {
    return BASE_URL . '/assets/img/admin/icons/' . rawurlencode($file);
}

function admin_user_can_see_item(array $item, array $jerarquia, int $nivel_usuario, ?array $_modulos_usuario_sidebar, string $admin_rol): bool {
    if (!empty($item['solo_rol']) && $admin_rol !== $item['solo_rol']) return false;
    if (($jerarquia[$item['rol']] ?? 99) > $nivel_usuario) return false;
    if (($item['modulo'] ?? null) === null) return true;
    if ($_modulos_usuario_sidebar === null) return true;
    return in_array($item['modulo'], $_modulos_usuario_sidebar, true);
}

// -- Menu por secciones con submenus (solo 4 modulos: credenciales-app) ----
$secciones = [
  [
    'titulo' => 'PRINCIPAL',
    'items'  => [
      ['id'=>'dashboard','href'=>'dashboard.php','icon'=>'ti-layout-dashboard','icon_img'=>'dash.png','label'=>'Dashboard','rol'=>'editor','modulo'=>null,'solo_rol'=>null,'submenu'=>null],
    ],
  ],
  [
    'titulo' => 'PARTIDO POLITICO',
    'items'  => [
      ['id'=>'simpatizantes','href'=>'simpatizantes.php','icon'=>'ti-users-group','icon_img'=>'simpatizante.png','label'=>'Simpatizantes','rol'=>'editor','modulo'=>'simpatizantes','solo_rol'=>null,
        'submenu'=>[
          ['href'=>'simpatizantes.php',          'label'=>'Ver registros',  'icon'=>$ic_list],
          ['href'=>'exportar-pdf.php',           'label'=>'Exportar PDF',   'icon'=>$ic_pdf, 'target'=>'_blank'],
        ]
      ],
      ['id'=>'militantes','href'=>'militantes.php','icon'=>'ti-users-group','icon_img'=>'militante.png','label'=>'Militantes','rol'=>'editor','modulo'=>'militantes','solo_rol'=>null,'submenu'=>null],
      ['id'=>'personeros','href'=>'personeros.php','icon'=>'ti-id-badge-2','icon_img'=>'personero.png','label'=>'Personeros','rol'=>'editor','modulo'=>'personeros','solo_rol'=>null,'submenu'=>null],
      ['id'=>'credenciales_modulo','href'=>'credenciales-modulo.php','icon'=>'ti-id-badge-2','icon_img'=>'credencial.png','label'=>'Credenciales','rol'=>'editor','modulo'=>'credenciales_modulo','solo_rol'=>null,
        'submenu'=>[
          ['href'=>'credenciales-modulo.php', 'label'=>'Generar credenciales', 'icon'=>'ti-id-badge-2', 'rol'=>'editor', 'modulo'=>'credenciales_modulo', 'solo_rol'=>null],
          ['href'=>'credenciales-escaneadas.php', 'label'=>'Subir credenciales escaneadas', 'icon'=>'ti-photo-scan', 'rol'=>'editor', 'modulo'=>'credenciales_escaneadas', 'solo_rol'=>null],
        ]
      ],
    ],
  ],
  [
    'titulo' => 'CONTENIDO',
    'items'  => [
      ['id'=>'noticias','href'=>'noticias.php','icon'=>'ti-news','icon_img'=>null,'label'=>'Noticias','rol'=>'editor','modulo'=>'noticias','solo_rol'=>null,'submenu'=>null],
      ['id'=>'media','href'=>'media.php','icon'=>'ti-photo','icon_img'=>null,'label'=>'Archivos y Media','rol'=>'editor','modulo'=>'media','solo_rol'=>null,'submenu'=>null],
    ],
  ],
  [
    'titulo' => 'ADMINISTRACION',
    'items'  => [
      ['id'=>'configurar','href'=>'#','icon'=>'ti-adjustments-horizontal','icon_img'=>null,'label'=>'Configurar','rol'=>'editor','modulo'=>null,'solo_rol'=>null,
        'submenu'=>[
          ['href'=>'config-pagina.php', 'label'=>'Configurar Pagina', 'icon'=>'ti-settings-2', 'rol'=>'editor', 'modulo'=>'config_pagina', 'solo_rol'=>null],
          ['href'=>'config-index.php',  'label'=>'Configurar Index',  'icon'=>'ti-layout',     'rol'=>'editor', 'modulo'=>'config_index',  'solo_rol'=>null],
        ]
      ],
      ['id'=>'usuarios','href'=>'usuarios.php','icon'=>'ti-users','icon_img'=>null,'label'=>'Usuarios','rol'=>'superadmin','modulo'=>null,'solo_rol'=>null,'submenu'=>null],
    ],
  ],
];


$jerarquia     = rol_jerarquia();
$nivel_usuario = $jerarquia[$admin_rol] ?? 0;
$_modulos_usuario_sidebar = usuario_modulos_permitidos($pdo, (int)($_SESSION['admin_id'] ?? 0));

$mobile_nav_items = [
    ['id'=>'dashboard','href'=>'dashboard.php','icon'=>'ti-home','icon_img'=>'home.png','label'=>'Home','rol'=>'editor','modulo'=>null,'solo_rol'=>null],
    ['id'=>'credenciales_modulo','href'=>'credenciales-modulo.php','icon'=>'ti-id-badge-2','icon_img'=>'crede.png','label'=>'Credenciales','rol'=>'editor','modulo'=>null,'solo_rol'=>null],
];
$mobile_nav_items = array_values(array_filter(
    $mobile_nav_items,
    fn($item) => admin_user_can_see_item($item, $jerarquia, $nivel_usuario, $_modulos_usuario_sidebar, $admin_rol)
));

// -- Construir datos de flyout para Alpine.js -------------
$flyout_data = [];
foreach ($secciones as $sec) {
    foreach ($sec['items'] as $item) {
        if (!empty($item['submenu'])) {
            // Filtrar sub-items por rol y módulo igual que el sidebar
            $sub_filtrado = array_values(array_filter($item['submenu'], function($sub) use ($jerarquia, $nivel_usuario, $_modulos_usuario_sidebar, $admin_rol) {
                if (!empty($sub['solo_rol']) && $admin_rol !== $sub['solo_rol']) return false;
                if (isset($sub['rol']) && ($jerarquia[$sub['rol']] ?? 99) > $nivel_usuario) return false;
                if (!isset($sub['modulo']) || $sub['modulo'] === null) return true;
                if ($_modulos_usuario_sidebar === null) return true;
                return in_array($sub['modulo'], $_modulos_usuario_sidebar);
            }));
            if (empty($sub_filtrado)) continue;
            $flyout_data[$item['id']] = [
                'title' => $item['label'],
                'icon'  => $item['icon'],
                'icon_img' => $item['icon_img'] ?? '',
                'href'  => $item['href'],
                'items' => $sub_filtrado,
            ];
        }
    }
}
$flyout_json = json_encode($flyout_data, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);

$mobile_find_item = function (string $id) use ($secciones): ?array {
    foreach ($secciones as $sec) {
        foreach ($sec['items'] as $item) {
            if (($item['id'] ?? '') === $id) return $item;
        }
    }
    return null;
};
$mobile_item_visible = function (array $item) use ($jerarquia, $nivel_usuario, $_modulos_usuario_sidebar, $admin_rol, $flyout_data): bool {
    if (!empty($item['solo_rol']) && $admin_rol !== $item['solo_rol']) return false;
    if (($jerarquia[$item['rol']] ?? 99) > $nivel_usuario) return false;
    if (!empty($item['submenu'])) return isset($flyout_data[$item['id']]);
    if (($item['modulo'] ?? null) === null) return true;
    if ($_modulos_usuario_sidebar === null) return true;
    return in_array($item['modulo'], $_modulos_usuario_sidebar, true);
};
$mobile_subitems = function (array $item) use ($flyout_data): array {
    return array_map(function ($sub) {
        return [
            'label' => $sub['label'],
            'href' => $sub['href'],
            'icon' => $sub['icon'] ?? 'ti-circle',
            'target' => $sub['target'] ?? '_self',
        ];
    }, $flyout_data[$item['id']]['items'] ?? []);
};
$mobile_item_card = function (array $item) use ($mobile_subitems): array {
    return [
        'id' => $item['id'],
        'label' => $item['label'],
        'href' => $item['href'] === '#' ? '' : $item['href'],
        'icon' => $item['icon'] ?? 'ti-circle',
        'icon_img' => $item['icon_img'] ?? '',
        'children' => !empty($item['submenu']) ? $mobile_subitems($item) : [],
    ];
};

$mobile_app_modules = [];
$mobile_direct_ids = ['dashboard'];
foreach ($mobile_direct_ids as $id) {
    $item = $mobile_find_item($id);
    if (!$item || !$mobile_item_visible($item)) continue;
    $card = $mobile_item_card($item);
    $card['screen'] = $id;
    $card['is_group'] = false;
    $mobile_app_modules[] = $card;
}

$partido_children = [];
foreach (($secciones[1]['items'] ?? []) as $item) {
    if ($mobile_item_visible($item)) $partido_children[] = $mobile_item_card($item);
}
if (!empty($partido_children)) {
    $mobile_app_modules[] = [
        'id' => 'partido_politico',
        'screen' => 'partido_politico',
        'label' => 'Partido Politico',
        'href' => '',
        'icon' => 'ti-users-group',
        'icon_img' => 'personero.png',
        'is_group' => true,
        'children' => $partido_children,
    ];
}

$mobile_app_json = json_encode($mobile_app_modules, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?> - Credenciales App</title>
  <link rel="manifest" href="<?= BASE_URL ?>/admin/manifest.json">
  <meta name="theme-color" content="#1E3A8A">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Credenciales Admin">
  <?php
  $brand_name = 'Sistema de Gestión de Credenciales';
  try {
      $bn = $pdo->query("SELECT valor FROM configuracion WHERE clave='site_brand_name' LIMIT 1")->fetchColumn();
      if ($bn) $brand_name = $bn;
  } catch (Exception $e) {}
  $favicon_admin = '';
  try {
      $fv = $pdo->query("SELECT valor FROM configuracion WHERE clave='site_favicon' LIMIT 1")->fetchColumn();
      if ($fv) $favicon_admin = $fv;
  } catch (Exception $e) {}
  $favicon_href = $favicon_admin ?: '/uploads/media/media_6a1cf5f3d89929.46479429.webp';
  $favicon_href = (str_starts_with($favicon_href, '/') ? BASE_URL : '') . $favicon_href;
  ?>
  <link rel="icon" href="<?= htmlspecialchars($favicon_href) ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon_href) ?>">
  <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.14/dist/full.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.x/dist/tabler-icons.min.css">
  <script>tailwind.config={theme:{extend:{colors:{primary:'#1E3A8A',secondary:'#38BDF8',accent:'#FACC15'}}}}</script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    [x-cloak] { display: none !important; }

    /* Nav items - hover fondo completo + scale */
    .nav-item {
      border-radius: 10px;
      transition: background 0.15s ease, transform 0.15s ease, color 0.15s ease;
    }
    .nav-item:hover {
      background: #07AEEB;
      transform: scale(1.015);
    }
    .nav-active {
      background: rgba(250,204,21,0.17);
      color: #FACC15 !important;
      font-weight: 700;
    }
    .nav-active:hover {
      background: rgba(250,204,21,0.24);
    }

    /* Flyout panel */
    .flyout-panel {
      position: fixed;
      left: 256px;
      z-index: 9999;
      min-width: 220px;
      max-width: 260px;
    }
    /* Puente invisible entre sidebar y flyout para evitar gap */
    .flyout-bridge {
      position: absolute;
      left: -12px;
      top: 0;
      width: 14px;
      height: 100%;
      background: transparent;
    }

    .mobile-bottom-shell {
      animation: mobileNavRise 0.42s cubic-bezier(.2,.8,.2,1) both;
      pointer-events: none;
    }
    @keyframes mobileNavRise {
      from { opacity: 0; transform: translateY(18px) scale(.96); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .mobile-bottom-track {
      pointer-events: auto;
      position: relative;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      height: 82px;
      overflow: hidden;
      border-radius: 999px;
      background: linear-gradient(135deg, #1F5FAE 0%, #18529C 50%, #1F5FAE 100%);
      border: 1px solid rgba(255, 255, 255, .72);
      box-shadow: 0 18px 36px rgba(15, 32, 87, .34), inset 0 1px 0 rgba(255,255,255,.24);
    }
    .mobile-bottom-track::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background:
        linear-gradient(90deg, rgba(255,255,255,.14), transparent 18%, transparent 82%, rgba(255,255,255,.14)),
        radial-gradient(circle at 50% -28px, rgba(255,255,255,.22), transparent 78px);
      pointer-events: none;
    }
    .mobile-bottom-link {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 0;
      color: #fff;
      transition: transform .18s ease, background .22s ease, box-shadow .22s ease, filter .22s ease;
    }
    .mobile-bottom-link:active {
      transform: translateY(1px) scale(.94);
    }
    .mobile-bottom-link.is-active {
      background: linear-gradient(180deg, #FF3845 0%, #EF3340 100%);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.16), 0 12px 26px rgba(239, 51, 64, .24);
    }
    .mobile-bottom-link.is-active.is-first {
      border-radius: 999px 0 0 999px;
    }
    .mobile-bottom-link.is-active.is-last {
      border-radius: 0 999px 999px 0;
    }
    .mobile-bottom-link:hover {
      background: rgba(255, 255, 255, .12);
      transform: translateY(-1px);
    }
    .mobile-bottom-logo {
      pointer-events: auto;
      position: absolute;
      left: 50%;
      top: 1px;
      z-index: 3;
      width: 54px;
      height: 54px;
      border-radius: 999px;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      justify-content: center;
      background: #fff;
      border: 5px solid #EF3340;
      color: #1F5FAE;
      box-shadow: 0 10px 22px rgba(15, 32, 87, .24), inset 0 0 0 1px rgba(31,95,174,.12);
      font-family: Arial, Helvetica, sans-serif;
      font-size: 34px;
      font-weight: 900;
      line-height: 1;
      letter-spacing: 0;
      text-decoration: none;
      transition: transform .22s ease, box-shadow .22s ease;
    }
    .mobile-bottom-logo:hover {
      transform: translateX(-50%) translateY(-2px) scale(1.03);
      box-shadow: 0 18px 34px rgba(15, 32, 87, .34), inset 0 0 0 1px rgba(31,95,174,.16);
    }
    .mobile-bottom-link img {
      width: 42px;
      height: 42px;
      object-fit: contain;
      filter: brightness(0) invert(1);
      transform: translateZ(0);
      transition: transform .2s ease, filter .2s ease;
    }
    .mobile-bottom-link:hover img,
    .mobile-bottom-link.is-active img {
      transform: scale(1.08);
    }
    .mobile-app-shell {
      background:
        radial-gradient(circle at 20% 0%, rgba(56, 189, 248, .18), transparent 30%),
        radial-gradient(circle at 100% 10%, rgba(239, 51, 64, .13), transparent 28%),
        linear-gradient(180deg, #F8FAFC 0%, #EEF4FF 100%);
    }
    .mobile-app-card {
      position: relative;
      overflow: hidden;
      border-radius: 24px;
      border: 1px solid rgba(191, 219, 254, .9);
      background: rgba(255, 255, 255, .92);
      box-shadow: 0 18px 38px rgba(15, 32, 87, .08);
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .mobile-app-card::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 100% 0%, rgba(30, 58, 138, .12), transparent 70px),
        linear-gradient(135deg, rgba(255,255,255,.18), transparent);
      pointer-events: none;
    }
    .mobile-app-card:active {
      transform: scale(.97);
      box-shadow: 0 12px 26px rgba(15, 32, 87, .12);
    }
    .mobile-app-icon {
      display: flex;
      width: 48px;
      height: 48px;
      align-items: center;
      justify-content: center;
      border-radius: 18px;
      background: linear-gradient(135deg, #1E3A8A, #2563EB);
      box-shadow: 0 12px 24px rgba(30, 58, 138, .22);
    }
    .mobile-app-icon img {
      width: 27px;
      height: 27px;
      object-fit: contain;
      filter: brightness(0) invert(1);
    }
    .mobile-app-action {
      border-radius: 18px;
      border: 1px solid rgba(203, 213, 225, .95);
      background: rgba(255, 255, 255, .96);
      box-shadow: 0 10px 24px rgba(15, 32, 87, .06);
      transition: transform .16s ease, border-color .16s ease, background .16s ease;
    }
    .mobile-app-action:active {
      transform: scale(.98);
      border-color: rgba(30, 58, 138, .35);
      background: #F8FAFC;
    }
    @media (max-width: 1023px) {
      body { padding-bottom: calc(122px + env(safe-area-inset-bottom)); }
      .admin-main-content { padding-bottom: calc(138px + env(safe-area-inset-bottom)) !important; }
      #bug-reporter > button { bottom: calc(120px + env(safe-area-inset-bottom)) !important; }
    }
    @media (max-width: 360px) {
      .mobile-bottom-track { height: 74px; }
      .mobile-bottom-logo { width: 48px; height: 48px; top: 4px; border-width: 5px; font-size: 30px; }
      .mobile-bottom-link img { width: 36px; height: 36px; }
    }
  </style>
</head>
<body class="bg-[#F1F5F9] min-h-screen"
      x-data="adminLayout()"
      @mousemove.window="handleMouseMove($event)"
      @keydown.escape.window="mobileNavOpen ? closeMobileNav() : null">

  <script>
    window.CSRF_TOKEN = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
    window.BRAND_NAME = '<?= htmlspecialchars($brand_name, ENT_QUOTES) ?>';

    function ensureCsrfField(form) {
      if (!form || String(form.method || '').toLowerCase() !== 'post') return;
      if (form.querySelector('input[name="_csrf"]')) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = '_csrf';
      input.value = window.CSRF_TOKEN;
      form.appendChild(input);
    }

    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('form').forEach(ensureCsrfField);
    });
    document.addEventListener('submit', (event) => {
      ensureCsrfField(event.target);
    }, true);

    function adminLayout() {
  return {
    sidebarOpen: false,
    mobileNavOpen: false,
    mobileScreen: null,
    mobileModules: <?= $mobile_app_json ?>,
    flyout: null,
    flyoutY: 0,
    flyoutFromBottom: false,
    flyoutTimer: null,
    submenus: <?= $flyout_json ?>,

    get currentSub() {
      return this.flyout ? this.submenus[this.flyout] : null;
    },

    get currentMobileModule() {
      return this.mobileModules.find((module) => module.screen === this.mobileScreen) || null;
    },

    mobileIconUrl(file) {
      return '<?= BASE_URL ?>/assets/img/admin/icons/' + encodeURIComponent(file || '');
    },

    adminUrl(path) {
      if (!path) return '#';
      return '<?= BASE_URL ?>/admin/' + path;
    },

    openMobileNav(screen = null) {
      this.mobileScreen = screen;
      this.mobileNavOpen = true;
      document.body.style.overflow = 'hidden';
    },

    closeMobileNav() {
      this.mobileNavOpen = false;
      this.mobileScreen = null;
      document.body.style.overflow = '';
    },

    openMobileModule(module) {
      if (!module) return;
      if (module.is_group || (module.children && module.children.length)) {
        this.mobileScreen = module.screen;
        return;
      }
      if (module.href) window.location.href = this.adminUrl(module.href);
    },

    openFlyout(id, el) {
      clearTimeout(this.flyoutTimer);
      const rect   = el.getBoundingClientRect();
      const items  = this.submenus[id]?.items?.length ?? 0;
      const panelH = items * 44 + 88;

      if (rect.top + panelH > window.innerHeight - 12) {
        // Anclar desde abajo: calcular distancia desde el borde inferior
        this.flyoutFromBottom = true;
        this.flyoutY = Math.max(window.innerHeight - rect.bottom, 8);
      } else {
        this.flyoutFromBottom = false;
        this.flyoutY = rect.top;
      }
      this.flyout = id;
    },

    closeFlyout(delay = 300) {
      clearTimeout(this.flyoutTimer);
      this.flyoutTimer = setTimeout(() => { this.flyout = null; }, delay);
    },

    keepFlyout() {
      clearTimeout(this.flyoutTimer);
    },

    handleMouseMove(e) {
      // Solo cerrar si el mouse esta LEJOS a la derecha (fuera del flyout)
      // No tocar si esta en el sidebar (< 256) ni en el flyout (256-520)
      if (!this.flyout) return;
      if (e.clientX > 520) {
        this.closeFlyout(250);
      }
    }
  };
}
</script>

  <!-- Overlay movil -->
  <div x-show="sidebarOpen" @click="sidebarOpen=false"
       class="fixed inset-0 bg-black/50 z-30 lg:hidden" x-cloak></div>

  <!-- -- SIDEBAR ------------------------------------------- -->
  <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
         class="fixed top-0 left-0 h-full w-64 z-40 flex flex-col
                transition-transform duration-300 lg:translate-x-0 overflow-y-auto overflow-x-visible"
         style="background: linear-gradient(180deg, #049CD4 0%, #137294 100%);">

    <!-- Logo / Marca -->
    <div class="px-5 py-5 border-b border-white/10 flex-shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-[#FACC15] rounded-xl flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-[#0F2057]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/>
          </svg>
        </div>
        <div>
          <p class="text-white font-black text-sm leading-tight">Credenciales App</p>
          <p class="text-[#38BDF8] text-[10px] uppercase tracking-widest">Panel Admin</p>
        </div>
      </div>
    </div>

    <!-- Info usuario -->
    <div class="px-5 py-4 border-b border-white/10 flex-shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-gradient-to-br from-[#38BDF8] to-[#1E3A8A] rounded-full flex items-center justify-center flex-shrink-0">
          <span class="text-white text-sm font-black"><?= strtoupper(substr($admin_nombre, 0, 1)) ?></span>
        </div>
        <div class="min-w-0">
          <p class="text-white text-sm font-semibold truncate"><?= $admin_nombre ?></p>
          <p class="text-[11px] mt-0.5">
            <?php if ($admin_rol === 'superadmin'): ?>
              <span class="text-purple-300 font-bold">Superadmin</span>
            <?php elseif ($admin_rol === 'admin'): ?>
              <span class="text-blue-300 font-bold">Admin</span>
            <?php else: ?>
              <span class="text-green-300 font-bold">Editor</span>
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Navegacion -->
    <nav class="flex-1 px-3 py-4 space-y-5 overflow-x-visible">
      <?php foreach ($secciones as $seccion): ?>
        <?php
        $items_visibles = array_filter($seccion['items'], function($item) use ($jerarquia, $nivel_usuario, $_modulos_usuario_sidebar, $admin_rol, $flyout_data) {
            if (!empty($item['solo_rol']) && $admin_rol !== $item['solo_rol']) return false;
            if (($jerarquia[$item['rol']] ?? 99) > $nivel_usuario) return false;
            if (!empty($item['submenu'])) return isset($flyout_data[$item['id']]);
            if ($item['modulo'] === null) return true;
            if ($_modulos_usuario_sidebar === null) return true;
            return in_array($item['modulo'], $_modulos_usuario_sidebar);
        });
        if (empty($items_visibles)) continue;
        ?>
        <div>
          <p class="text-[9px] font-black text-white/30 uppercase tracking-widest px-4 mb-2">
            <?= $seccion['titulo'] ?>
          </p>
          <div class="space-y-0.5">
            <?php foreach ($items_visibles as $item):
              $has_sub = !empty($item['submenu']);
              $is_active = $current === $item['href'];
              if (!$is_active && $has_sub) {
                  foreach ($item['submenu'] as $sub_active) {
                      if ($current === ($sub_active['href'] ?? '')) { $is_active = true; break; }
                  }
              }
            ?>
            <div class="relative"
                 <?php if ($has_sub): ?>
                 @mouseenter="openFlyout('<?= $item['id'] ?>', $el)"
                 @mouseleave="closeFlyout(350)"
                 <?php endif; ?>>

              <!-- Link principal -->
              <a href="<?= $item['href'] === '#' ? '#' : BASE_URL . '/admin/' . $item['href'] ?>"
                 <?= $item['href'] === '#' ? '@click.prevent=""' : '' ?>
                 class="nav-item flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm
                        text-blue-200 hover:text-white group
                        <?= $is_active ? 'nav-active' : '' ?>">
                <?php if (!empty($item['icon_img'])): ?>
                  <img src="<?= htmlspecialchars(admin_sidebar_icon_url($item['icon_img'])) ?>"
                       alt=""
                       class="w-4 h-4 object-contain flex-shrink-0 opacity-85 group-hover:opacity-100 transition-opacity">
                <?php else: ?>
                  <i class="ti <?= htmlspecialchars($item['icon']) ?> text-base flex-shrink-0 opacity-80 group-hover:opacity-100 transition-opacity"></i>
                <?php endif; ?>
                <span class="truncate flex-1"><?= $item['label'] ?></span>
                <?php if ($has_sub): ?>
                <svg class="w-3 h-3 flex-shrink-0 opacity-40 group-hover:opacity-80 transition-all"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <?php endif; ?>
              </a>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <!-- Footer sidebar -->
    <div class="px-3 py-4 border-t border-white/10 flex-shrink-0 space-y-1">

      <!-- ── Botón instalar PWA ───────────────────────────── -->
      <div id="pwa-install-wrap" style="display:none" class="px-1 pb-2">
        <button id="pwa-install-btn"
                class="w-full relative overflow-hidden group
                       flex items-center gap-3 px-4 py-3 rounded-2xl
                       bg-gradient-to-r from-[#FACC15] via-[#FCD34D] to-[#FACC15]
                       bg-[length:200%_100%] bg-left
                       hover:bg-right
                       text-[#0F2057] font-black text-sm
                       shadow-lg shadow-yellow-500/30
                       transition-all duration-700 ease-in-out
                       hover:shadow-xl hover:shadow-yellow-400/40 hover:scale-[1.02]
                       active:scale-95">

          <!-- Shimmer effect -->
          <span class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent
                       translate-x-[-100%] group-hover:translate-x-[100%]
                       transition-transform duration-700 ease-in-out pointer-events-none"></span>

          <!-- Icono descarga animado -->
          <span class="relative flex-shrink-0 w-8 h-8 rounded-xl bg-[#0F2057]/15
                       flex items-center justify-center
                       group-hover:bg-[#0F2057]/25 transition-colors">
            <svg class="w-4 h-4 group-hover:animate-bounce" fill="none" stroke="currentColor"
                 stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
          </span>

          <!-- Texto -->
          <span class="relative flex-1 text-left leading-tight">
            <span class="block text-sm font-black">Instalar App</span>
            <span class="block text-[10px] font-semibold text-[#0F2057]/60">Acceso rápido desde tu celular</span>
          </span>

          <!-- Flecha -->
          <svg class="relative w-4 h-4 flex-shrink-0 opacity-60 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all"
               fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>

        <!-- Subtexto -->
        <p class="text-center text-[9px] text-white/25 mt-1.5 font-medium tracking-wide">
          Sin tiendas · Instalación directa
        </p>
      </div>

      <!-- Separador si el botón está visible -->
      <div id="pwa-divider" style="display:none" class="border-t border-white/10 mb-1"></div>

      <a href="<?= BASE_URL ?>/index.php?preview=1" target="_blank"
         class="nav-item flex items-center gap-3 px-4 py-2.5 rounded-r-xl text-sm text-blue-200 hover:text-white transition-all duration-150">
        <i class="ti ti-external-link text-base flex-shrink-0 opacity-85"></i>
        Ver sitio web
      </a>
      <a href="<?= BASE_URL ?>/admin/logout.php"
         class="nav-item flex items-center gap-3 px-4 py-2.5 rounded-r-xl text-sm text-red-300 hover:bg-red-500/15 hover:text-red-200 transition-all duration-150">
        <img src="<?= htmlspecialchars(admin_sidebar_icon_url('salir.png')) ?>" alt="" class="w-4 h-4 object-contain flex-shrink-0 opacity-85">
        Cerrar sesion
      </a>
    </div>
  </aside>

  <!-- -- FLYOUT PANEL (fixed, fuera del sidebar) ----------- -->
  <div x-cloak
       x-show="flyout !== null && currentSub !== null"
       x-transition:enter="transition ease-out duration-150"
       x-transition:enter-start="opacity-0 translate-x-1 scale-95"
       x-transition:enter-end="opacity-100 translate-x-0 scale-100"
       x-transition:leave="transition ease-in duration-100"
       x-transition:leave-start="opacity-100 translate-x-0 scale-100"
       x-transition:leave-end="opacity-0 translate-x-1 scale-95"
       :style="flyoutFromBottom ? `bottom: ${flyoutY}px; top: auto` : `top: ${flyoutY}px; bottom: auto`"
       @mouseenter="keepFlyout()"
       @mouseleave="closeFlyout()"
       class="flyout-panel hidden lg:block">

    <!-- Puente invisible anti-gap -->
    <div class="flyout-bridge"></div>

    <!-- Panel -->
    <div class="bg-white rounded-2xl shadow-2xl shadow-slate-900/20 border border-gray-100 overflow-hidden">

      <!-- Header del flyout -->
      <div class="px-4 py-3 bg-gradient-to-r from-[#0F2057] to-[#1E3A8A] flex items-center gap-2.5">
        <template x-if="currentSub?.icon_img">
          <img :src="'<?= BASE_URL ?>/assets/img/admin/icons/' + currentSub.icon_img" alt="" class="w-4 h-4 object-contain flex-shrink-0">
        </template>
        <template x-if="currentSub && !currentSub.icon_img">
          <i :class="'ti ' + (currentSub?.icon ?? '') + ' text-base text-[#FACC15] flex-shrink-0'"></i>
        </template>
        <span class="text-white font-black text-sm" x-text="currentSub?.title ?? ''"></span>
      </div>

      <!-- Sub-links -->
      <div class="py-1.5">
        <template x-for="sub in (currentSub?.items ?? [])" :key="sub.href">
          <a :href="'<?= BASE_URL ?>/admin/' + sub.href"
             :target="sub.target ?? '_self'"
             class="flex items-center gap-3 mx-2 px-3 py-2.5 text-sm text-gray-600 font-medium rounded-xl
                    hover:bg-[#1E3A8A] hover:text-white hover:scale-[1.02] transition-all duration-150 group">
            <i :class="'ti ' + sub.icon + ' text-sm flex-shrink-0 text-gray-400 group-hover:text-[#FACC15] transition-colors'"></i>
            <span x-text="sub.label"></span>
          </a>
        </template>

        <!-- Divisor + ir a la seccion principal -->
        <div class="border-t border-gray-100 mt-1 pt-1">
          <a :href="'<?= BASE_URL ?>/admin/' + (currentSub?.href ?? '#')"
             class="flex items-center gap-3 mx-2 px-3 py-2 text-xs text-gray-400 font-semibold rounded-xl
                    hover:bg-gray-50 hover:text-[#1E3A8A] hover:scale-[1.02] transition-all duration-150">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <span x-text="'Ir a ' + (currentSub?.title ?? '')"></span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- -- MAIN AREA ------------------------------------------ -->
  <div class="lg:ml-64 flex flex-col min-h-screen">

    <!-- Topbar -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-20 px-4 sm:px-6 h-14 flex items-center justify-between shadow-sm">
      <div class="flex items-center gap-3">
        <button @click="openMobileNav()"
                class="lg:hidden p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
          <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
        </button>
        <div class="flex items-center gap-2">
          <span class="text-gray-300 hidden sm:block">/</span>
          <h1 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($page_title) ?></h1>
        </div>
      </div>
      <div class="flex items-center gap-4">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 bg-gradient-to-br from-[#38BDF8] to-[#1E3A8A] rounded-full flex items-center justify-center">
            <span class="text-white text-xs font-black"><?= strtoupper(substr($admin_nombre, 0, 1)) ?></span>
          </div>
          <div class="hidden sm:block">
            <p class="text-xs font-semibold text-gray-700 leading-tight"><?= $admin_nombre ?></p>
            <p class="text-[10px] text-gray-400 leading-tight capitalize"><?= $admin_rol ?></p>
          </div>
        </div>
        <a href="<?= BASE_URL ?>/admin/logout.php"
           class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors">
          Salir
        </a>
      </div>
    </header>

    <div id="admin-pwa-required-modal"
         class="hidden lg:hidden fixed inset-0 z-[10000] bg-slate-950/75 backdrop-blur-sm px-4 py-6">
      <div class="mx-auto mt-10 max-w-sm overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div class="bg-gradient-to-br from-[#0F2057] via-[#1E3A8A] to-[#EF3340] px-6 py-6 text-white">
          <div class="flex items-center gap-3">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl border-4 border-[#EF3340] bg-white text-3xl font-black leading-none text-[#1E3A8A] shadow-lg">A</div>
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#FACC15]">App administrativa</p>
              <h2 class="mt-1 text-xl font-black leading-tight">Instala el panel en tu celular</h2>
            </div>
          </div>
        </div>
        <div class="space-y-4 px-6 py-5">
          <p class="text-sm font-semibold leading-relaxed text-slate-600">
            Para una mejor administracion, instala el dashboard como app movil y accede directo desde la pantalla de inicio.
          </p>
          <div class="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-xs font-bold text-blue-900">
            No se descarga desde Play Store: Chrome instalara esta web como aplicacion segura.
          </div>
          <button id="admin-pwa-required-install"
                  type="button"
                  class="flex w-full items-center justify-center gap-2 rounded-full bg-[#EF3340] px-5 py-3.5 text-sm font-black text-white shadow-lg shadow-red-500/25 transition hover:bg-red-600 active:scale-95">
            Instalar app ahora
          </button>
          <button id="admin-pwa-required-later"
                  type="button"
                  class="w-full rounded-full px-5 py-2.5 text-xs font-black text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
            Continuar por ahora
          </button>
        </div>
      </div>
    </div>

    <!-- ── PWA: registro SW + lógica de instalación ─────────── -->
    <script>
    (function () {
      // 1. Registrar Service Worker
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= BASE_URL ?>/admin/sw.js', { scope: '/admin/' })
          .catch(() => {});
      }

      // 2. Capturar evento de instalación
      let _deferredPrompt = null;
      const wrap    = document.getElementById('pwa-install-wrap');
      const divider = document.getElementById('pwa-divider');
      const btn     = document.getElementById('pwa-install-btn');

      window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        _deferredPrompt = e;
        if (wrap)    wrap.style.display    = 'block';
        if (divider) divider.style.display = 'block';
      });

      // 3. Click en el botón de instalar
      if (btn) {
        btn.addEventListener('click', async () => {
          if (!_deferredPrompt) return;
          _deferredPrompt.prompt();
          const { outcome } = await _deferredPrompt.userChoice;
          _deferredPrompt = null;
          if (outcome === 'accepted') {
            if (wrap)    wrap.style.display    = 'none';
            if (divider) divider.style.display = 'none';
          }
        });
      }

      // 4. Si ya está instalada, ocultar el botón
      window.addEventListener('appinstalled', () => {
        if (wrap)    wrap.style.display    = 'none';
        if (divider) divider.style.display = 'none';
        _deferredPrompt = null;
      });

      // 5. Detectar si ya corre como PWA instalada
      if (window.matchMedia('(display-mode: standalone)').matches ||
          window.navigator.standalone === true) {
        if (wrap)    wrap.style.display    = 'none';
        if (divider) divider.style.display = 'none';
      }
    })();
    </script>

    <!-- ── BUG REPORTER PRO ─────────────────────────────────── -->
    <script>
    (function () {
      let adminDeferredPrompt = null;
      const modal = document.getElementById('admin-pwa-required-modal');
      const installBtn = document.getElementById('admin-pwa-required-install');
      const laterBtn = document.getElementById('admin-pwa-required-later');
      const isDashboard = '<?= htmlspecialchars($current, ENT_QUOTES) ?>' === 'dashboard.php';
      const isMobile = window.matchMedia('(max-width: 1023px)').matches;
      const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

      function hideAdminInstallModal() {
        if (modal) modal.classList.add('hidden');
        document.body.style.overflow = '';
      }

      window.addEventListener('beforeinstallprompt', function (event) {
        adminDeferredPrompt = event;
        if (!modal || !isDashboard || !isMobile || isStandalone) return;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
      });

      if (installBtn) {
        installBtn.addEventListener('click', async function () {
          if (!adminDeferredPrompt) return;
          adminDeferredPrompt.prompt();
          try {
            await adminDeferredPrompt.userChoice;
          } catch (error) {}
          adminDeferredPrompt = null;
          hideAdminInstallModal();
        });
      }

      if (laterBtn) laterBtn.addEventListener('click', hideAdminInstallModal);
      window.addEventListener('appinstalled', hideAdminInstallModal);
    })();
    </script>

    <?php if (false): ?>
    <div id="bug-reporter" x-data="bugReporter()" x-cloak>

      <!-- Botón flotante -->
      <button @click="open = !open"
              title="Reportar un problema"
              class="fixed bottom-6 right-6 z-[9998] rounded-full shadow-2xl
                     flex items-center justify-center transition-all duration-300
                     hover:scale-110 active:scale-95"
              :class="open ? 'bg-red-500 hover:bg-red-600 rotate-45' : 'bg-[#1E3A8A] hover:bg-[#2563EB]'"
              style="width:52px;height:52px">
        <svg x-show="!open" class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <svg x-show="open" class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>

      <!-- Panel slide-in -->
      <div x-show="open"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 translate-y-4 scale-95"
           x-transition:enter-end="opacity-100 translate-y-0 scale-100"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="opacity-100 translate-y-0 scale-100"
           x-transition:leave-end="opacity-0 translate-y-4 scale-95"
           class="fixed bottom-24 right-6 z-[9997] w-96 bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden"
           style="max-height:90vh;overflow-y:auto">

        <!-- Header dinámico según tipo -->
        <div class="px-5 py-4"
             :class="tipo === 'cambio'
               ? 'bg-gradient-to-r from-[#065f46] to-[#059669]'
               : 'bg-gradient-to-r from-[#0F2057] to-[#1E3A8A]'">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-white font-black text-sm"
                  x-text="tipo === 'cambio' ? '✏️ Solicitar Cambio' : '🐛 Reportar Bug'"></h3>
              <p class="text-white/70 text-[11px] mt-0.5"
                 x-text="elementoCapturado ? 'Elemento: ' + elementoCapturado.tag : 'Captura automática de contexto técnico'"></p>
            </div>
            <span class="text-[10px] bg-[#FACC15] text-[#0F2057] font-black px-2 py-0.5 rounded-full">PRO</span>
          </div>
        </div>

        <div class="p-5 space-y-4">

          <!-- Elemento capturado vía click derecho -->
          <div x-show="elementoCapturado" class="bg-indigo-50 border border-indigo-200 rounded-xl p-3">
            <p class="text-[10px] font-black text-indigo-400 uppercase mb-1.5">Elemento capturado automáticamente</p>
            <div class="space-y-1">
              <div class="flex gap-2 text-[11px]">
                <span class="text-indigo-400 w-16 flex-shrink-0 font-bold">Tag:</span>
                <code class="text-indigo-700 font-mono" x-text="elementoCapturado?.tag"></code>
              </div>
              <div class="flex gap-2 text-[11px]" x-show="elementoCapturado?.texto">
                <span class="text-indigo-400 w-16 flex-shrink-0 font-bold">Texto:</span>
                <span class="text-indigo-700 truncate" x-text="elementoCapturado?.texto"></span>
              </div>
              <div class="flex gap-2 text-[11px]">
                <span class="text-indigo-400 w-16 flex-shrink-0 font-bold">Selector:</span>
                <code class="text-indigo-700 font-mono text-[10px] break-all" x-text="elementoCapturado?.selector"></code>
              </div>
            </div>
            <button @click="elementoCapturado = null" class="mt-2 text-[10px] text-indigo-400 hover:text-indigo-600">× Quitar</button>
          </div>

          <!-- Tipo de reporte -->
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-2">Tipo de reporte</label>
            <div class="grid grid-cols-2 gap-2">
              <button @click="tipo = 'bug'"
                      :class="tipo === 'bug' ? 'bg-[#1E3A8A] text-white ring-2 ring-blue-400 ring-offset-1' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                      class="py-2 rounded-xl text-xs font-black transition-all">🐛 Bug / Error</button>
              <button @click="tipo = 'cambio'"
                      :class="tipo === 'cambio' ? 'bg-[#059669] text-white ring-2 ring-green-400 ring-offset-1' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                      class="py-2 rounded-xl text-xs font-black transition-all">✏️ Quiero un cambio</button>
            </div>
          </div>

          <!-- Severidad -->
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-2">Severidad / Prioridad</label>
            <div class="grid grid-cols-4 gap-1.5">
              <template x-for="s in severidades" :key="s.val">
                <button @click="severidad = s.val"
                        :class="severidad === s.val ? s.activeClass + ' ring-2 ring-offset-1 ' + s.ring : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                        class="px-2 py-2 rounded-lg text-[11px] font-black transition-all text-center">
                  <div x-text="s.emoji" class="text-base leading-none mb-0.5"></div>
                  <div x-text="s.label"></div>
                </button>
              </template>
            </div>
          </div>

          <!-- Descripción -->
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">
              <span x-text="tipo === 'cambio' ? '¿Qué quieres cambiar?' : '¿Qué pasó?'"></span>
              <span class="text-red-500"> *</span>
            </label>
            <textarea x-ref="descArea"
                      :value="descripcion"
                      @input="descripcion = $event.target.value; errDesc = false"
                      rows="3"
                      tabindex="0"
                      :placeholder="tipo === 'cambio'
                        ? 'Ej: Quiero que este botón sea verde en lugar de azul.'
                        : 'Ej: Al hacer clic en Guardar no pasa nada. Esperaba que guardara los datos.'"
                      class="w-full rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#1E3A8A] focus:border-[#1E3A8A] resize-none p-2.5"
                      :class="errDesc ? 'border-red-400' : 'border-gray-200'"></textarea>
            <p x-show="errDesc" class="text-red-500 text-[11px] mt-1">La descripción es obligatoria.</p>
          </div>

          <!-- Screenshot -->
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label class="block text-xs font-black text-gray-500 uppercase tracking-wide">Captura</label>
              <button @click="capturarPantalla()" :disabled="capturando"
                      class="text-[11px] font-bold text-[#1E3A8A] hover:underline disabled:opacity-50">
                <span x-show="!capturando">📸 Capturar pantalla</span>
                <span x-show="capturando">Capturando...</span>
              </button>
            </div>
            <div x-show="screenshot" class="relative rounded-xl overflow-hidden border border-gray-200">
              <img :src="screenshot" class="w-full max-h-36 object-cover object-top">
              <button @click="screenshot = null"
                      class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold">×</button>
            </div>
            <p x-show="!screenshot" class="text-[11px] text-gray-400 bg-gray-50 rounded-xl px-3 py-2 text-center">
              Sin captura — o usa click derecho → "Seleccionar zona"
            </p>
          </div>

          <!-- Contexto técnico -->
          <details class="group">
            <summary class="text-[11px] text-gray-400 cursor-pointer hover:text-gray-600 font-semibold select-none">
              ⚙️ Contexto técnico automático <span class="group-open:hidden">▸</span><span class="hidden group-open:inline">▾</span>
            </summary>
            <div class="mt-2 bg-gray-50 rounded-xl p-3 space-y-1 text-[11px]">
              <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">URL:</span><span class="text-gray-700 break-all" x-text="urlActual"></span></div>
              <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Navegador:</span><span class="text-gray-700" x-text="navegador"></span></div>
              <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">OS:</span><span class="text-gray-700" x-text="sistema_os"></span></div>
              <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Resolución:</span><span class="text-gray-700" x-text="resolucion"></span></div>
              <div class="flex gap-2" x-show="jsErrors.length > 0"><span class="text-gray-400 w-20 flex-shrink-0">Errores JS:</span><span class="text-red-600 font-bold" x-text="jsErrors.length + ' error(es)'"></span></div>
            </div>
          </details>

          <!-- Feedback envío -->
          <div x-show="enviado" class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 flex items-center gap-3">
            <span class="text-2xl">✅</span>
            <div>
              <p class="text-green-800 font-bold text-sm">¡Reporte enviado!</p>
              <p class="text-green-600 text-[11px]" x-text="'ID #' + bugId + ' — gracias.'"></p>
            </div>
          </div>
          <div x-show="errorEnvio" class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-red-700 text-sm">
            ❌ <span x-text="errorEnvio"></span>
          </div>

          <!-- Acciones -->
          <button x-show="!enviado" @click="enviar()" :disabled="enviando"
                  class="w-full py-3 rounded-xl font-black text-sm text-white transition-all disabled:opacity-60"
                  :class="tipo === 'cambio' ? 'bg-[#059669] hover:bg-[#047857]' : 'bg-[#1E3A8A] hover:bg-[#2563EB]'">
            <span x-show="!enviando" x-text="tipo === 'cambio' ? 'Enviar solicitud de cambio' : 'Enviar reporte de bug'"></span>
            <span x-show="enviando" class="flex items-center justify-center gap-2">
              <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
              </svg>Enviando...
            </span>
          </button>
          <button x-show="enviado" @click="resetear()"
                  class="w-full py-2 rounded-xl text-sm text-gray-500 hover:text-gray-700 font-semibold">
            Reportar otro problema
          </button>
        </div>
      </div>
    </div>

    <!-- ── MENÚ CONTEXTUAL ────────────────────────────────────── -->
    <div id="ctx-menu"
         class="hidden fixed z-[99999] bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden py-1.5"
         style="min-width:220px">
      <div id="ctx-header" class="px-4 py-2.5 bg-gradient-to-r from-[#0F2057] to-[#1E3A8A] mx-1.5 rounded-xl mb-1.5">
        <p class="text-[10px] font-black text-[#FACC15] uppercase tracking-wide">Elemento</p>
        <p id="ctx-elem-tag"  class="text-white text-[11px] font-mono truncate mt-0.5"></p>
        <p id="ctx-elem-txt"  class="text-blue-200 text-[10px] truncate"></p>
      </div>
      <button id="ctx-bug"
              class="w-full text-left px-4 py-2.5 text-sm hover:bg-red-50 hover:text-red-700 flex items-center gap-3 font-semibold transition-colors group">
        <span class="text-base">🐛</span>
        <div>
          <div>Reportar bug aquí</div>
          <div class="text-[10px] text-gray-400 group-hover:text-red-400">Algo no funciona bien</div>
        </div>
      </button>
      <button id="ctx-cambio"
              class="w-full text-left px-4 py-2.5 text-sm hover:bg-green-50 hover:text-green-700 flex items-center gap-3 font-semibold transition-colors group">
        <span class="text-base">✏️</span>
        <div>
          <div>Quiero un cambio aquí</div>
          <div class="text-[10px] text-gray-400 group-hover:text-green-400">Color, texto, tamaño, etc.</div>
        </div>
      </button>
      <div class="border-t border-gray-100 my-1"></div>
      <button id="ctx-zona"
              class="w-full text-left px-4 py-2.5 text-sm hover:bg-amber-50 hover:text-amber-700 flex items-center gap-3 font-semibold transition-colors group">
        <span class="text-base">📐</span>
        <div>
          <div>Seleccionar zona</div>
          <div class="text-[10px] text-gray-400 group-hover:text-amber-500">Arrastra de A → B para recortar</div>
        </div>
      </button>
    </div>

    <!-- ── OVERLAY DRAG A→B ───────────────────────────────────── -->
    <div id="drag-overlay" class="hidden fixed inset-0 z-[99998]" style="cursor:crosshair">
      <div class="fixed top-4 left-1/2 -translate-x-1/2 bg-black/80 text-white text-xs px-5 py-2.5 rounded-full font-bold shadow-xl flex items-center gap-2">
        <span>📐</span> Arrastra para seleccionar la zona · <kbd class="bg-white/20 px-1.5 py-0.5 rounded text-[10px]">ESC</kbd> para cancelar
      </div>
      <div id="drag-selection"
           class="absolute border-2 border-[#FACC15] rounded"
           style="display:none;background:rgba(250,204,21,0.08);box-shadow:0 0 0 9999px rgba(0,0,0,0.35)"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
    // ── Captura errores JS globales ──────────────────────────────
    window.__bugJsErrors = [];
    window.addEventListener('error', function(e) {
      window.__bugJsErrors.push((e.message||'Error') + ' — ' + (e.filename||'') + ':' + (e.lineno||''));
      if (window.__bugJsErrors.length > 20) window.__bugJsErrors.shift();
    });
    window.addEventListener('unhandledrejection', function(e) {
      window.__bugJsErrors.push('Promise: ' + (e.reason?.message || String(e.reason)));
      if (window.__bugJsErrors.length > 20) window.__bugJsErrors.shift();
    });

    function detectarNavegador() {
      const ua = navigator.userAgent;
      if (ua.includes('Edg/'))     return 'Microsoft Edge';
      if (ua.includes('OPR/'))     return 'Opera';
      if (ua.includes('Chrome/'))  return 'Google Chrome';
      if (ua.includes('Firefox/')) return 'Mozilla Firefox';
      if (ua.includes('Safari/'))  return 'Safari';
      return ua.slice(0, 80);
    }
    function detectarOS() {
      const ua = navigator.userAgent;
      if (ua.includes('Windows NT 10')) return 'Windows 10/11';
      if (ua.includes('Windows'))       return 'Windows';
      if (ua.includes('Mac OS X'))      return 'macOS';
      if (ua.includes('Android'))       return 'Android';
      if (ua.includes('iPhone'))        return 'iOS';
      if (ua.includes('Linux'))         return 'Linux';
      return 'Desconocido';
    }

    // ── Genera selector CSS legible para un elemento ─────────────
    function getCssSelector(el) {
      if (!el || el === document.body) return 'body';
      if (el.id) return '#' + el.id;
      const ignorar = /^(hover|focus|active|group|transition|duration|ease|opacity|scale|translate|rotate|text-|bg-|px-|py-|p-|m-|w-|h-|rounded|flex|grid|hidden|block|inline|fixed|absolute|relative|z-|top-|bottom-|left-|right-|border|shadow|font-|leading-|tracking-|gap-|space-|col-|row-|overflow|cursor|select|pointer|sr-|min-|max-|ring|aspect)/;
      const parts = [];
      let cur = el;
      for (let i = 0; i < 4 && cur && cur !== document.body; i++) {
        if (cur.id) { parts.unshift('#' + cur.id); break; }
        let sel = cur.tagName.toLowerCase();
        const classes = [...cur.classList].filter(c => !ignorar.test(c)).slice(0, 2);
        if (classes.length) sel += '.' + classes.join('.');
        const sibs = cur.parentElement ? [...cur.parentElement.children].filter(s => s.tagName === cur.tagName) : [];
        if (sibs.length > 1) sel += ':nth-child(' + ([...cur.parentElement.children].indexOf(cur) + 1) + ')';
        parts.unshift(sel);
        cur = cur.parentElement;
      }
      return parts.join(' > ');
    }

    // ── Alpine: bugReporter ──────────────────────────────────────
    function bugReporter() {
      return {
        open: false,
        tipo: 'bug',
        enviando: false,
        enviado: false,
        errorEnvio: '',
        bugId: null,
        capturando: false,
        screenshot: null,
        severidad: 'medio',
        descripcion: '',
        errDesc: false,
        elementoCapturado: null,
        urlActual: window.location.href,
        navegador: detectarNavegador(),
        sistema_os: detectarOS(),
        resolucion: window.screen.width + 'x' + window.screen.height,
        get jsErrors() { return window.__bugJsErrors || []; },
        severidades: [
          { val:'critico', label:'Crítico', emoji:'🔴', activeClass:'bg-red-600 text-white',    ring:'ring-red-400' },
          { val:'alto',    label:'Alto',    emoji:'🟠', activeClass:'bg-orange-500 text-white', ring:'ring-orange-400' },
          { val:'medio',   label:'Medio',   emoji:'🟡', activeClass:'bg-amber-400 text-white',  ring:'ring-amber-400' },
          { val:'bajo',    label:'Bajo',    emoji:'🟢', activeClass:'bg-green-500 text-white',  ring:'ring-green-400' },
        ],
        init() {
          // Exponer referencia global para que el menú contextual pueda llamar métodos
          window.__bugReporter = this;
        },
        // Llamado desde el menú contextual o drag overlay
        abrirConElemento(tipo, elementoInfo, screenshotData) {
          this.tipo = tipo;
          this.elementoCapturado = elementoInfo || null;
          if (screenshotData) this.screenshot = screenshotData;
          this.enviado = false;
          this.errorEnvio = '';
          this.errDesc = false;
          this.descripcion = '';
          this.open = true;
        },
        async capturarPantalla() {
          this.capturando = true;
          this.open = false;
          await new Promise(r => setTimeout(r, 300));
          try {
            const canvas = await html2canvas(document.body, { scale: 0.6, useCORS: true, logging: false });
            this.screenshot = canvas.toDataURL('image/jpeg', 0.7);
          } catch(e) { this.screenshot = null; }
          this.open = true;
          this.capturando = false;
        },
        async enviar() {
          this.errDesc = false; this.errorEnvio = '';
          if (!this.descripcion.trim()) { this.errDesc = true; return; }
          this.enviando = true;
          try {
            const res = await fetch('<?= BASE_URL ?>/admin/ajax/bug-report.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                tipo:               this.tipo,
                severidad:          this.severidad,
                descripcion:        this.descripcion,
                url:                this.urlActual,
                navegador:          this.navegador,
                sistema_os:         this.sistema_os,
                resolucion:         this.resolucion,
                js_errors:          this.jsErrors.length ? this.jsErrors : null,
                screenshot:         this.screenshot,
                elemento_selector:  this.elementoCapturado?.selector || null,
                elemento_texto:     this.elementoCapturado?.texto    || null,
              })
            });
            const data = await res.json();
            if (data.ok) { this.enviado = true; this.bugId = data.id; }
            else { this.errorEnvio = data.error || 'Error desconocido.'; }
          } catch(e) { this.errorEnvio = 'No se pudo conectar.'; }
          this.enviando = false;
        },
        resetear() {
          this.enviado = false; this.errorEnvio = '';
          this.descripcion = ''; this.screenshot = null;
          this.severidad = 'medio'; this.bugId = null;
          this.tipo = 'bug'; this.elementoCapturado = null;
          this.$nextTick(() => {
            if (this.$refs.descArea) this.$refs.descArea.value = '';
          });
        }
      };
    }

    // ── Menú contextual ──────────────────────────────────────────
    (function() {
      const menu    = document.getElementById('ctx-menu');
      const overlay = document.getElementById('drag-overlay');
      let ctxEl     = null;
      let dragTipo  = 'bug';

      function ocultarMenu() { menu.classList.add('hidden'); }

      document.addEventListener('contextmenu', function(e) {
        // Ignorar si el click fue dentro del menú, del panel bug-reporter o del overlay
        if (e.target.closest('#ctx-menu, #bug-reporter, #drag-overlay')) return;

        e.preventDefault();
        ctxEl = e.target;

        // Llenar cabecera del menú con info del elemento
        const selector = getCssSelector(ctxEl);
        const texto    = (ctxEl.textContent || '').trim().slice(0, 60);
        const tag      = ctxEl.tagName.toLowerCase() +
                         (ctxEl.id ? '#' + ctxEl.id : '') +
                         (ctxEl.className && typeof ctxEl.className === 'string'
                           ? '.' + ctxEl.className.trim().split(/\s+/).slice(0,2).join('.')
                           : '');
        document.getElementById('ctx-elem-tag').textContent = tag.slice(0, 50);
        document.getElementById('ctx-elem-txt').textContent = texto || '(sin texto)';

        // Posicionar menú
        const vw = window.innerWidth, vh = window.innerHeight;
        const mw = 230, mh = 210;
        let x = e.clientX, y = e.clientY;
        if (x + mw > vw) x = vw - mw - 8;
        if (y + mh > vh) y = vh - mh - 8;
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';
        menu.classList.remove('hidden');
      });

      // Cerrar menú al hacer clic fuera
      document.addEventListener('click', function(e) {
        if (!e.target.closest('#ctx-menu')) ocultarMenu();
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { ocultarMenu(); cerrarDrag(); }
      });

      // ── Opciones del menú ─────────────────────────────────────
      // Sin html2canvas aquí: evita residuos que bloquean el teclado.
      // El usuario puede capturar pantalla manualmente desde el panel.
      function abrirReporterConElemento(tipo) {
        ocultarMenu();
        if (!window.__bugReporter) return;
        const selector = getCssSelector(ctxEl);
        const texto    = (ctxEl.textContent || '').trim().slice(0, 200);
        const tag      = ctxEl.tagName.toLowerCase();
        // Liberar foco del menú ANTES de abrir el panel
        if (document.activeElement) document.activeElement.blur();
        window.__bugReporter.abrirConElemento(tipo, { selector, texto, tag }, null);
      }

      document.getElementById('ctx-bug').addEventListener('click',    () => abrirReporterConElemento('bug'));
      document.getElementById('ctx-cambio').addEventListener('click', () => abrirReporterConElemento('cambio'));
      document.getElementById('ctx-zona').addEventListener('click',   () => { ocultarMenu(); iniciarDrag(); });

      // ── Drag A→B ──────────────────────────────────────────────
      let dragState = { active: false, x0: 0, y0: 0 };
      const dragSel = document.getElementById('drag-selection');

      function iniciarDrag() {
        overlay.classList.remove('hidden');
        dragSel.style.display = 'none';
        dragState.active = false;
      }
      function cerrarDrag() {
        overlay.classList.add('hidden');
        dragSel.style.display = 'none';
        dragState.active = false;
      }

      overlay.addEventListener('mousedown', function(e) {
        dragState = { active: true, x0: e.clientX, y0: e.clientY };
        dragSel.style.cssText += ';display:block;left:' + e.clientX + 'px;top:' + e.clientY + 'px;width:0;height:0';
      });
      overlay.addEventListener('mousemove', function(e) {
        if (!dragState.active) return;
        const x = Math.min(e.clientX, dragState.x0);
        const y = Math.min(e.clientY, dragState.y0);
        const w = Math.abs(e.clientX - dragState.x0);
        const h = Math.abs(e.clientY - dragState.y0);
        dragSel.style.left   = x + 'px';
        dragSel.style.top    = y + 'px';
        dragSel.style.width  = w + 'px';
        dragSel.style.height = h + 'px';
      });
      overlay.addEventListener('mouseup', async function(e) {
        if (!dragState.active) return;
        dragState.active = false;
        const x0 = Math.min(e.clientX, dragState.x0);
        const y0 = Math.min(e.clientY, dragState.y0);
        const w  = Math.abs(e.clientX - dragState.x0);
        const h  = Math.abs(e.clientY - dragState.y0);
        cerrarDrag();
        if (w < 10 || h < 10) return;

        let shot = null;
        try {
          const scale  = 1;
          const canvas = await html2canvas(document.body, { scale, useCORS: true, logging: false });
          const crop   = document.createElement('canvas');
          crop.width  = w * scale;
          crop.height = h * scale;
          crop.getContext('2d').drawImage(canvas, x0 * scale, y0 * scale, w * scale, h * scale, 0, 0, w * scale, h * scale);
          shot = crop.toDataURL('image/jpeg', 0.9);
        } catch(e) {}
        document.body.style.pointerEvents = '';
        document.documentElement.style.pointerEvents = '';

        if (window.__bugReporter) {
          window.__bugReporter.abrirConElemento('cambio', null, shot);
        }
      });
    })();
    </script>

    <?php endif; ?>

    <?php if (!empty($mobile_nav_items)): ?>
    <!-- Bottom nav movil -->
    <nav class="mobile-bottom-shell lg:hidden fixed left-4 right-4 bottom-3 z-[9996]"
         style="padding-bottom:env(safe-area-inset-bottom)">
      <div class="relative mx-auto max-w-[430px] pt-8">
        <a href="<?= htmlspecialchars(BASE_URL . '/admin/dashboard.php') ?>"
           class="mobile-bottom-logo"
           title="Alianza para el Progreso"
           aria-label="Alianza para el Progreso">A</a>
        <div class="mobile-bottom-track">
          <?php foreach ($mobile_nav_items as $idx => $item):
            $href = BASE_URL . '/admin/' . $item['href'];
            $active = $current === $item['href']
                || ($item['id'] === 'noticias' && in_array($current, ['noticias.php','noticia-form.php','categorias-noticias.php'], true))
                || ($item['id'] === 'candidatos' && in_array($current, ['candidatos-distritales.php','candidato-nuevo.php'], true));
            $active_edge = $active ? ($idx === 0 ? 'is-first' : ($idx === count($mobile_nav_items) - 1 ? 'is-last' : '')) : '';
          ?>
          <a href="<?= htmlspecialchars($href) ?>"
             class="mobile-bottom-link <?= $active ? 'is-active ' . $active_edge : '' ?>"
             title="<?= htmlspecialchars($item['label']) ?>"
             aria-label="<?= htmlspecialchars($item['label']) ?>">
            <?php if (!empty($item['icon_img'])): ?>
              <img src="<?= htmlspecialchars(admin_sidebar_icon_url($item['icon_img'])) ?>" alt="">
            <?php else: ?>
              <i class="ti <?= htmlspecialchars($item['icon']) ?> text-[42px]"></i>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </nav>
    <?php endif; ?>

    <section x-show="mobileNavOpen"
             x-transition.opacity
             x-cloak
             class="mobile-app-shell fixed inset-0 z-[9997] overflow-y-auto lg:hidden">
      <div class="sticky top-0 z-10 border-b border-blue-100/80 bg-white/90 px-4 py-4 shadow-sm backdrop-blur-xl">
        <div class="mx-auto flex max-w-md items-center justify-between gap-3">
          <button type="button"
                  x-show="mobileScreen"
                  @click="mobileScreen = null"
                  class="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-700 active:scale-95">
            <i class="ti ti-arrow-left text-xl"></i>
          </button>
          <button type="button"
                  x-show="!mobileScreen"
                  @click="closeMobileNav()"
                  class="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-700 active:scale-95">
            <i class="ti ti-x text-xl"></i>
          </button>
          <div class="min-w-0 flex-1 text-center">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[#EF3340]">Panel administrativo</p>
            <h2 class="truncate text-lg font-black text-[#0F2057]"
                x-text="currentMobileModule ? currentMobileModule.label : 'Modulos principales'"></h2>
          </div>
          <a href="<?= htmlspecialchars(BASE_URL . '/admin/dashboard.php') ?>"
             class="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#1E3A8A] text-white shadow-lg shadow-blue-900/20 active:scale-95">
            <i class="ti ti-home text-xl"></i>
          </a>
        </div>
      </div>

      <div class="mx-auto max-w-md px-4 pb-32 pt-5">
        <div x-show="!mobileScreen" x-transition>
          <div class="grid grid-cols-2 gap-3">
            <template x-for="module in mobileModules" :key="module.id">
              <button type="button"
                      @click="openMobileModule(module)"
                      class="mobile-app-card min-h-[132px] p-4 text-left">
                <span class="relative z-[1] mobile-app-icon">
                  <img x-show="module.icon_img" :src="mobileIconUrl(module.icon_img)" alt="">
                  <i x-show="!module.icon_img" :class="'ti ' + module.icon + ' text-2xl text-white'"></i>
                </span>
                <span class="relative z-[1] mt-4 block text-base font-black leading-tight text-[#0F2057]" x-text="module.label"></span>
                <span class="relative z-[1] mt-1 block text-xs font-bold text-slate-400"
                      x-text="module.children && module.children.length ? module.children.length + ' opciones' : 'Acceso directo'"></span>
              </button>
            </template>
          </div>
        </div>

        <div x-show="mobileScreen" x-transition>
          <div class="space-y-3">
            <template x-for="item in (currentMobileModule?.children ?? [])" :key="item.id || item.href">
              <div class="mobile-app-action p-3">
                <a :href="adminUrl(item.href)"
                   :target="item.target ?? '_self'"
                   class="flex items-center gap-3 rounded-2xl px-1 py-1">
                  <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-[#1E3A8A]">
                    <img x-show="item.icon_img" :src="mobileIconUrl(item.icon_img)" alt="" class="h-6 w-6 object-contain">
                    <i x-show="!item.icon_img" :class="'ti ' + item.icon + ' text-xl'"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-black text-slate-800" x-text="item.label"></span>
                    <span class="mt-0.5 block text-[11px] font-bold text-slate-400">Abrir modulo</span>
                  </span>
                  <i class="ti ti-chevron-right text-lg text-slate-300"></i>
                </a>
                <div x-show="item.children && item.children.length" class="mt-2 grid grid-cols-1 gap-2 border-t border-slate-100 pt-2">
                  <template x-for="child in item.children" :key="child.href">
                    <a :href="adminUrl(child.href)"
                       :target="child.target ?? '_self'"
                       class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600 active:scale-[.98]">
                      <i :class="'ti ' + child.icon + ' text-sm text-[#1E3A8A]'"></i>
                      <span class="truncate" x-text="child.label"></span>
                    </a>
                  </template>
                </div>
              </div>
            </template>

            <a x-show="currentMobileModule && currentMobileModule.href"
               :href="adminUrl(currentMobileModule?.href)"
               class="mobile-app-action flex items-center gap-3 p-4">
              <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#EF3340] text-white">
                <i class="ti ti-arrow-right text-xl"></i>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-black text-slate-800" x-text="'Ir a ' + (currentMobileModule?.label ?? '')"></span>
                <span class="block text-[11px] font-bold text-slate-400">Pantalla principal del modulo</span>
              </span>
            </a>
          </div>
        </div>
      </div>
    </section>

    <!-- Contenido dinamico -->
    <main class="admin-main-content flex-1 p-4 sm:p-6 lg:p-8">
