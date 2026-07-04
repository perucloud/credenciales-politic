<?php
// ============================================================
// config-pagina.php — Configuración global del sitio
// Tabs: Favicon | Login | Nombre del Partido | Colores | Contador | Mantenimiento
// ============================================================
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/config.php';

require_login();
require_modulo($pdo, 'config_pagina');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$config = [];
try {
    $config = $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

// Estadísticas de visitas para tab Contador
$visit_stats = ['total' => 0, 'hoy' => 0, 'semana' => 0, 'mes' => 0];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitas (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        ip_hash    VARCHAR(64) NOT NULL,
        fecha      DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_fecha (ip_hash, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $row = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(fecha = CURDATE()) AS hoy,
        SUM(fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS semana,
        SUM(fecha >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS mes
    FROM visitas")->fetch(PDO::FETCH_ASSOC);
    $visit_stats = [
        'total'  => (int)($row['total']  ?? 0),
        'hoy'    => (int)($row['hoy']    ?? 0),
        'semana' => (int)($row['semana'] ?? 0),
        'mes'    => (int)($row['mes']    ?? 0),
    ];
} catch (Exception $e) {}

function cpag_val(array $config, string $key, string $default = ''): string {
    return htmlspecialchars(cfg_value($config, $key, $default), ENT_QUOTES);
}

$flash      = '';
$flash_type = 'ok';
$active_tab = $_GET['tab'] ?? 'favicon';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = $_POST['tab'] ?? 'favicon';

    if ($active_tab === 'favicon') {
        $favicon_url = trim($_POST['site_favicon'] ?? '');
        try {
            cfg_save_values($pdo, ['site_favicon' => $favicon_url]);
            $config['site_favicon'] = $favicon_url;
            log_activity($pdo, 'Actualizo favicon del sitio', 'config_pagina');
            $flash = 'Favicon actualizado correctamente.';
        } catch (Exception $e) {
            $flash      = 'Error al guardar: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }

    if ($active_tab === 'partido') {
        $nombre = trim($_POST['partido_nombre'] ?? 'RENOVACION POPULAR');
        try {
            cfg_save_values($pdo, ['partido_nombre' => $nombre]);
            $config['partido_nombre'] = $nombre;
            log_activity($pdo, 'Actualizo nombre del partido/agrupacion', 'config_pagina');
            $flash = 'Nombre del partido actualizado correctamente.';
        } catch (Exception $e) {
            $flash      = 'Error al guardar: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }

    if ($active_tab === 'colores') {
        $color_keys = [
            'color_primary', 'color_accent',
            'color_btn_hero_primary', 'color_btn_hero_primary_text',
            'color_btn_hero_secondary', 'color_btn_hero_secondary_text',
            'color_btn_download', 'color_btn_download_text',
            'color_btn_cta_navbar', 'color_btn_cta_navbar_text',
            'color_btn_join', 'color_btn_join_text',
        ];
        $values = [];
        foreach ($color_keys as $key) {
            $val = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) {
                $values[$key] = $val;
            }
        }
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo paleta de colores del sitio', 'config_pagina');
            $flash = 'Colores actualizados correctamente.';
        } catch (Exception $e) {
            $flash      = 'Error al guardar: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }

    if ($active_tab === 'contador') {
        if (!empty($_POST['reset_visits'])) {
            try {
                $pdo->exec("DELETE FROM visitas");
                log_activity($pdo, 'Reinicio el contador de visitas', 'config_pagina');
                $flash = 'Contador de visitas reiniciado a cero.';
                $visit_stats = ['total' => 0, 'hoy' => 0, 'semana' => 0, 'mes' => 0];
            } catch (Exception $e) {
                $flash      = 'Error al reiniciar: ' . $e->getMessage();
                $flash_type = 'error';
            }
        } else {
            $cd_active    = isset($_POST['countdown_active'])     ? '1' : '0';
            $visit_active = isset($_POST['visit_counter_active']) ? '1' : '0';
            $cd_date      = trim($_POST['countdown_date']  ?? '');
            $cd_title     = trim($_POST['countdown_title'] ?? '');
            $cd_label     = trim($_POST['countdown_label'] ?? '');
            if ($cd_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cd_date)) $cd_date = '';
            try {
                cfg_save_values($pdo, [
                    'countdown_active'     => $cd_active,
                    'countdown_date'       => $cd_date,
                    'countdown_title'      => $cd_title,
                    'countdown_label'      => $cd_label,
                    'visit_counter_active' => $visit_active,
                ]);
                $config = array_merge($config, [
                    'countdown_active'     => $cd_active,
                    'countdown_date'       => $cd_date,
                    'countdown_title'      => $cd_title,
                    'countdown_label'      => $cd_label,
                    'visit_counter_active' => $visit_active,
                ]);
                log_activity($pdo, 'Actualizo configuracion del contador', 'config_pagina');
                $flash = 'Configuracion guardada correctamente.';
            } catch (Exception $e) {
                $flash      = 'Error al guardar: ' . $e->getMessage();
                $flash_type = 'error';
            }
        }
    }

    if ($active_tab === 'login') {
        $login_logo     = trim($_POST['login_logo']     ?? '/assets/img/logos/logorp.webp');
        $login_subtitle = trim($_POST['login_subtitle'] ?? 'Panel Administrativo');
        $login_hero_img = trim($_POST['login_hero_img'] ?? '');
        try {
            cfg_save_values($pdo, [
                'login_logo'     => $login_logo,
                'login_subtitle' => $login_subtitle,
                'login_hero_img' => $login_hero_img,
            ]);
            $config['login_logo']     = $login_logo;
            $config['login_subtitle'] = $login_subtitle;
            $config['login_hero_img'] = $login_hero_img;
            log_activity($pdo, 'Actualizo configuracion de la pantalla de login', 'config_pagina');
            $flash = 'Configuracion del login actualizada correctamente.';
        } catch (Exception $e) {
            $flash      = 'Error al guardar: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }

    if ($active_tab === 'mantenimiento') {
        $maint_active       = isset($_POST['maintenance_active']) ? '1' : '0';
        $maint_title        = trim($_POST['maint_title']   ?? 'Sitio en Mantenimiento');
        $maint_message      = trim($_POST['maint_message'] ?? 'Estamos trabajando para mejorar tu experiencia. Volvemos pronto.');
        $maint_eta          = trim($_POST['maint_eta']     ?? '');
        $maint_logo         = trim($_POST['maint_logo']    ?? '');
        try {
            cfg_save_values($pdo, [
                'maintenance_active' => $maint_active,
                'maint_title'        => $maint_title,
                'maint_message'      => $maint_message,
                'maint_eta'          => $maint_eta,
                'maint_logo'         => $maint_logo,
            ]);
            $config['maintenance_active'] = $maint_active;
            $config['maint_title']        = $maint_title;
            $config['maint_message']      = $maint_message;
            $config['maint_eta']          = $maint_eta;
            $config['maint_logo']         = $maint_logo;
            $accion = $maint_active === '1' ? 'ACTIVÓ' : 'desactivó';
            log_activity($pdo, $accion . ' el modo mantenimiento del sitio', 'config_pagina');
            $flash = $maint_active === '1'
                ? 'Modo mantenimiento ACTIVADO. El sitio público muestra la página de mantenimiento.'
                : 'Modo mantenimiento desactivado. El sitio público está visible.';
        } catch (Exception $e) {
            $flash      = 'Error al guardar: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }

    if ($flash && $flash_type === 'ok') {
        header('Location: config-pagina.php?tab=' . urlencode($active_tab) . '&ok=1');
        exit;
    }
}

if (isset($_GET['ok'])) $flash = 'Cambios guardados correctamente.';
$active_tab = $_GET['tab'] ?? $active_tab;

$page_title = 'Configurar Página';
require __DIR__ . '/layout.php';
?>

<style>
  [x-cloak] { display: none !important; }
  .cpag-tab {
    padding: 0.5rem 1.1rem; font-size: 0.8rem; font-weight: 700; border-radius: 0.6rem;
    border: 2px solid transparent; color: #64748b; background: transparent; cursor: pointer;
    transition: all 0.15s; white-space: nowrap;
  }
  .cpag-tab:hover { background: #e2e8f0; color: #1e293b; }
  .cpag-tab.is-active { background: #049CD4; color: #fff; border-color: #049CD4; }
</style>

<div class="max-w-4xl mx-auto space-y-6" x-data="{ activeTab: '<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>' }">

  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-black text-gray-900">Configurar Página</h1>
      <p class="text-sm text-gray-400 mt-0.5">Favicon, login, identidad del partido, colores y contador del sitio.</p>
    </div>
    <a href="<?= BASE_URL ?>/index.php" target="_blank"
       class="inline-flex items-center gap-2 text-xs font-bold text-gray-500 hover:text-[#049CD4] border border-gray-200 rounded-xl px-4 py-2 bg-white transition-colors">
      Ver sitio
    </a>
  </div>

  <?php if ($flash): ?>
  <div class="px-4 py-3 rounded-xl text-sm font-semibold <?= $flash_type === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-1.5 flex flex-wrap gap-1">
    <?php foreach ([
      'favicon'        => 'Favicon',
      'login'          => 'Login',
      'partido'        => 'Nombre del Partido',
      'colores'        => 'Colores',
      'contador'       => 'Contador',
      'mantenimiento'  => 'Mantenimiento',
    ] as $tid => $tlabel): ?>
    <button type="button" @click="activeTab = '<?= $tid ?>'" :class="activeTab === '<?= $tid ?>' ? 'is-active' : ''" class="cpag-tab">
      <?= $tlabel ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- TAB: FAVICON -->
  <form method="POST" x-show="activeTab === 'favicon'" class="space-y-6">
    <input type="hidden" name="tab" value="favicon">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Favicon del sitio</h2>
        <p class="text-white/80 text-xs mt-0.5">Ícono que aparece en la pestaña del navegador, en el admin y en el sitio público.</p>
      </div>
      <div class="p-6 space-y-6">
        <div x-data="{ url: '<?= cpag_val($config, 'site_favicon') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">URL del favicon</label>
          <p class="text-xs text-gray-400 mb-3">Sube una imagen cuadrada (PNG o WEBP, mínimo 32×32 px).</p>
          <div class="flex gap-2 items-center">
            <input name="site_favicon" x-model="url" placeholder="/assets/img/logos/logorp.webp"
                   class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-300">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')"
                    class="px-4 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 hover:bg-indigo-100 transition-colors flex-shrink-0">
              Media
            </button>
          </div>
          <div class="mt-4 flex items-center gap-4 p-4 bg-gray-50 rounded-xl border border-gray-100">
            <template x-if="url && url !== ''">
              <img :src="url.startsWith('/') ? '<?= BASE_URL ?>' + url : url" class="w-10 h-10 rounded-lg object-contain border border-gray-200 bg-white shadow-sm" alt="Preview favicon">
            </template>
            <div>
              <p class="text-sm font-black text-gray-700">Vista previa del favicon</p>
              <p class="text-xs text-gray-400 mt-0.5">Así se verá en la pestaña del navegador.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar Favicon</button>
    </div>
  </form>

  <!-- TAB: LOGIN -->
  <form method="POST" x-show="activeTab === 'login'" class="space-y-6">
    <input type="hidden" name="tab" value="login">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Pantalla de inicio de sesión</h2>
        <p class="text-white/80 text-xs mt-0.5">Personaliza el logo y el subtítulo del login del panel admin.</p>
      </div>
      <div class="p-6 space-y-6">
        <div x-data="{ url: '<?= cpag_val($config, 'login_logo', '/assets/img/logos/logorp.webp') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Logo del panel de acceso</label>
          <div class="flex gap-2">
            <input name="login_logo" x-model="url" class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-blue-300">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')"
                    class="px-4 py-2 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 hover:bg-indigo-100 transition-colors flex-shrink-0">Media</button>
          </div>
          <div class="mt-3 flex items-center gap-4 p-4 bg-gray-50 rounded-xl border border-gray-100">
            <template x-if="url && url !== ''">
              <img :src="url.match(/^https?:\/\//) ? url : '<?= BASE_URL ?>/' + url.replace(/^\/+/,'')" class="h-16 object-contain rounded-lg border border-gray-200 bg-white p-1 shadow-sm" alt="Preview logo login">
            </template>
          </div>
        </div>

        <div x-data="{ url: '<?= cpag_val($config, 'login_hero_img', '') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Foto lateral (desktop)</label>
          <p class="text-xs text-gray-400 mb-3">Imagen en la columna izquierda del login en pantallas grandes.</p>
          <div class="flex gap-2">
            <input name="login_hero_img" x-model="url" class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-blue-300">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')"
                    class="px-4 py-2 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 hover:bg-indigo-100 transition-colors flex-shrink-0">Media</button>
          </div>
        </div>

        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Subtítulo del login</label>
          <input name="login_subtitle" value="<?= cpag_val($config, 'login_subtitle', 'Panel Administrativo') ?>" maxlength="120"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <a href="<?= BASE_URL ?>/admin/login.php" target="_blank" class="bg-white border border-gray-200 text-gray-600 font-bold px-5 py-3 rounded-xl text-sm">Ver login</a>
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar Login</button>
    </div>
  </form>

  <!-- TAB: NOMBRE DEL PARTIDO -->
  <form method="POST" x-show="activeTab === 'partido'" class="space-y-6">
    <input type="hidden" name="tab" value="partido">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Nombre del Partido / Agrupación Política</h2>
        <p class="text-white/80 text-xs mt-0.5">Se aplica globalmente en PDFs, footer, hero y verificación de credenciales.</p>
      </div>
      <div class="p-6 space-y-6">
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5">
          <label class="block text-xs font-black text-yellow-700 uppercase mb-1">Nombre del Partido</label>
          <input name="partido_nombre" value="<?= cpag_val($config, 'partido_nombre', 'RENOVACION POPULAR') ?>" maxlength="120"
                 class="w-full border border-yellow-300 rounded-xl px-3 py-2.5 text-sm font-bold bg-white focus:outline-none focus:ring-2 focus:ring-yellow-400"
                 placeholder="RENOVACION POPULAR">
        </div>
        <div>
          <p class="text-xs font-black text-gray-500 uppercase tracking-widest mb-3">Dónde se aplica este valor</p>
          <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach (['PDFs de militantes y simpatizantes', 'Exportación PDF de personeros', 'Verificación pública de credenciales', 'Footer y navegación del sitio público', 'Hero y secciones del landing page'] as $lbl): ?>
            <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
              <span class="text-xs font-semibold text-gray-600"><?= $lbl ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar Nombre</button>
    </div>
  </form>

  <!-- TAB: COLORES -->
  <form method="POST" x-show="activeTab === 'colores'" class="space-y-6">
    <input type="hidden" name="tab" value="colores">
    <?= csrf_field() ?>
    <?php
    $color_groups = [
        ['label' => 'Colores globales', 'items' => [
            'color_primary' => ['Azul principal (80%)', 'Navbar, hero, secciones destacadas.', '#049CD4'],
            'color_accent'  => ['Acento secundario (15%)', 'Detalles, etiquetas, bordes.', '#028FB7'],
        ]],
        ['label' => 'Botón hero primario', 'preview' => 'prev-hero-primary', 'items' => [
            'color_btn_hero_primary'      => ['Fondo', 'Botón principal del hero.', '#049CD4'],
            'color_btn_hero_primary_text' => ['Texto', 'Color del texto e ícono.', '#FFFFFF'],
        ]],
        ['label' => 'Botón hero secundario', 'preview' => 'prev-hero-secondary', 'items' => [
            'color_btn_hero_secondary'      => ['Fondo', 'Botón secundario del hero.', '#028FB7'],
            'color_btn_hero_secondary_text' => ['Texto', 'Color del texto e ícono.', '#FFFFFF'],
        ]],
        ['label' => 'Botón descarga / plan', 'preview' => 'prev-download', 'items' => [
            'color_btn_download'      => ['Fondo', 'Botones de descarga/plan.', '#049CD4'],
            'color_btn_download_text' => ['Texto', 'Color del texto e ícono.', '#FFFFFF'],
        ]],
        ['label' => 'Botón CTA navbar', 'preview' => 'prev-navbar', 'items' => [
            'color_btn_cta_navbar'      => ['Fondo', 'Esquina superior derecha del menú.', '#028FB7'],
            'color_btn_cta_navbar_text' => ['Texto', 'Color del texto.', '#FFFFFF'],
        ]],
        ['label' => 'Botón formulario Únete', 'preview' => 'prev-join', 'items' => [
            'color_btn_join'      => ['Fondo', 'Botón "Unirme al Cambio".', '#028FB7'],
            'color_btn_join_text' => ['Texto', 'Color del texto.', '#FFFFFF'],
        ]],
    ];
    ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Paleta de colores del sitio</h2>
        <p class="text-white/80 text-xs mt-0.5">Cada botón tiene su propio color de fondo y de texto.</p>
      </div>
      <div class="p-6 space-y-6">
        <?php foreach ($color_groups as $group): ?>
        <div>
          <p class="text-xs font-black text-gray-500 uppercase tracking-widest mb-3"><?= htmlspecialchars($group['label']) ?></p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($group['items'] as $key => [$label, $desc, $default]): ?>
            <div class="flex items-start gap-4 p-4 rounded-xl border border-gray-100 bg-gray-50 hover:border-blue-200 transition-colors">
              <input type="color" name="<?= $key ?>" value="<?= htmlspecialchars(cfg_value($config, $key, $default)) ?>"
                     class="w-12 h-12 rounded-xl border border-gray-200 cursor-pointer p-0.5 bg-white shadow-sm flex-shrink-0" title="<?= htmlspecialchars($label) ?>">
              <div class="min-w-0">
                <p class="text-sm font-black text-gray-800 leading-tight"><?= htmlspecialchars($label) ?></p>
                <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($desc) ?></p>
                <code class="text-[10px] text-gray-400 font-mono"><?= htmlspecialchars($key) ?></code>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($group['preview'])): ?>
          <div class="mt-3 flex items-center gap-3">
            <span class="text-xs text-gray-400">Preview:</span>
            <span id="<?= $group['preview'] ?>" class="inline-flex items-center gap-2 text-sm font-black px-6 py-2.5 rounded-full shadow cursor-default transition-all">Botón</span>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar Colores</button>
    </div>
  </form>

  <!-- TAB: CONTADOR -->
  <form method="POST" x-show="activeTab === 'contador'" class="space-y-6">
    <input type="hidden" name="tab" value="contador">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Contador regresivo</h2>
        <p class="text-white/80 text-xs mt-0.5">Se puede mostrar en el hero del landing page.</p>
      </div>
      <div class="p-6 space-y-6">
        <div class="flex items-center justify-between p-4 rounded-xl border border-gray-100 bg-gray-50">
          <div>
            <p class="text-sm font-black text-gray-800">Mostrar contador en el sitio</p>
            <p class="text-xs text-gray-400 mt-0.5">Si está desactivado el bloque no aparece en la web.</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" name="countdown_active" value="1" class="sr-only peer" <?= cfg_value($config, 'countdown_active', '0') === '1' ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:bg-[#049CD4] transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
          </label>
        </div>
        <div class="p-4 rounded-xl border border-gray-100 bg-gray-50">
          <label class="block text-sm font-black text-gray-800 mb-1">Fecha objetivo</label>
          <input type="date" name="countdown_date" value="<?= cpag_val($config, 'countdown_date', '') ?>"
                 class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div class="p-4 rounded-xl border border-gray-100 bg-gray-50">
          <label class="block text-sm font-black text-gray-800 mb-1">Título (encima de los dígitos)</label>
          <input type="text" name="countdown_title" value="<?= cpag_val($config, 'countdown_title', '') ?>" maxlength="80"
                 class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-300" placeholder="Faltan para...">
        </div>
        <div class="p-4 rounded-xl border border-gray-100 bg-gray-50">
          <label class="block text-sm font-black text-gray-800 mb-1">Etiqueta inferior</label>
          <input type="text" name="countdown_label" value="<?= cpag_val($config, 'countdown_label', '') ?>" maxlength="80"
                 class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-white font-black text-base">Contador de visitas</h2>
          <p class="text-emerald-100 text-xs mt-0.5">Visitantes únicos por IP, una vez por día.</p>
        </div>
      </div>
      <div class="p-6 space-y-6">
        <div class="flex items-center justify-between p-4 rounded-xl border border-gray-100 bg-gray-50">
          <div>
            <p class="text-sm font-black text-gray-800">Activar registro de visitas</p>
            <p class="text-xs text-gray-400 mt-0.5">Muestra el total en el footer del sitio.</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" name="visit_counter_active" value="1" class="sr-only peer" <?= cfg_value($config, 'visit_counter_active', '1') === '1' ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:bg-emerald-600 transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
          </label>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <?php foreach ([
            ['Total histórico', $visit_stats['total'],  'text-emerald-600', 'bg-emerald-50'],
            ['Hoy',            $visit_stats['hoy'],    'text-blue-600',    'bg-blue-50'],
            ['Esta semana',    $visit_stats['semana'], 'text-violet-600',  'bg-violet-50'],
            ['Este mes',       $visit_stats['mes'],    'text-orange-500',  'bg-orange-50'],
          ] as [$lbl, $val, $color, $bg]): ?>
          <div class="<?= $bg ?> rounded-2xl p-4 text-center border border-white">
            <p class="<?= $color ?> text-3xl font-black"><?= number_format($val) ?></p>
            <p class="text-gray-500 text-xs uppercase tracking-widest mt-1"><?= $lbl ?></p>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="p-4 rounded-xl border border-red-100 bg-red-50 flex items-center justify-between gap-4">
          <div>
            <p class="text-sm font-black text-red-700">Reiniciar contador</p>
            <p class="text-xs text-red-400 mt-0.5">Elimina todos los registros de visitas.</p>
          </div>
          <button type="submit" name="reset_visits" value="1" onclick="return confirm('¿Reiniciar el contador a cero?')"
                  class="flex-shrink-0 bg-red-600 hover:bg-red-700 text-white font-black text-xs px-4 py-2.5 rounded-xl shadow transition-colors">
            Reiniciar a 0
          </button>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar Contador</button>
    </div>
  </form>

  <!-- TAB: MANTENIMIENTO -->
  <?php $maint_on = cfg_value($config, 'maintenance_active', '0') === '1'; ?>
  <div x-show="activeTab === 'mantenimiento'" x-cloak>
  <form method="POST" class="space-y-6">
    <input type="hidden" name="tab" value="mantenimiento">
    <?= csrf_field() ?>

    <?php if ($maint_on): ?>
    <div class="flex items-start gap-3 bg-red-50 border-2 border-red-300 rounded-2xl px-5 py-4">
      <div>
        <p class="font-black text-red-700 text-sm">MODO MANTENIMIENTO ACTIVO</p>
        <p class="text-xs text-red-600 mt-0.5">El sitio público está mostrando la página de mantenimiento ahora mismo.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border <?= $maint_on ? 'border-red-200' : 'border-gray-100' ?> overflow-hidden">
      <div class="<?= $maint_on ? 'bg-gradient-to-r from-red-600 to-rose-700' : 'bg-gradient-to-r from-[#049CD4] to-[#028FB7]' ?> px-6 py-4">
        <h2 class="text-white font-black text-base">Modo Mantenimiento</h2>
        <p class="text-white/80 text-xs mt-0.5">Al activarlo, el sitio público muestra una página de mantenimiento. Tú (admin) sigues viendo el sitio normal.</p>
      </div>
      <div class="p-6 space-y-5">
        <div class="flex items-center justify-between p-5 rounded-2xl border-2 <?= $maint_on ? 'border-red-200 bg-red-50' : 'border-gray-100 bg-gray-50' ?>">
          <div>
            <p class="font-black text-gray-800 text-sm">Activar página de mantenimiento</p>
            <p class="text-xs text-gray-400 mt-0.5"><?= $maint_on ? 'Activo — el sitio público está en mantenimiento' : 'Inactivo — el sitio público es visible' ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input type="checkbox" name="maintenance_active" value="1" class="sr-only peer" <?= $maint_on ? 'checked' : '' ?>>
            <div class="w-14 h-7 bg-gray-200 peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:bg-red-500 transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-6 h-6 bg-white rounded-full shadow transition-transform peer-checked:translate-x-7"></div>
          </label>
        </div>

        <div x-data="{ url: '<?= cpag_val($config, 'maint_logo', '') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Logo (opcional)</label>
          <div class="flex gap-2">
            <input name="maint_logo" x-model="url" placeholder="Usa el logo principal si está vacío"
                   class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')"
                    class="px-4 py-2 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 hover:bg-indigo-100 transition-colors flex-shrink-0">Media</button>
          </div>
        </div>

        <div>
          <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Título principal</label>
          <input type="text" name="maint_title" value="<?= cpag_val($config, 'maint_title', 'Sitio en Mantenimiento') ?>" maxlength="80"
                 class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#049CD4]">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Mensaje / subtítulo</label>
          <textarea name="maint_message" rows="3" maxlength="300"
                    class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#049CD4] resize-none"><?= cpag_val($config, 'maint_message', 'Estamos trabajando para mejorar tu experiencia. Volvemos pronto.') ?></textarea>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase tracking-wide mb-1.5">Tiempo estimado (opcional)</label>
          <input type="text" name="maint_eta" value="<?= cpag_val($config, 'maint_eta', '') ?>" maxlength="80"
                 placeholder="Ej: Volvemos hoy a las 6:00 PM"
                 class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#049CD4]">
        </div>
      </div>
    </div>

    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <a href="<?= BASE_URL ?>/index.php" target="_blank" class="bg-white border border-gray-200 text-gray-600 font-bold px-5 py-3 rounded-xl text-sm">Ver sitio</a>
      <button type="submit" class="<?= $maint_on ? 'bg-red-500 hover:bg-red-600 text-white' : 'bg-[#049CD4] hover:bg-[#028FB7] text-white' ?> font-black px-8 py-3 rounded-xl text-sm shadow">
        <?= $maint_on ? 'Guardar / Desactivar' : 'Guardar / Activar' ?>
      </button>
    </div>
  </form>
  </div>

</div>

<script>
(function () {
  const pairs = [
    ['color_btn_hero_primary',      'color_btn_hero_primary_text',      'prev-hero-primary'],
    ['color_btn_hero_secondary',    'color_btn_hero_secondary_text',    'prev-hero-secondary'],
    ['color_btn_download',          'color_btn_download_text',          'prev-download'],
    ['color_btn_cta_navbar',        'color_btn_cta_navbar_text',        'prev-navbar'],
    ['color_btn_join',              'color_btn_join_text',              'prev-join'],
  ];
  function applyPreview() {
    pairs.forEach(([bgKey, textKey, previewId]) => {
      const bgInput   = document.querySelector(`input[name="${bgKey}"]`);
      const textInput = document.querySelector(`input[name="${textKey}"]`);
      const preview   = document.getElementById(previewId);
      if (!bgInput || !textInput || !preview) return;
      preview.style.background = bgInput.value;
      preview.style.color      = textInput.value;
    });
  }
  document.addEventListener('input', e => { if (e.target.type === 'color') applyPreview(); });
  applyPreview();
})();
</script>

<?php include __DIR__ . '/_media-picker.php'; ?>

    </main>
  </div>
</body>
</html>
