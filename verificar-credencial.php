<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config/db.php';
require_once __DIR__ . '/includes/helpers/config.php';
require_once __DIR__ . '/includes/helpers/credenciales.php';

$cfg_camp = cfg_load($pdo);

if (cfg_value($cfg_camp, 'maintenance_active', '0') === '1') {
    if (isset($_GET['json'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'El sitio se encuentra en mantenimiento.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . BASE_URL . '/maintenance.php');
    exit;
}

$partido_nombre = strtoupper(cfg_value($cfg_camp, 'partido_nombre', 'RENOVACION POPULAR'));
$brand_name = cfg_value($cfg_camp, 'site_brand_name', 'Sistema de Gestión de Credenciales');

// Refresca estado vencido on-the-fly, maximo una vez por hora (igual que en el modulo admin)
$last_venc = $_SESSION['cred_vencidas_ts'] ?? 0;
if ((time() - $last_venc) > 3600) {
    try {
        $pdo->exec("UPDATE credenciales SET estado='vencido' WHERE estado='activo' AND fecha_vencimiento < CURDATE()");
        $_SESSION['cred_vencidas_ts'] = time();
    } catch (Exception $e) {}
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $dni_json = preg_replace('/\D+/', '', (string)($_GET['dni'] ?? $_POST['dni'] ?? ''));
    $token_json = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
    $row = null;

    try {
        if ($token_json !== '') {
            $stmt = $pdo->prepare("SELECT * FROM credenciales WHERE qr_token = ? LIMIT 1");
            $stmt->execute([$token_json]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (preg_match('/^\d{8}$/', $dni_json)) {
            $stmt = $pdo->prepare("SELECT * FROM credenciales WHERE dni = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$dni_json]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Exception $e) {
        $row = null;
    }

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'No se encontró una credencial vigente asociada a los datos ingresados.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => credencial_payload_publico($row, $partido_nombre)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1) Búsqueda por token de QR ────────────────────────────────
$resultado = null;
$buscado   = false;
$error     = '';

$token = trim((string)($_GET['t'] ?? ''));
if ($token !== '') {
    $buscado = true;
    $stmt = $pdo->prepare("SELECT * FROM credenciales WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$resultado) $error = 'No se encontró ninguna credencial asociada a este código QR.';
}

// ── 2) Búsqueda manual por DNI + nombres ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_manual'])) {
    $buscado    = true;
    $dni_in     = trim((string)($_POST['dni'] ?? ''));
    $nombre_in  = trim((string)($_POST['nombres'] ?? ''));

    if ($dni_in === '' || $nombre_in === '') {
        $error = 'Ingresa el DNI y los nombres y apellidos del titular.';
    } elseif (!preg_match('/^\d{8}$/', $dni_in)) {
        $error = 'El DNI debe tener 8 dígitos.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM credenciales WHERE dni = ? AND nombres_completos LIKE ? LIMIT 1"
        );
        $stmt->execute([$dni_in, '%' . $nombre_in . '%']);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$resultado) $error = 'No se encontró ninguna credencial con esos datos. Verifica el DNI y el nombre ingresados.';
    }
}

$datos_publicos = null;
if ($resultado) {
    $info = estado_info($resultado['estado']);
    $datos_publicos = [
        'nombre_enmascarado' => enmascarar_nombre($resultado['nombres_completos']),
        'dni_enmascarado'    => enmascarar_dni($resultado['dni']),
        'cargo'              => $resultado['cargo'] ?: 'Acreditado',
        'codigo'             => $resultado['codigo'],
        'estado'             => $info,
        'vencimiento'        => fecha_es($resultado['fecha_vencimiento']),
        'provincia'          => $resultado['provincia'] ?: '---',
        'distrito'           => $resultado['distrito'] ?: '---',
        'foto'               => $resultado['foto'] ? (BASE_URL . '/' . $resultado['foto']) : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verificar Credencial - <?= htmlspecialchars($brand_name) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <?php $fv = cfg_value($cfg_camp, 'site_favicon', ''); $fv_url = ($fv !== '' ? ((str_starts_with($fv,'/') ? BASE_URL : '') . $fv) : ''); ?>
  <?php if ($fv_url !== ''): ?><link rel="icon" href="<?= htmlspecialchars($fv_url) ?>"><?php endif; ?>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: { colors: { primary: '#049CD4', secondary: '#028FB7', accent: '#028FB7' },
        fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style> body { font-family: 'Inter', system-ui, sans-serif; } </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen flex flex-col">

  <?php if ($token !== '' && $datos_publicos): ?>
  <div id="qrModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6">
    <div class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm"></div>
    <div class="relative z-10 my-8 w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
      <div class="bg-primary px-6 py-5 text-white">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.18em] text-accent">Verificación pública</p>
            <h1 class="mt-1 text-xl font-black leading-tight"><?= htmlspecialchars($partido_nombre) ?></h1>
            <p class="mt-1 text-xs font-medium text-white/70">Resultado de lectura QR</p>
          </div>
          <button type="button" onclick="document.getElementById('qrModal').style.display='none'"
                  class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/15 hover:text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="px-6 py-6">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
          <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-white px-4 py-3">
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Resultado</p>
              <p class="text-sm font-black text-slate-900">Credencial encontrada</p>
            </div>
            <span class="rounded-full px-3 py-1 text-[11px] font-black"
                  style="background:<?= htmlspecialchars($datos_publicos['estado']['bg']) ?>; color:<?= htmlspecialchars($datos_publicos['estado']['color']) ?>">
              <?= htmlspecialchars($datos_publicos['estado']['label']) ?>
            </span>
          </div>

          <dl class="grid grid-cols-1 gap-3 px-4 py-4 text-sm">
            <div>
              <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Apellidos y nombres</dt>
              <dd class="mt-0.5 font-black text-slate-900"><?= htmlspecialchars($datos_publicos['nombre_enmascarado']) ?></dd>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Cargo</dt>
                <dd class="mt-0.5 font-bold text-primary"><?= htmlspecialchars($datos_publicos['cargo']) ?></dd>
              </div>
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">DNI</dt>
                <dd class="mt-0.5 font-mono font-bold text-slate-800"><?= htmlspecialchars($datos_publicos['dni_enmascarado']) ?></dd>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Caduca</dt>
                <dd class="mt-0.5 font-bold text-slate-800"><?= htmlspecialchars($datos_publicos['vencimiento']) ?></dd>
              </div>
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Código</dt>
                <dd class="mt-0.5 font-mono text-xs font-bold text-slate-500"><?= htmlspecialchars($datos_publicos['codigo']) ?></dd>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Provincia</dt>
                <dd class="mt-0.5 font-bold text-slate-800"><?= htmlspecialchars($datos_publicos['provincia']) ?></dd>
              </div>
              <div>
                <dt class="text-[11px] font-black uppercase tracking-wide text-slate-400">Distrito</dt>
                <dd class="mt-0.5 font-bold text-slate-800"><?= htmlspecialchars($datos_publicos['distrito']) ?></dd>
              </div>
            </div>
          </dl>
        </div>

        <p class="mt-4 text-center text-xs font-medium text-slate-400">
          La información se muestra parcialmente por seguridad.
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <header class="bg-primary text-white py-4 shadow-md">
    <div class="max-w-3xl mx-auto px-4 flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div>
        <div class="font-black text-sm sm:text-base leading-tight">Verificación de Credenciales</div>
        <div class="text-xs text-white/70"><?= htmlspecialchars($partido_nombre) ?></div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-3xl w-full mx-auto px-4 py-8 sm:py-12">

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8">
      <h1 class="text-xl sm:text-2xl font-black text-gray-800">Consulta tu credencial</h1>
      <p class="text-sm text-gray-500 mt-1.5">
        Ingresa el número de DNI y los nombres y apellidos del titular para verificar la vigencia y autenticidad
        de una credencial partidaria. También puedes escanear el código QR impreso en la credencial.
      </p>

      <form method="post" class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="buscar_manual" value="1">
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">DNI</label>
          <input type="text" name="dni" maxlength="8" inputmode="numeric" required
                 value="<?= htmlspecialchars((string)($_POST['dni'] ?? '')) ?>"
                 placeholder="Ej. 12345678"
                 class="w-full px-4 py-3 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-primary font-mono">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Nombres y apellidos</label>
          <input type="text" name="nombres" required
                 value="<?= htmlspecialchars((string)($_POST['nombres'] ?? '')) ?>"
                 placeholder="Ej. Juan Pérez"
                 class="w-full px-4 py-3 text-sm border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-primary">
        </div>
        <div class="sm:col-span-2">
          <button type="submit"
                  class="inline-flex items-center justify-center gap-2 bg-primary hover:bg-[#028FB7] text-white
                         text-sm font-bold px-6 py-3 rounded-xl shadow transition-all w-full sm:w-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
            </svg>
            Verificar credencial
          </button>
        </div>
      </form>
    </div>

    <?php if ($buscado): ?>
    <div class="mt-6">
      <?php if ($datos_publicos): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div class="px-6 sm:px-8 py-4 border-b border-gray-50 flex items-center justify-between"
               style="background:<?= htmlspecialchars($datos_publicos['estado']['bg']) ?>">
            <span class="text-sm font-black" style="color:<?= htmlspecialchars($datos_publicos['estado']['color']) ?>">
              Credencial <?= htmlspecialchars($datos_publicos['estado']['label']) ?>
            </span>
            <span class="text-xs font-mono font-bold text-gray-500"><?= htmlspecialchars($datos_publicos['codigo']) ?></span>
          </div>

          <div class="p-6 sm:p-8 flex flex-col sm:flex-row gap-6 items-center sm:items-start text-center sm:text-left">
            <div class="w-24 h-24 rounded-2xl overflow-hidden bg-gray-100 border border-gray-200 flex items-center justify-center flex-shrink-0">
              <?php if ($datos_publicos['foto']): ?>
                <img src="<?= htmlspecialchars($datos_publicos['foto']) ?>" alt="Foto del titular" class="w-24 h-24 object-cover">
              <?php else: ?>
                <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              <?php endif; ?>
            </div>
            <div class="flex-1">
              <div class="text-lg sm:text-xl font-black text-gray-800"><?= htmlspecialchars($datos_publicos['nombre_enmascarado']) ?></div>
              <div class="text-sm font-bold text-primary mt-0.5"><?= htmlspecialchars($datos_publicos['cargo']) ?></div>

              <dl class="grid grid-cols-2 gap-3 mt-5 text-left">
                <div>
                  <dt class="text-[11px] font-bold text-gray-400 uppercase tracking-wide">DNI</dt>
                  <dd class="text-sm font-mono font-bold text-gray-700 mt-0.5"><?= htmlspecialchars($datos_publicos['dni_enmascarado']) ?></dd>
                </div>
                <div>
                  <dt class="text-[11px] font-bold text-gray-400 uppercase tracking-wide">Vigencia hasta</dt>
                  <dd class="text-sm font-bold text-gray-700 mt-0.5"><?= htmlspecialchars($datos_publicos['vencimiento']) ?></dd>
                </div>
                <div>
                  <dt class="text-[11px] font-bold text-gray-400 uppercase tracking-wide">Provincia</dt>
                  <dd class="text-sm font-bold text-gray-700 mt-0.5"><?= htmlspecialchars($datos_publicos['provincia']) ?></dd>
                </div>
                <div>
                  <dt class="text-[11px] font-bold text-gray-400 uppercase tracking-wide">Distrito</dt>
                  <dd class="text-sm font-bold text-gray-700 mt-0.5"><?= htmlspecialchars($datos_publicos['distrito']) ?></dd>
                </div>
              </dl>

              <p class="text-xs text-gray-400 mt-5"><?= htmlspecialchars($datos_publicos['estado']['desc']) ?></p>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-6 flex items-start gap-3">
          <svg class="w-6 h-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3l-6.93-12a2 2 0 00-3.48 0l-6.93 12a2 2 0 001.74 3z"/>
          </svg>
          <div>
            <p class="font-bold text-sm">No se encontró la credencial</p>
            <p class="text-sm mt-0.5"><?= htmlspecialchars($error) ?></p>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <p class="text-xs text-gray-400 text-center mt-8">
      Por seguridad, esta página solo muestra información parcial del titular.
      Si tienes dudas sobre la autenticidad de una credencial, comunícate con la secretaría de <?= htmlspecialchars($partido_nombre) ?>.
    </p>
  </main>

  <footer class="bg-white border-t border-gray-100 py-5">
    <p class="text-center text-xs text-gray-400">&copy; <?= date('Y') ?> <?= htmlspecialchars($partido_nombre) ?> — Todos los derechos reservados.</p>
  </footer>

</body>
</html>
