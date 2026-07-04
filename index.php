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

$brand_name  = cfg_value($cfg_camp, 'site_brand_name', 'Credenciales App');
$party_name  = cfg_value($cfg_camp, 'partido_nombre', 'Renovacion Popular');
$hero_title  = cfg_value($cfg_camp, 'index_hero_title', $brand_name);
$hero_quote  = cfg_value($cfg_camp, 'index_hero_quote', 'Juntos por el desarrollo de nuestra provincia.');
$hero_img    = cfg_value($cfg_camp, 'index_hero_img', '');
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; }
    [x-cloak] { display: none !important; }
  </style>
</head>
<body class="bg-white text-gray-800">

  <?php require __DIR__ . '/includes/navbar.php'; ?>

  <section class="relative overflow-hidden" style="background: linear-gradient(135deg, #049CD4 0%, #028FB7 100%);">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
      <div class="text-white">
        <p class="text-xs font-black uppercase tracking-widest text-[#DFF6FF] mb-3"><?= htmlspecialchars($party_name) ?></p>
        <h1 class="text-4xl sm:text-5xl font-black leading-tight mb-4"><?= htmlspecialchars($hero_title) ?></h1>
        <p class="text-white/80 text-lg mb-8"><?= htmlspecialchars($hero_quote) ?></p>
        <div class="flex flex-wrap gap-3">
          <a href="#unete" class="rounded-full bg-white text-[#049CD4] px-6 py-3 text-sm font-black shadow-lg hover:-translate-y-0.5 transition-transform">Unete al equipo</a>
          <a href="<?= BASE_URL ?>/verificar-credencial.php" class="rounded-full border-2 border-white/70 text-white px-6 py-3 text-sm font-black hover:bg-white/10 transition-colors">Verificar credencial</a>
        </div>
      </div>
      <div class="relative">
        <?php if ($hero_img !== ''): ?>
          <img src="<?= htmlspecialchars((str_starts_with($hero_img, '/') ? BASE_URL : '') . $hero_img) ?>" alt="<?= htmlspecialchars($brand_name) ?>"
               class="w-full aspect-[1800/650] object-cover rounded-3xl shadow-2xl" onerror="this.style.display='none'">
        <?php else: ?>
          <div class="w-full aspect-[1800/650] rounded-3xl bg-white/10 border border-white/20 flex items-center justify-center">
            <span class="text-white/50 text-sm font-semibold">Imagen del hero (1800x650)</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="unete" class="max-w-3xl mx-auto px-4 sm:px-6 py-16 text-center">
    <h2 class="text-2xl sm:text-3xl font-black text-gray-800 mb-3">Solo ingresa tu DNI y forma parte de este gran proyecto</h2>
    <p class="text-gray-500 mb-8">Suma tu energia, tus ideas y tu compromiso a este equipo.</p>
    <div class="max-w-sm mx-auto flex gap-2">
      <input type="text" maxlength="8" inputmode="numeric" placeholder="Ingresa tu DNI"
             class="flex-1 rounded-full border-2 border-gray-200 px-5 py-3 text-sm font-bold font-mono outline-none focus:border-[#049CD4] focus:ring-4 focus:ring-[#049CD4]/10">
      <button type="button" class="rounded-full px-6 py-3 text-sm font-black text-white shadow-lg" style="background:#049CD4">Unirme al Cambio</button>
    </div>
    <p class="text-xs text-gray-400 mt-4">El formulario completo de registro se habilitara en la siguiente fase.</p>
  </section>

  <?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
