<?php
// ============================================================
// config-index.php — Configurar contenido del landing publico
// Tabs: Hero Slider | Quien es | Plan de Accion | Redes Sociales |
//       Contacto/Local de Campaña | Header/Footer
// ============================================================
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/config.php';

require_login();
require_modulo($pdo, 'config_index');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$config = [];
try {
    $config = $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

function cix_val(array $config, string $key, string $default = ''): string {
    return htmlspecialchars(cfg_value($config, $key, $default), ENT_QUOTES);
}

$flash = '';
$flash_type = 'ok';
$active_tab = $_GET['tab'] ?? 'hero';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = $_POST['tab'] ?? 'hero';

    // ── Hero Slides: agregar ──────────────────────────────────
    if ($active_tab === 'hero' && ($_POST['slide_action'] ?? '') === 'add') {
        $imagen = trim($_POST['imagen'] ?? '');
        if ($imagen === '') {
            $flash = 'La imagen es obligatoria.'; $flash_type = 'error';
        } else {
            $max_orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0) FROM hero_slides")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO hero_slides (imagen, titulo, subtitulo, boton_texto, boton_url, orden, activo) VALUES (?,?,?,?,?,?,1)");
            $stmt->execute([
                $imagen,
                trim($_POST['titulo'] ?? '') ?: null,
                trim($_POST['subtitulo'] ?? '') ?: null,
                trim($_POST['boton_texto'] ?? '') ?: null,
                trim($_POST['boton_url'] ?? '') ?: null,
                $max_orden + 1,
            ]);
            log_activity($pdo, 'Agrego un slide al hero', 'config_index');
            $flash = 'Slide agregado correctamente.';
        }
    }

    // ── Hero Slides: eliminar ─────────────────────────────────
    if ($active_tab === 'hero' && ($_POST['slide_action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hero_slides WHERE id = ?")->execute([$id]);
        log_activity($pdo, 'Elimino un slide del hero', 'config_index');
        $flash = 'Slide eliminado.';
    }

    // ── Hero Slides: alternar activo ──────────────────────────
    if ($active_tab === 'hero' && ($_POST['slide_action'] ?? '') === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE hero_slides SET activo = 1 - activo WHERE id = ?")->execute([$id]);
        $flash = 'Estado del slide actualizado.';
    }

    // ── Quien es ───────────────────────────────────────────────
    if ($active_tab === 'bio') {
        $values = [
            'index_bio_eyebrow'     => trim($_POST['index_bio_eyebrow'] ?? ''),
            'index_bio_title'       => trim($_POST['index_bio_title'] ?? ''),
            'index_bio_p1'          => trim($_POST['index_bio_p1'] ?? ''),
            'index_bio_p2'          => trim($_POST['index_bio_p2'] ?? ''),
            'index_bio_img'         => trim($_POST['index_bio_img'] ?? ''),
            'index_bio_button_text' => trim($_POST['index_bio_button_text'] ?? ''),
            'index_bio_button_url'  => trim($_POST['index_bio_button_url'] ?? ''),
        ];
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo la seccion Quien es', 'config_index');
            $flash = 'Seccion "Quien es" actualizada correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }

    // ── Plan de Accion ───────────────────────────────────────────
    if ($active_tab === 'plan') {
        $axes_in = cfg_json($config, 'index_work_axes', cfg_default_work_axes());
        $titles  = $_POST['axis_title'] ?? [];
        $descs   = $_POST['axis_desc']  ?? [];
        $actives = $_POST['axis_active'] ?? [];
        foreach ($axes_in as $i => &$axis) {
            $id = $axis['id'] ?? (string)$i;
            if (isset($titles[$id])) $axis['title'] = trim($titles[$id]);
            if (isset($descs[$id]))  $axis['desc']  = trim($descs[$id]);
            $axis['activo'] = isset($actives[$id]);
        }
        unset($axis);
        try {
            cfg_save_values($pdo, ['index_work_axes' => json_encode($axes_in, JSON_UNESCAPED_UNICODE)]);
            $config['index_work_axes'] = json_encode($axes_in, JSON_UNESCAPED_UNICODE);
            log_activity($pdo, 'Actualizo el Plan de Accion', 'config_index');
            $flash = 'Plan de Accion actualizado correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }

    // ── Redes Sociales ────────────────────────────────────────
    if ($active_tab === 'social') {
        $values = [
            'index_social_enabled'      => isset($_POST['index_social_enabled']) ? '1' : '0',
            'index_social_facebook_url' => trim($_POST['index_social_facebook_url'] ?? ''),
        ];
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo Redes Sociales', 'config_index');
            $flash = 'Redes sociales actualizadas correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }

    // ── Noticias (texto vacio) ────────────────────────────────
    if ($active_tab === 'noticias') {
        $values = ['index_news_empty_text' => trim($_POST['index_news_empty_text'] ?? '')];
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo textos de Noticias', 'config_index');
            $flash = 'Texto actualizado correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }

    // ── Contacto / Local de Campaña ───────────────────────────
    if ($active_tab === 'contacto') {
        $values = [
            'index_contact_title'         => trim($_POST['index_contact_title'] ?? ''),
            'index_contact_address_line1' => trim($_POST['index_contact_address_line1'] ?? ''),
            'index_contact_hours_line1'   => trim($_POST['index_contact_hours_line1'] ?? ''),
            'index_contact_phone_text'    => trim($_POST['index_contact_phone_text'] ?? ''),
            'index_contact_phone_href'    => trim($_POST['index_contact_phone_href'] ?? ''),
            'index_contact_map_iframe'    => trim($_POST['index_contact_map_iframe'] ?? ''),
        ];
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo Contacto / Local de Campaña', 'config_index');
            $flash = 'Contacto actualizado correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }

    // ── Header / Footer ───────────────────────────────────────
    if ($active_tab === 'header_footer') {
        $values = [
            'site_header_logo'      => trim($_POST['site_header_logo'] ?? ''),
            'site_header_signature' => trim($_POST['site_header_signature'] ?? ''),
            'site_header_cta_text'  => trim($_POST['site_header_cta_text'] ?? ''),
            'site_header_cta_url'   => trim($_POST['site_header_cta_url'] ?? ''),
            'site_footer_logo'      => trim($_POST['site_footer_logo'] ?? ''),
            'site_footer_name'      => trim($_POST['site_footer_name'] ?? ''),
            'site_footer_party'     => trim($_POST['site_footer_party'] ?? ''),
            'site_footer_slogan'    => trim($_POST['site_footer_slogan'] ?? ''),
            'site_footer_address'   => trim($_POST['site_footer_address'] ?? ''),
            'site_footer_email'     => trim($_POST['site_footer_email'] ?? ''),
            'site_footer_whatsapp_number'  => trim($_POST['site_footer_whatsapp_number'] ?? ''),
            'site_footer_whatsapp_message' => trim($_POST['site_footer_whatsapp_message'] ?? ''),
            'site_footer_whatsapp_text'    => trim($_POST['site_footer_whatsapp_text'] ?? ''),
            'site_footer_facebook_url'  => trim($_POST['site_footer_facebook_url'] ?? ''),
            'site_footer_instagram_url' => trim($_POST['site_footer_instagram_url'] ?? ''),
            'site_footer_youtube_url'   => trim($_POST['site_footer_youtube_url'] ?? ''),
            'site_footer_copyright'     => trim($_POST['site_footer_copyright'] ?? ''),
            'site_footer_bottom_left'   => trim($_POST['site_footer_bottom_left'] ?? ''),
            'site_footer_bottom_right'  => trim($_POST['site_footer_bottom_right'] ?? ''),
        ];
        try {
            cfg_save_values($pdo, $values);
            $config = array_merge($config, $values);
            log_activity($pdo, 'Actualizo Header/Footer del sitio', 'config_index');
            $flash = 'Header y footer actualizados correctamente.';
        } catch (Exception $e) {
            $flash = 'Error al guardar: ' . $e->getMessage(); $flash_type = 'error';
        }
    }
}

if (isset($_GET['ok'])) $flash = 'Cambios guardados correctamente.';
$active_tab = $_GET['tab'] ?? $active_tab;

$hero_slides = [];
try { $hero_slides = $pdo->query("SELECT * FROM hero_slides ORDER BY orden ASC, id ASC")->fetchAll(); } catch (Exception $e) {}

$work_axes = cfg_json($config, 'index_work_axes', cfg_default_work_axes());

$page_title = 'Configurar Index';
require __DIR__ . '/layout.php';
?>

<style>
  [x-cloak] { display: none !important; }
  .cix-tab {
    padding: 0.5rem 1.1rem; font-size: 0.8rem; font-weight: 700; border-radius: 0.6rem;
    border: 2px solid transparent; color: #64748b; background: transparent; cursor: pointer; white-space: nowrap;
  }
  .cix-tab:hover { background: #e2e8f0; color: #1e293b; }
  .cix-tab.is-active { background: #049CD4; color: #fff; border-color: #049CD4; }
</style>

<div class="max-w-4xl mx-auto space-y-6" x-data="{ activeTab: '<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>' }">

  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-black text-gray-900">Configurar Index</h1>
      <p class="text-sm text-gray-400 mt-0.5">Contenido del landing page público.</p>
    </div>
    <a href="<?= BASE_URL ?>/index.php" target="_blank" class="inline-flex items-center gap-2 text-xs font-bold text-gray-500 hover:text-[#049CD4] border border-gray-200 rounded-xl px-4 py-2 bg-white transition-colors">Ver sitio</a>
  </div>

  <?php if ($flash): ?>
  <div class="px-4 py-3 rounded-xl text-sm font-semibold <?= $flash_type === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-1.5 flex flex-wrap gap-1">
    <?php foreach ([
      'hero'          => 'Hero Slider',
      'bio'           => 'Quien es',
      'plan'          => 'Plan de Accion',
      'social'        => 'Redes Sociales',
      'noticias'      => 'Noticias',
      'contacto'      => 'Local de Campaña',
      'header_footer' => 'Header / Footer',
    ] as $tid => $tlabel): ?>
    <button type="button" @click="activeTab = '<?= $tid ?>'" :class="activeTab === '<?= $tid ?>' ? 'is-active' : ''" class="cix-tab"><?= $tlabel ?></button>
    <?php endforeach; ?>
  </div>

  <!-- TAB: HERO SLIDER -->
  <div x-show="activeTab === 'hero'" x-cloak class="space-y-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Slides del Hero</h2>
        <p class="text-white/80 text-xs mt-0.5">Imágenes recomendadas: 1800×650 px. El slider rota automáticamente.</p>
      </div>
      <div class="p-6 space-y-4">
        <?php foreach ($hero_slides as $slide): ?>
        <div class="flex items-center gap-4 p-3 rounded-xl border border-gray-100 <?= $slide['activo'] ? 'bg-white' : 'bg-gray-50 opacity-60' ?>">
          <img src="<?= htmlspecialchars($slide['imagen']) ?>" class="w-24 h-9 object-cover rounded-lg border border-gray-200 flex-shrink-0" alt="">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-black text-gray-800 truncate"><?= htmlspecialchars($slide['titulo'] ?: 'Sin título') ?></p>
            <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($slide['subtitulo'] ?: '') ?></p>
          </div>
          <form method="POST" class="flex-shrink-0">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="hero">
            <input type="hidden" name="slide_action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$slide['id'] ?>">
            <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-lg <?= $slide['activo'] ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' ?>">
              <?= $slide['activo'] ? 'Activo' : 'Inactivo' ?>
            </button>
          </form>
          <form method="POST" class="flex-shrink-0" onsubmit="return confirm('¿Eliminar este slide?')">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="hero">
            <input type="hidden" name="slide_action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$slide['id'] ?>">
            <button type="submit" class="text-red-500 hover:underline text-xs font-bold">Eliminar</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (empty($hero_slides)): ?>
        <p class="text-sm text-gray-400 text-center py-6">Todavía no hay slides. Agrega el primero abajo.</p>
        <?php endif; ?>
      </div>
    </div>

    <form method="POST" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden" x-data="{ url: '' }">
      <div class="bg-gray-50 px-6 py-4 border-b border-gray-100">
        <h3 class="font-black text-gray-800 text-sm">Agregar nuevo slide</h3>
      </div>
      <div class="p-6 space-y-4">
        <input type="hidden" name="tab" value="hero">
        <input type="hidden" name="slide_action" value="add">
        <?= csrf_field() ?>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Imagen (1800x650)</label>
          <div class="flex gap-2">
            <input type="text" name="imagen" x-model="url" required placeholder="https://... o /uploads/media/..."
                   class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-300">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')"
                    class="px-4 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 hover:bg-indigo-100 transition-colors flex-shrink-0">Media</button>
          </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Título</label>
            <input type="text" name="titulo" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Subtítulo</label>
            <input type="text" name="subtitulo" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto del botón</label>
            <input type="text" name="boton_texto" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">URL del botón</label>
            <input type="text" name="boton_url" placeholder="#unete" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-6 py-3 rounded-xl text-sm shadow">Agregar slide</button>
      </div>
    </form>
  </div>

  <!-- TAB: QUIEN ES -->
  <form method="POST" x-show="activeTab === 'bio'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="bio">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Seccion "Quien es"</h2>
      </div>
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Eyebrow (texto pequeño superior)</label>
          <input type="text" name="index_bio_eyebrow" value="<?= cix_val($config, 'index_bio_eyebrow', 'Conoce a nuestro candidato') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Título (usa | para salto de línea)</label>
          <input type="text" name="index_bio_title" value="<?= cix_val($config, 'index_bio_title', 'Quien es|nuestro candidato') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Párrafo 1</label>
          <textarea name="index_bio_p1" rows="3" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm resize-none"><?= cix_val($config, 'index_bio_p1') ?></textarea>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Párrafo 2 (opcional)</label>
          <textarea name="index_bio_p2" rows="3" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm resize-none"><?= cix_val($config, 'index_bio_p2') ?></textarea>
        </div>
        <div x-data="{ url: '<?= cix_val($config, 'index_bio_img') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Foto del candidato</label>
          <div class="flex gap-2">
            <input type="text" name="index_bio_img" x-model="url" class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')" class="px-4 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 flex-shrink-0">Media</button>
          </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto del botón</label>
            <input type="text" name="index_bio_button_text" value="<?= cix_val($config, 'index_bio_button_text', 'Ver biografia completa') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">URL del botón</label>
            <input type="text" name="index_bio_button_url" value="<?= cix_val($config, 'index_bio_button_url', '#') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

  <!-- TAB: PLAN DE ACCION -->
  <form method="POST" x-show="activeTab === 'plan'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="plan">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Nuestro Plan de Accion</h2>
        <p class="text-white/80 text-xs mt-0.5">Activa/desactiva y edita el título y descripción de cada eje.</p>
      </div>
      <div class="p-6 space-y-4">
        <?php foreach ($work_axes as $axis): $aid = $axis['id'] ?? ''; ?>
        <div class="p-4 rounded-xl border border-gray-100 bg-gray-50 space-y-3">
          <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm font-black text-gray-700">
              <input type="checkbox" name="axis_active[<?= htmlspecialchars($aid) ?>]" value="1" <?= ($axis['activo'] ?? true) ? 'checked' : '' ?>>
              <?= htmlspecialchars($axis['label'] ?? $aid) ?>
            </label>
          </div>
          <input type="text" name="axis_title[<?= htmlspecialchars($aid) ?>]" value="<?= htmlspecialchars($axis['title'] ?? '') ?>"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm" placeholder="Título">
          <textarea name="axis_desc[<?= htmlspecialchars($aid) ?>]" rows="2" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm resize-none" placeholder="Descripción"><?= htmlspecialchars($axis['desc'] ?? '') ?></textarea>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

  <!-- TAB: REDES SOCIALES -->
  <form method="POST" x-show="activeTab === 'social'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="social">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Redes Sociales</h2>
        <p class="text-white/80 text-xs mt-0.5">Embed oficial de la fanpage de Facebook.</p>
      </div>
      <div class="p-6 space-y-4">
        <div class="flex items-center justify-between p-4 rounded-xl border border-gray-100 bg-gray-50">
          <p class="text-sm font-black text-gray-800">Mostrar seccion de redes sociales</p>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" name="index_social_enabled" value="1" class="sr-only peer" <?= cfg_value($config, 'index_social_enabled', '1') === '1' ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:bg-[#049CD4] transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
          </label>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">URL de la fanpage de Facebook</label>
          <input type="text" name="index_social_facebook_url" value="<?= cix_val($config, 'index_social_facebook_url') ?>"
                 placeholder="https://www.facebook.com/tu-pagina" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

  <!-- TAB: NOTICIAS -->
  <form method="POST" x-show="activeTab === 'noticias'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="noticias">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Noticias</h2>
        <p class="text-white/80 text-xs mt-0.5">El contenido se administra desde el modulo Noticias del sidebar.</p>
      </div>
      <div class="p-6">
        <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto cuando no hay noticias publicadas</label>
        <textarea name="index_news_empty_text" rows="2" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm resize-none"><?= cix_val($config, 'index_news_empty_text', 'Muy pronto compartiremos las ultimas noticias de la campana.') ?></textarea>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

  <!-- TAB: CONTACTO -->
  <form method="POST" x-show="activeTab === 'contacto'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="contacto">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Local de Campaña</h2>
      </div>
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Título (usa | para salto de línea)</label>
          <input type="text" name="index_contact_title" value="<?= cix_val($config, 'index_contact_title', 'Nuestro Local|de Campaña') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Dirección</label>
          <input type="text" name="index_contact_address_line1" value="<?= cix_val($config, 'index_contact_address_line1') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Horario</label>
          <input type="text" name="index_contact_hours_line1" value="<?= cix_val($config, 'index_contact_hours_line1') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Teléfono (texto visible)</label>
            <input type="text" name="index_contact_phone_text" value="<?= cix_val($config, 'index_contact_phone_text') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Teléfono (enlace tel:)</label>
            <input type="text" name="index_contact_phone_href" value="<?= cix_val($config, 'index_contact_phone_href') ?>" placeholder="tel:+51999999999" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Mapa (URL de embed de Google Maps)</label>
          <textarea name="index_contact_map_iframe" rows="2" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm resize-none font-mono"><?= cix_val($config, 'index_contact_map_iframe') ?></textarea>
          <p class="text-xs text-gray-400 mt-1">Pega la URL "src" del iframe que te da Google Maps al compartir/insertar el mapa.</p>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

  <!-- TAB: HEADER / FOOTER -->
  <form method="POST" x-show="activeTab === 'header_footer'" x-cloak class="space-y-6">
    <input type="hidden" name="tab" value="header_footer">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#049CD4] to-[#028FB7] px-6 py-4">
        <h2 class="text-white font-black text-base">Header</h2>
      </div>
      <div class="p-6 space-y-4">
        <div x-data="{ url: '<?= cix_val($config, 'site_header_logo', '/assets/img/logos/logorp.webp') ?>' }">
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Logo del header</label>
          <div class="flex gap-2">
            <input type="text" name="site_header_logo" x-model="url" class="min-w-0 flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono">
            <button type="button" @click="openMediaPicker((picked) => { url = picked }, 'image')" class="px-4 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 flex-shrink-0">Media</button>
          </div>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Nombre / firma (junto al logo)</label>
          <input type="text" name="site_header_signature" value="<?= cix_val($config, 'site_header_signature') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto botón CTA</label>
            <input type="text" name="site_header_cta_text" value="<?= cix_val($config, 'site_header_cta_text', 'Unete al equipo') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">URL botón CTA</label>
            <input type="text" name="site_header_cta_url" value="<?= cix_val($config, 'site_header_cta_url', '#unete') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="bg-gradient-to-r from-[#023A63] to-[#049CD4] px-6 py-4">
        <h2 class="text-white font-black text-base">Footer</h2>
      </div>
      <div class="p-6 space-y-4">
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Nombre en footer</label>
            <input type="text" name="site_footer_name" value="<?= cix_val($config, 'site_footer_name') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Partido</label>
            <input type="text" name="site_footer_party" value="<?= cix_val($config, 'site_footer_party', 'RENOVACION POPULAR') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Eslogan</label>
          <input type="text" name="site_footer_slogan" value="<?= cix_val($config, 'site_footer_slogan') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Dirección</label>
            <input type="text" name="site_footer_address" value="<?= cix_val($config, 'site_footer_address') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Correo</label>
            <input type="text" name="site_footer_email" value="<?= cix_val($config, 'site_footer_email') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">WhatsApp (número)</label>
            <input type="text" name="site_footer_whatsapp_number" value="<?= cix_val($config, 'site_footer_whatsapp_number') ?>" placeholder="51999999999" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Mensaje predefinido</label>
            <input type="text" name="site_footer_whatsapp_message" value="<?= cix_val($config, 'site_footer_whatsapp_message') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto del botón</label>
            <input type="text" name="site_footer_whatsapp_text" value="<?= cix_val($config, 'site_footer_whatsapp_text', 'WhatsApp') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Facebook URL</label>
            <input type="text" name="site_footer_facebook_url" value="<?= cix_val($config, 'site_footer_facebook_url') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Instagram URL</label>
            <input type="text" name="site_footer_instagram_url" value="<?= cix_val($config, 'site_footer_instagram_url') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">YouTube URL</label>
            <input type="text" name="site_footer_youtube_url" value="<?= cix_val($config, 'site_footer_youtube_url') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Copyright</label>
            <input type="text" name="site_footer_copyright" value="<?= cix_val($config, 'site_footer_copyright', 'Todos los derechos reservados.') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Texto inferior derecho</label>
            <input type="text" name="site_footer_bottom_right" value="<?= cix_val($config, 'site_footer_bottom_right', 'Todos los derechos reservados.') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
          </div>
        </div>
      </div>
    </div>
    <div class="sticky bottom-0 bg-[#F1F5F9]/95 backdrop-blur py-4 flex justify-end gap-3">
      <button type="submit" class="bg-[#049CD4] hover:bg-[#028FB7] text-white font-black px-8 py-3 rounded-xl text-sm shadow">Guardar</button>
    </div>
  </form>

</div>

<?php include __DIR__ . '/_media-picker.php'; ?>

    </main>
  </div>
</body>
</html>
