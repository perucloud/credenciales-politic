<?php
require_once __DIR__ . '/helpers/config.php';
if (!isset($cfg_camp)) {
    $cfg_camp = cfg_load($pdo);
}

if (!function_exists('footer_whatsapp_url')) {
    function footer_whatsapp_url(array $cfg_camp): string {
        $number = preg_replace('/\D+/', '', cfg_value($cfg_camp, 'site_footer_whatsapp_number', ''));
        if ($number !== '') {
            $message = trim(cfg_value($cfg_camp, 'site_footer_whatsapp_message', ''));
            return 'https://wa.me/' . $number . ($message !== '' ? '?text=' . rawurlencode($message) : '');
        }
        $url = trim(cfg_value($cfg_camp, 'site_footer_whatsapp_url', ''));
        if ($url === '' || $url === '#') return '';
        return $url;
    }
}

$footer_whatsapp_url  = footer_whatsapp_url($cfg_camp);
$footer_whatsapp_text = cfg_value($cfg_camp, 'site_footer_whatsapp_text', 'WhatsApp');
$public_current_page  = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');

$footer_brand_name = cfg_value($cfg_camp, 'site_footer_name', cfg_value($cfg_camp, 'site_brand_name', 'Credenciales App'));
$footer_party       = cfg_value($cfg_camp, 'site_footer_party', 'RENOVACION POPULAR');

$mobile_nav_items = [
    ['label' => 'Inicio',    'url' => '/index.php',                'active' => $public_current_page === 'index.php' || $public_current_page === ''],
    ['label' => 'Plan',      'url' => '/index.php#plan',            'active' => false],
    ['label' => 'Verificar', 'url' => '/verificar-credencial.php',  'active' => $public_current_page === 'verificar-credencial.php',
     'action' => "event.preventDefault(); window.dispatchEvent(new CustomEvent('abrir-verificador-credencial')); if (!window.__credencialVerifierReady) window.location.href='" . BASE_URL . "/verificar-credencial.php';"],
    ['label' => 'Acceso',    'url' => '/admin/login.php',           'active' => $public_current_page === 'login.php'],
];
?>
<footer class="bg-[#023A63] text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-10">

      <div>
        <p class="font-bold text-lg leading-tight"><?= htmlspecialchars($footer_brand_name) ?></p>
        <p class="text-[#67D6F5] text-sm mt-1"><?= htmlspecialchars($footer_party) ?></p>
        <p class="text-gray-300 text-sm mt-2 italic"><?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_slogan', '')) ?></p>
        <p class="text-gray-400 text-xs mt-6">&copy; <?= date('Y') ?> <?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_copyright', 'Todos los derechos reservados.')) ?></p>
      </div>

      <div>
        <h4 class="font-bold text-[#67D6F5] uppercase text-xs tracking-widest mb-4">Navegacion</h4>
        <ul class="space-y-2 text-sm text-gray-300">
          <li><a href="<?= BASE_URL ?>/index.php" class="hover:text-white transition-colors">Inicio</a></li>
          <li><a href="<?= BASE_URL ?>/index.php#quien-es" class="hover:text-white transition-colors">Quien es?</a></li>
          <li><a href="<?= BASE_URL ?>/index.php#plan" class="hover:text-white transition-colors">Nuestro Plan de Accion</a></li>
          <li><a href="<?= BASE_URL ?>/index.php#noticias" class="hover:text-white transition-colors">Noticias</a></li>
          <li><a href="<?= BASE_URL ?>/index.php#contacto" class="hover:text-white transition-colors">Local de Campaña</a></li>
          <li>
            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('abrir-verificador-credencial')); if (!window.__credencialVerifierReady) window.location.href='<?= BASE_URL ?>/verificar-credencial.php';"
                    class="hover:text-white transition-colors text-left">
              Verificar credencial
            </button>
          </li>
        </ul>
        <div class="mt-5 pt-4 border-t border-white/10">
          <a href="<?= BASE_URL ?>/admin/login.php" class="inline-flex items-center gap-2 text-gray-400 hover:text-gray-200 transition-all duration-200">
            <span class="text-xs font-semibold tracking-wide">Intranet</span>
          </a>
        </div>
      </div>

      <div>
        <h4 class="font-bold text-[#67D6F5] uppercase text-xs tracking-widest mb-4">Contacto</h4>
        <ul class="space-y-3 text-sm text-gray-300">
          <li class="flex items-start gap-2">
            <svg class="w-4 h-4 mt-0.5 text-[#67D6F5] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_address', '')) ?>
          </li>
          <li class="flex items-start gap-2">
            <svg class="w-4 h-4 mt-0.5 text-[#67D6F5] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_email', '')) ?>
          </li>
          <?php if ($footer_whatsapp_url !== ''): ?>
          <li>
            <a href="<?= htmlspecialchars($footer_whatsapp_url) ?>" target="_blank" rel="noopener"
               class="flex items-center gap-2 bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-3 py-2 rounded-full transition-colors w-fit">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                <path d="M12.004 2C6.477 2 2 6.477 2 12.004c0 1.773.463 3.486 1.345 4.999L2 22l5.13-1.345A9.953 9.953 0 0012.004 22C17.53 22 22 17.523 22 11.996 22 6.477 17.53 2 12.004 2zm0 18.18a8.144 8.144 0 01-4.158-1.137l-.298-.177-3.046.799.815-2.979-.194-.307A8.154 8.154 0 013.82 12.004c0-4.514 3.672-8.184 8.184-8.184 4.514 0 8.184 3.67 8.184 8.184 0 4.512-3.67 8.176-8.184 8.176z"/>
              </svg>
              <?= htmlspecialchars($footer_whatsapp_text) ?>
            </a>
          </li>
          <?php endif; ?>
        </ul>
        <div class="flex items-center gap-3 mt-5">
          <a href="<?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_facebook_url', '#')) ?>" class="text-gray-400 hover:text-[#67D6F5] transition-colors" aria-label="Facebook">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          </a>
          <a href="<?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_instagram_url', '#')) ?>" class="text-gray-400 hover:text-[#67D6F5] transition-colors" aria-label="Instagram">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
          </a>
          <a href="<?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_youtube_url', '#')) ?>" class="text-gray-400 hover:text-[#67D6F5] transition-colors" aria-label="YouTube">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="border-t border-white/10">
    <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-400">
      <span>&copy; <?= date('Y') ?> <?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_bottom_left', $footer_brand_name)) ?></span>
      <span class="mt-1 sm:mt-0"><?= htmlspecialchars(cfg_value($cfg_camp, 'site_footer_bottom_right', 'Todos los derechos reservados.')) ?></span>
    </div>
  </div>
