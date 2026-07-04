<?php
require_once __DIR__ . '/includes/config/db.php';
require_once __DIR__ . '/includes/helpers/config.php';

$cfg_camp = cfg_load($pdo);

$brand_name = cfg_value($cfg_camp, 'site_brand_name', 'Credenciales App');
$maint_logo = cfg_value($cfg_camp, 'maint_logo', cfg_value($cfg_camp, 'site_header_logo', '/assets/img/logos/logorp.webp'));
$maint_title = cfg_value($cfg_camp, 'maint_title', 'Sitio en Mantenimiento');
$maint_message = cfg_value($cfg_camp, 'maint_message', 'Estamos trabajando para mejorar tu experiencia. Volvemos pronto.');
$maint_eta = cfg_value($cfg_camp, 'maint_eta', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($maint_title) ?> - <?= htmlspecialchars($brand_name) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style> body { font-family: 'Inter', system-ui, sans-serif; } </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4" style="background: linear-gradient(135deg, #023A63 0%, #049CD4 100%);">
  <div class="max-w-lg w-full text-center text-white">
    <?php if ($maint_logo !== ''): ?>
    <img src="<?= htmlspecialchars((str_starts_with($maint_logo, '/') ? BASE_URL : '') . $maint_logo) ?>" alt="<?= htmlspecialchars($brand_name) ?>"
         class="h-14 mx-auto mb-8" onerror="this.style.display='none'">
    <?php endif; ?>
    <h1 class="text-3xl sm:text-4xl font-black mb-4"><?= htmlspecialchars($maint_title) ?></h1>
    <p class="text-white/80 mb-6"><?= htmlspecialchars($maint_message) ?></p>
    <?php if ($maint_eta !== ''): ?>
    <p class="inline-block bg-white/10 border border-white/20 rounded-full px-5 py-2 text-sm font-bold"><?= htmlspecialchars($maint_eta) ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
