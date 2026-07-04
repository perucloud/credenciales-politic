<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config/db.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/helpers/config.php';

$cfg_camp = [];
try {
    $cfg_camp = $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

$brand_name = cfg_value($cfg_camp, 'site_brand_name', 'Credenciales App');
$party_name = cfg_value($cfg_camp, 'partido_nombre', 'Renovacion Popular');

// ── Hero slider ──────────────────────────────────────────────
$hero_slides = [];
try {
    $hero_slides = $pdo->query("SELECT * FROM hero_slides WHERE activo = 1 ORDER BY orden ASC, id ASC")->fetchAll();
} catch (Exception $e) {}

// ── Quien es ─────────────────────────────────────────────────
$bio_eyebrow = cfg_value($cfg_camp, 'index_bio_eyebrow', 'Conoce a nuestro candidato');
$bio_title   = cfg_value($cfg_camp, 'index_bio_title', "Quien es|nuestro candidato");
$bio_p1      = cfg_value($cfg_camp, 'index_bio_p1', 'Comprometido con el desarrollo de Satipo, con experiencia de gestion y cercania con la gente.');
$bio_p2      = cfg_value($cfg_camp, 'index_bio_p2', '');
$bio_img     = cfg_value($cfg_camp, 'index_bio_img', '');
$bio_button_text = cfg_value($cfg_camp, 'index_bio_button_text', 'Ver biografia completa');
$bio_button_url  = cfg_value($cfg_camp, 'index_bio_button_url', '#');

// ── Plan de accion (ejes de trabajo) ────────────────────────────
$work_axes = cfg_json($cfg_camp, 'index_work_axes', cfg_default_work_axes());
$work_axes = array_values(array_filter($work_axes, fn($a) => ($a['activo'] ?? true)));
$work_axes = array_slice($work_axes, 0, 6);

// ── Redes sociales ───────────────────────────────────────────
$social_enabled = cfg_value($cfg_camp, 'index_social_enabled', '1') === '1';
$social_fb_url  = cfg_value($cfg_camp, 'index_social_facebook_url', '');

// ── Noticias ─────────────────────────────────────────────────
$noticias = [];
try {
    $noticias = $pdo->query("SELECT id, titulo, imagen, categoria, fecha FROM noticias WHERE estado='publicado' ORDER BY fecha DESC LIMIT 6")->fetchAll();
} catch (Exception $e) {}
$news_empty_text = cfg_value($cfg_camp, 'index_news_empty_text', 'Muy pronto compartiremos las ultimas noticias de la campana.');

// ── Contacto / Local de Campaña ──────────────────────────────
$contact_title_raw = cfg_value($cfg_camp, 'index_contact_title', "Nuestro Local|de Campaña");
$contact_title_lines = explode('|', $contact_title_raw);
$contact_address = cfg_value($cfg_camp, 'index_contact_address_line1', 'Jr. Los Proceres 123, Satipo');
$contact_hours   = cfg_value($cfg_camp, 'index_contact_hours_line1', 'Lunes a sabado, 9:00am - 6:00pm');
$contact_phone_text = cfg_value($cfg_camp, 'index_contact_phone_text', '+51 999 999 999');
$contact_phone_href = cfg_value($cfg_camp, 'index_contact_phone_href', 'tel:+51999999999');
$contact_map_iframe = cfg_value($cfg_camp, 'index_contact_map_iframe', '');

$distritos_lista = ['Satipo', 'Rio Negro', 'Pangoa', 'Rio Tambo', 'Coviriali', 'Llaylla', 'Vizcatan del Ene', 'Pampa Hermosa', 'Mazamari'];
$formas_apoyo_opciones = ['Volanteo', 'Redes sociales', 'Movilizaciones', 'Pintado', 'Coordinacion en mi Zona'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($brand_name) ?></title>
  <?php $fv = cfg_value($cfg_camp, 'site_favicon', ''); $fv_url = ($fv !== '' ? ((str_starts_with($fv,'/') ? BASE_URL : '') . $fv) : ''); ?>
  <?php if ($fv_url !== ''): ?><link rel="icon" href="<?= htmlspecialchars($fv_url) ?>"><?php endif; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; }
    [x-cloak] { display: none !important; }
    .hero-swiper .swiper-pagination-bullet { background: #fff; opacity: .5; }
    .hero-swiper .swiper-pagination-bullet-active { opacity: 1; background: #fff; }
  </style>
</head>
<body class="bg-white text-gray-800">

  <?php require __DIR__ . '/includes/navbar.php'; ?>

  <!-- ══ HERO SLIDER ══════════════════════════════════════════ -->
  <section class="relative">
    <?php if ($hero_slides): ?>
    <div class="swiper hero-swiper">
      <div class="swiper-wrapper">
        <?php foreach ($hero_slides as $slide): ?>
        <div class="swiper-slide relative">
          <div class="relative aspect-[1800/650] w-full overflow-hidden">
            <img src="<?= htmlspecialchars($slide['imagen']) ?>" alt="<?= htmlspecialchars($slide['titulo'] ?? '') ?>"
                 class="absolute inset-0 w-full h-full object-cover">
            <div class="absolute inset-0" style="background: linear-gradient(90deg, rgba(2,58,99,.85) 0%, rgba(2,58,99,.35) 60%, transparent 100%);"></div>
            <div class="relative z-10 h-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center">
              <div class="max-w-xl text-white">
                <?php if (!empty($slide['titulo'])): ?><h1 class="text-3xl sm:text-5xl font-black leading-tight mb-3"><?= htmlspecialchars($slide['titulo']) ?></h1><?php endif; ?>
                <?php if (!empty($slide['subtitulo'])): ?><p class="text-white/85 text-lg mb-6"><?= htmlspecialchars($slide['subtitulo']) ?></p><?php endif; ?>
                <?php if (!empty($slide['boton_texto'])): ?>
                <a href="<?= htmlspecialchars($slide['boton_url'] ?: '#') ?>" class="inline-block rounded-full bg-white text-[#049CD4] px-6 py-3 text-sm font-black shadow-lg hover:-translate-y-0.5 transition-transform">
                  <?= htmlspecialchars($slide['boton_texto']) ?>
                </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="swiper-pagination"></div>
    </div>
    <script>
      new Swiper('.hero-swiper', {
        loop: true,
        autoplay: { delay: 6000, disableOnInteraction: false },
        pagination: { el: '.swiper-pagination', clickable: true },
        effect: 'fade',
        fadeEffect: { crossFade: true },
      });
    </script>
    <?php else: ?>
    <div class="aspect-[1800/650] w-full flex items-center justify-center" style="background: linear-gradient(135deg, #049CD4 0%, #028FB7 100%);">
      <span class="text-white/70 text-sm font-semibold">Configura las imagenes del hero desde el dashboard</span>
    </div>
    <?php endif; ?>
  </section>

  <!-- ══ QUIEN ES ═════════════════════════════════════════════ -->
  <section id="quien-es" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
    <div class="order-2 lg:order-1">
      <p class="text-xs font-black uppercase tracking-widest text-[#049CD4] mb-3"><?= htmlspecialchars($bio_eyebrow) ?></p>
      <h2 class="text-3xl sm:text-4xl font-black text-gray-800 leading-tight mb-5">
        <?php foreach (explode('|', $bio_title) as $line): ?><?= htmlspecialchars($line) ?><br><?php endforeach; ?>
      </h2>
      <p class="text-gray-600 mb-4 leading-relaxed"><?= nl2br(htmlspecialchars($bio_p1)) ?></p>
      <?php if ($bio_p2 !== ''): ?><p class="text-gray-600 mb-6 leading-relaxed"><?= nl2br(htmlspecialchars($bio_p2)) ?></p><?php endif; ?>
      <a href="<?= htmlspecialchars($bio_button_url) ?>" class="inline-flex items-center gap-2 rounded-full px-6 py-3 text-sm font-black text-white shadow-lg" style="background:#049CD4">
        <?= htmlspecialchars($bio_button_text) ?>
      </a>
    </div>
    <div class="order-1 lg:order-2">
      <?php if ($bio_img !== ''): ?>
        <img src="<?= htmlspecialchars((str_starts_with($bio_img, '/') ? BASE_URL : '') . $bio_img) ?>" alt="<?= htmlspecialchars($brand_name) ?>"
             class="w-full rounded-3xl shadow-xl object-cover aspect-[4/5]" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="w-full aspect-[4/5] rounded-3xl bg-gray-100 border border-gray-200 flex items-center justify-center">
          <span class="text-gray-400 text-sm font-semibold">Foto del candidato</span>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ PLAN DE ACCION ═══════════════════════════════════════ -->
  <?php if ($work_axes): ?>
  <section id="plan" class="bg-[#F0FAFF] py-16 sm:py-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-12">
        <p class="text-xs font-black uppercase tracking-widest text-[#049CD4] mb-3">Propuestas</p>
        <h2 class="text-3xl sm:text-4xl font-black text-gray-800">Nuestro Plan de Accion</h2>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($work_axes as $eje): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-lg transition-shadow">
          <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:#049CD4">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="<?= htmlspecialchars(cfg_axis_icon_path($eje['icon'] ?? '')) ?>"/>
            </svg>
          </div>
          <h3 class="font-black text-gray-800 mb-2"><?= htmlspecialchars($eje['title'] ?? $eje['label'] ?? '') ?></h3>
          <p class="text-sm text-gray-500 leading-relaxed"><?= htmlspecialchars($eje['desc'] ?? '') ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ══ REDES SOCIALES ═══════════════════════════════════════ -->
  <?php if ($social_enabled && $social_fb_url !== ''): ?>
  <section id="redes-sociales" class="max-w-4xl mx-auto px-4 sm:px-6 py-16 sm:py-24 text-center">
    <p class="text-xs font-black uppercase tracking-widest text-[#049CD4] mb-3">Sigenos</p>
    <h2 class="text-3xl sm:text-4xl font-black text-gray-800 mb-8">Redes Sociales</h2>
    <div class="flex justify-center overflow-hidden rounded-2xl border border-gray-100 shadow-sm">
      <iframe src="https://www.facebook.com/plugins/page.php?href=<?= urlencode($social_fb_url) ?>&tabs=timeline&width=500&height=550&small_header=false&adapt_container_width=true&hide_cover=false&show_facepile=true"
              width="500" height="550" style="border:none;overflow:hidden;max-width:100%" scrolling="no" frameborder="0"
              allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" loading="lazy"></iframe>
    </div>
  </section>
  <?php endif; ?>

  <!-- ══ NOTICIAS ═════════════════════════════════════════════ -->
  <section id="noticias" class="bg-gray-50 py-16 sm:py-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-2xl mx-auto mb-12">
        <p class="text-xs font-black uppercase tracking-widest text-[#049CD4] mb-3">Actualidad</p>
        <h2 class="text-3xl sm:text-4xl font-black text-gray-800">Ultimas Noticias</h2>
      </div>
      <?php if ($noticias): ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($noticias as $n): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div class="aspect-[16/9] bg-gray-100">
            <?php if (!empty($n['imagen'])): ?>
              <img src="<?= htmlspecialchars(BASE_URL . '/assets/img/noticias/' . $n['imagen']) ?>" alt="" class="w-full h-full object-cover">
            <?php endif; ?>
          </div>
          <div class="p-5">
            <span class="text-[10px] font-black uppercase tracking-wide text-[#049CD4]"><?= htmlspecialchars($n['categoria'] ?: 'General') ?></span>
            <h3 class="font-black text-gray-800 mt-1 mb-2 leading-snug"><?= htmlspecialchars($n['titulo']) ?></h3>
            <p class="text-xs text-gray-400"><?= htmlspecialchars(date('d/m/Y', strtotime($n['fecha']))) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-center text-gray-400 text-sm"><?= htmlspecialchars($news_empty_text) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ SE PARTE DEL CAMBIO ══════════════════════════════════ -->
  <section id="unete" x-data="joinFlow()" class="max-w-3xl mx-auto px-4 sm:px-6 py-16 sm:py-24 text-center">
    <p class="text-xs font-black uppercase tracking-widest text-[#049CD4] mb-3">Se parte del cambio en Satipo</p>
    <h2 class="text-2xl sm:text-3xl font-black text-gray-800 mb-3">Solo ingresa tu DNI y forma parte de este gran proyecto</h2>
    <p class="text-gray-500 mb-8">Suma tu energia, tus ideas y tu compromiso a este equipo.</p>

    <form @submit.prevent="abrirModal()" class="max-w-sm mx-auto flex gap-2">
      <input type="text" x-model="dni" maxlength="8" inputmode="numeric" placeholder="Ingresa tu DNI"
             @input="dni = dni.replace(/\D/g,'').slice(0,8)"
             class="flex-1 rounded-full border-2 border-gray-200 px-5 py-3 text-sm font-bold font-mono outline-none focus:border-[#049CD4] focus:ring-4 focus:ring-[#049CD4]/10">
      <button type="submit" class="rounded-full px-6 py-3 text-sm font-black text-white shadow-lg whitespace-nowrap" style="background:#049CD4">Unirme al Cambio</button>
    </form>
    <p x-show="topError" x-cloak x-text="topError" class="text-red-500 text-xs font-semibold mt-3"></p>

    <!-- Modal registro -->
    <div x-show="modalOpen" x-cloak x-transition class="fixed inset-0 z-[130] flex items-start justify-center overflow-y-auto p-4">
      <div class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm" @click="modalOpen = false"></div>
      <div x-transition class="relative z-10 my-8 w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl text-left">
        <div class="px-6 py-5 text-white" style="background:#049CD4">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.18em] text-white/80">Registro de simpatizante</p>
              <h3 class="mt-1 text-xl font-black">Completa tus datos</h3>
            </div>
            <button type="button" @click="modalOpen = false" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-white/70 hover:bg-white/15 hover:text-white">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <template x-if="!success">
        <form @submit.prevent="enviar()" class="px-6 py-6 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2">
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">DNI</label>
              <input type="text" :value="dni" disabled class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-mono font-bold text-gray-500">
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Nombre completo <span x-show="reniecLoading" class="text-[#049CD4] normal-case font-normal">(buscando en RENIEC...)</span></label>
              <input type="text" x-model="form.nombre" required class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
            </div>
            <div>
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Telefono</label>
              <input type="tel" x-model="form.telefono" required class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
            </div>
            <div>
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Distrito</label>
              <select x-model="form.distrito" required class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
                <option value="">Selecciona...</option>
                <?php foreach ($distritos_lista as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Correo (opcional)</label>
              <input type="email" x-model="form.correo" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
            </div>
            <div>
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Celular (opcional)</label>
              <input type="tel" x-model="form.celular" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">WhatsApp (opcional)</label>
              <input type="tel" x-model="form.whatsapp" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm">
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Formas de apoyo</label>
              <div class="grid grid-cols-2 gap-2">
                <?php foreach ($formas_apoyo_opciones as $opt): ?>
                <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                  <input type="checkbox" value="<?= htmlspecialchars($opt) ?>" x-model="form.formas_apoyo">
                  <?= htmlspecialchars($opt) ?>
                </label>
                <?php endforeach; ?>
              </div>
              <input type="text" x-model="form.otro_apoyo" placeholder="Otro (especifica)"
                     class="w-full rounded-xl border border-gray-200 px-4 py-2 text-xs mt-2">
            </div>
          </div>

          <p x-show="error" x-cloak x-text="error" class="text-red-600 text-sm font-semibold bg-red-50 border border-red-100 rounded-xl px-4 py-3"></p>

          <button type="submit" :disabled="loading"
                  class="w-full rounded-full py-3 text-sm font-black text-white shadow-lg disabled:opacity-60"
                  style="background:#049CD4">
            <span x-text="loading ? 'Enviando...' : 'Finalizar registro'"></span>
          </button>
        </form>
        </template>

        <template x-if="success">
          <div class="px-6 py-10 text-center">
            <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
              <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h3 class="font-black text-gray-800 text-lg mb-1">Registro exitoso</h3>
            <p class="text-gray-500 text-sm">Gracias por sumarte al equipo. Pronto nos pondremos en contacto contigo.</p>
            <button type="button" @click="modalOpen = false" class="mt-6 rounded-full px-6 py-2.5 text-sm font-black text-white" style="background:#049CD4">Cerrar</button>
          </div>
        </template>
      </div>
    </div>
  </section>

  <!-- ══ CONTACTO / LOCAL DE CAMPAÑA ══════════════════════════ -->
  <section id="contacto" class="bg-[#023A63] text-white py-16 sm:py-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">
      <div>
        <p class="text-xs font-black uppercase tracking-widest text-[#67D6F5] mb-3">Visitanos</p>
        <h2 class="text-3xl sm:text-4xl font-black leading-tight mb-6">
          <?php foreach ($contact_title_lines as $line): ?><?= htmlspecialchars($line) ?><br><?php endforeach; ?>
        </h2>
        <div class="space-y-4 text-white/85 text-sm">
          <p class="flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 text-[#67D6F5] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <?= htmlspecialchars($contact_address) ?>
          </p>
          <p class="flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 text-[#67D6F5] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= htmlspecialchars($contact_hours) ?>
          </p>
          <p class="flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 text-[#67D6F5] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <a href="<?= htmlspecialchars($contact_phone_href) ?>" class="hover:text-white"><?= htmlspecialchars($contact_phone_text) ?></a>
          </p>
        </div>
      </div>
      <div class="rounded-2xl overflow-hidden border border-white/10 aspect-video">
        <?php if ($contact_map_iframe !== ''): ?>
          <iframe src="<?= htmlspecialchars($contact_map_iframe) ?>" width="100%" height="100%" style="border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        <?php else: ?>
          <div class="w-full h-full flex items-center justify-center bg-white/5">
            <span class="text-white/40 text-xs font-semibold">Configura el mapa desde el dashboard</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php require __DIR__ . '/includes/footer.php'; ?>

  <script>
    function joinFlow() {
      return {
        dni: '',
        modalOpen: false,
        loading: false,
        reniecLoading: false,
        error: '',
        topError: '',
        success: false,
        form: { nombre: '', telefono: '', distrito: '', correo: '', celular: '', whatsapp: '', formas_apoyo: [], otro_apoyo: '' },

        abrirModal() {
          this.dni = String(this.dni || '').replace(/\D/g, '').slice(0, 8);
          if (!/^\d{8}$/.test(this.dni)) { this.topError = 'Ingresa un DNI valido de 8 digitos.'; return; }
          this.topError = ''; this.error = ''; this.success = false;
          this.form = { nombre: '', telefono: '', distrito: '', correo: '', celular: '', whatsapp: '', formas_apoyo: [], otro_apoyo: '' };
          this.modalOpen = true;
          this.buscarReniec();
        },

        async buscarReniec() {
          this.reniecLoading = true;
          try {
            const res = await fetch('<?= BASE_URL ?>/includes/reniec-lookup.php?dni=' + this.dni);
            const data = await res.json();
            if (data.ok && data.data && data.data.nombre_completo) this.form.nombre = data.data.nombre_completo;
          } catch (e) {}
          finally { this.reniecLoading = false; }
        },

        async enviar() {
          this.error = '';
          if (!this.form.nombre || !this.form.telefono || !this.form.distrito) {
            this.error = 'Completa nombre, telefono y distrito.';
            return;
          }
          this.loading = true;
          const apoyos = [...this.form.formas_apoyo];
          if (this.form.otro_apoyo && this.form.otro_apoyo.trim()) apoyos.push('Otro: ' + this.form.otro_apoyo.trim());

          const fd = new FormData();
          fd.append('dni', this.dni);
          fd.append('nombre', this.form.nombre);
          fd.append('telefono', this.form.telefono);
          fd.append('distrito', this.form.distrito);
          fd.append('correo', this.form.correo || '');
          fd.append('celular', this.form.celular || '');
          fd.append('whatsapp', this.form.whatsapp || '');
          fd.append('formas_apoyo', apoyos.join(', '));
          fd.append('tipo_documento', 'DNI');

          try {
            const res = await fetch('<?= BASE_URL ?>/includes/registrar.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) { this.success = true; }
            else { this.error = data.error || 'No se pudo completar el registro.'; }
          } catch (e) {
            this.error = 'No se pudo conectar con el servidor.';
          } finally {
            this.loading = false;
          }
        }
      };
    }
  </script>

</body>
</html>