</footer>

<nav class="sm:hidden fixed left-4 right-4 bottom-3 z-[95]" style="padding-bottom:env(safe-area-inset-bottom)" aria-label="Navegacion movil publica">
  <div class="relative mx-auto max-w-[430px] grid grid-cols-4 gap-1 rounded-full bg-[#023A63] border border-white/20 shadow-2xl p-2">
    <?php foreach ($mobile_nav_items as $item): ?>
    <a href="<?= htmlspecialchars(BASE_URL . $item['url']) ?>"
       class="flex flex-col items-center justify-center gap-0.5 py-2 rounded-full text-[10px] font-bold transition-colors <?= $item['active'] ? 'bg-[#049CD4] text-white' : 'text-white/70' ?>"
       <?= isset($item['action']) ? 'onclick="' . htmlspecialchars($item['action'], ENT_QUOTES) . '"' : '' ?>>
      <?= htmlspecialchars($item['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</nav>

<?php if ($footer_whatsapp_url !== ''): ?>
<a href="<?= htmlspecialchars($footer_whatsapp_url) ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($footer_whatsapp_text) ?>"
   class="fixed right-4 bottom-20 sm:right-6 sm:bottom-6 z-50 group inline-flex items-center gap-3 rounded-full bg-[#25D366] px-4 py-3 text-white shadow-[0_16px_40px_rgba(37,211,102,.35)] transition-all duration-200 hover:-translate-y-1 hover:bg-[#1DB954]">
  <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
    <path d="M12.004 2C6.477 2 2 6.477 2 12.004c0 1.773.463 3.486 1.345 4.999L2 22l5.13-1.345A9.953 9.953 0 0012.004 22C17.53 22 22 17.523 22 11.996 22 6.477 17.53 2 12.004 2zm0 18.18a8.144 8.144 0 01-4.158-1.137l-.298-.177-3.046.799.815-2.979-.194-.307A8.154 8.154 0 013.82 12.004c0-4.514 3.672-8.184 8.184-8.184 4.514 0 8.184 3.67 8.184 8.184 0 4.512-3.67 8.176-8.184 8.176z"/>
  </svg>
</a>
<?php endif; ?>

<?php if ($public_current_page !== 'index.php'): ?>
<script>
  function publicCredentialVerifier() {
    return {
      abierto: false, dni: '', cargando: false, error: '', resultado: null,
      abrir(detail) {
        this.abierto = true; this.error = ''; this.resultado = null;
        this.dni = detail && detail.dni ? String(detail.dni).replace(/\D/g, '').slice(0, 8) : '';
        document.body.style.overflow = 'hidden';
        setTimeout(() => { const input = this.$refs.dniInput; if (input) input.focus(); }, 80);
      },
      cerrar() { this.abierto = false; this.cargando = false; document.body.style.overflow = ''; },
      async verificar() {
        this.error = ''; this.resultado = null;
        this.dni = String(this.dni || '').replace(/\D/g, '').slice(0, 8);
        if (!/^\d{8}$/.test(this.dni)) { this.error = 'Ingresa un DNI valido de 8 digitos.'; return; }
        this.cargando = true;
        try {
          const url = '<?= BASE_URL ?>/verificar-credencial.php?json=1&dni=' + encodeURIComponent(this.dni);
          const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
          const data = await response.json();
          if (!data.ok) { this.error = data.msg || 'No se encontro una credencial asociada a este DNI.'; }
          else { this.resultado = data.data; }
        } catch (e) { this.error = 'No se pudo conectar con el verificador. Intentalo nuevamente.'; }
        finally { this.cargando = false; }
      }
    };
  }
</script>
<div x-data="publicCredentialVerifier()" x-init="window.__credencialVerifierReady = true"
     @abrir-verificador-credencial.window="abrir($event.detail || {})" x-cloak>
  <div x-show="abierto" x-transition class="fixed inset-0 z-[120] flex items-start justify-center overflow-y-auto p-4">
    <div class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm" @click="cerrar()"></div>
    <div x-transition class="relative z-10 my-8 w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
      <div class="bg-[#049CD4] px-6 py-5 text-white">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.18em] text-[#DFF6FF]">Verificacion publica</p>
            <h3 class="mt-1 text-xl font-black leading-tight"><?= htmlspecialchars($footer_party) ?></h3>
            <p class="mt-1 text-xs font-medium text-white/70">Consulta de credencial partidaria</p>
          </div>
          <button type="button" @click="cerrar()" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/15 hover:text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>
      <div class="px-6 py-6">
        <form @submit.prevent="verificar()" class="space-y-4">
          <div>
            <label class="mb-1.5 block text-xs font-black uppercase tracking-wide text-slate-500">DNI del portador</label>
            <input x-model="dni" x-ref="dniInput" type="text" maxlength="8" inputmode="numeric"
                   @input="dni = String(dni).replace(/\D/g, '').slice(0, 8)"
                   placeholder="Ej. 12345678"
                   class="w-full rounded-xl border-2 border-slate-200 px-4 py-3 font-mono text-sm font-bold text-slate-800 outline-none transition focus:border-[#049CD4] focus:ring-4 focus:ring-[#049CD4]/10">
          </div>
          <button type="submit" :disabled="cargando"
                  class="flex w-full items-center justify-center gap-2 rounded-full bg-[#049CD4] px-5 py-3.5 text-sm font-black text-white shadow-lg transition hover:bg-[#028FB7] disabled:cursor-not-allowed disabled:opacity-60">
            <span x-text="cargando ? 'Verificando...' : 'Verificar credencial'"></span>
          </button>
        </form>
        <div x-show="error" x-transition class="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700" x-text="error"></div>
        <div x-show="resultado" x-transition class="mt-5 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
          <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-white px-4 py-3">
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Resultado</p>
              <p class="text-sm font-black text-slate-900">Credencial encontrada</p>
            </div>
            <span class="rounded-full px-3 py-1 text-[11px] font-black" :style="'background:' + resultado.estado_bg + '; color:' + resultado.estado_color" x-text="resultado.estado"></span>
          </div>
          <dl class="grid grid-cols-1 gap-3 px-4 py-4 text-sm">
            <div><dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Apellidos y nombres</dt><dd class="mt-0.5 font-black text-slate-900" x-text="resultado.apellidos_nombres"></dd></div>
            <div class="grid grid-cols-2 gap-3">
              <div><dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Cargo</dt><dd class="mt-0.5 font-bold text-[#049CD4]" x-text="resultado.cargo"></dd></div>
              <div><dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">DNI</dt><dd class="mt-0.5 font-mono font-bold text-slate-800" x-text="resultado.dni"></dd></div>
              <div><dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Caduca</dt><dd class="mt-0.5 font-bold text-slate-800" x-text="resultado.caduca"></dd></div>
              <div><dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Codigo</dt><dd class="mt-0.5 font-mono text-xs font-bold text-slate-500" x-text="resultado.codigo"></dd></div>
            </div>
          </dl>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
