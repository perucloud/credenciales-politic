<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_modulo($pdo, 'simpatizantes');

function ensure_simpatizante_estado(PDO $pdo): void {
    try {
        $existe = $pdo->query("SHOW COLUMNS FROM simpatizantes LIKE 'estado'")->fetch();
        if (!$existe) {
            $pdo->exec("ALTER TABLE simpatizantes ADD COLUMN estado ENUM('activo','bloqueado') NOT NULL DEFAULT 'activo'");
        }
    } catch (Exception $e) {}
}
ensure_simpatizante_estado($pdo);


$flash = null;
$flash_type = 'success';
// El modulo "militantes" no existe en credenciales-app (solo se migraron
// Dashboard, Simpatizantes, Personeros y Credenciales), por lo que la
// conversion a militante queda deshabilitada permanentemente.
$can_manage_militantes = false;

if (isset($_GET['msg'])) {
    $messages = [
        'militante_creado' => ['success', 'Simpatizante convertido en militante correctamente.'],
        'militante_duplicado' => ['error', 'Este DNI ya existe en la lista de militantes.'],
        'militante_error' => ['error', 'No se pudo convertir el simpatizante. Revisa la base de datos.'],
        'militante_permiso' => ['error', 'No tienes permisos para administrar militantes.'],
        'simpatizante_actualizado' => ['success', 'Datos del simpatizante actualizados correctamente.'],
        'simpatizante_bloqueado' => ['success', 'Simpatizante bloqueado correctamente.'],
        'simpatizante_desbloqueado' => ['success', 'Simpatizante desbloqueado correctamente.'],
        'simpatizante_eliminado' => ['success', 'Simpatizante eliminado correctamente.'],
        'simpatizante_no_encontrado' => ['error', 'No se encontró el simpatizante indicado.'],
        'simpatizante_error' => ['error', 'No se pudo completar la acción. Revisa la base de datos.'],
    ];
    if (isset($messages[$_GET['msg']])) {
        [$flash_type, $flash] = $messages[$_GET['msg']];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'convertir_militante') {
    csrf_verify();

    if (!$can_manage_militantes) {
        header('Location: simpatizantes.php?msg=militante_permiso');
        exit;
    }

    $simpatizante_id = (int)($_POST['simpatizante_id'] ?? 0);
    $cargo_id = (int)($_POST['cargo_id'] ?? 0);
    $fecha_ingreso = trim($_POST['fecha_ingreso'] ?? date('Y-m-d'));
    if ($fecha_ingreso === '') $fecha_ingreso = date('Y-m-d');

    try {
        $stmt = $pdo->prepare("SELECT * FROM simpatizantes WHERE id = ? LIMIT 1");
        $stmt->execute([$simpatizante_id]);
        $simpatizante = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$simpatizante) {
            header('Location: simpatizantes.php?msg=militante_error');
            exit;
        }

        $dup = $pdo->prepare("SELECT id FROM militantes WHERE dni = ? LIMIT 1");
        $dup->execute([$simpatizante['dni']]);
        if ($dup->fetchColumn()) {
            header('Location: simpatizantes.php?msg=militante_duplicado');
            exit;
        }

        $celular = $simpatizante['celular'] ?: ($simpatizante['telefono'] ?? null);
        $whatsapp = $simpatizante['whatsapp'] ?: null;
        $correo = $simpatizante['correo'] ?: null;
        $cargo_value = $cargo_id > 0 ? $cargo_id : null;

        $insert = $pdo->prepare(
            "INSERT INTO militantes
             (simpatizante_id, nombre, dni, celular, whatsapp, correo, cargo_id, fecha_ingreso, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')"
        );
        $insert->execute([
            $simpatizante_id,
            $simpatizante['nombre'],
            $simpatizante['dni'],
            $celular,
            $whatsapp,
            $correo,
            $cargo_value,
            $fecha_ingreso,
        ]);

        log_activity($pdo, 'Convirtio simpatizante en militante: ' . $simpatizante['nombre'], 'militantes');
        header('Location: simpatizantes.php?msg=militante_creado');
        exit;
    } catch (Exception $e) {
        header('Location: simpatizantes.php?msg=militante_error');
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'editar_simpatizante') {
    csrf_verify();

    $id = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $distrito = trim($_POST['distrito'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $formas_apoyo = trim($_POST['formas_apoyo'] ?? '');

    if ($id <= 0 || $nombre === '' || $dni === '') {
        header('Location: simpatizantes.php?msg=simpatizante_error');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE simpatizantes SET nombre = ?, dni = ?, distrito = ?, celular = ?, whatsapp = ?, correo = ?, formas_apoyo = ? WHERE id = ?"
        );
        $stmt->execute([$nombre, $dni, $distrito, $celular, $whatsapp, $correo, $formas_apoyo, $id]);

        log_activity($pdo, 'Edito datos del simpatizante: ' . $nombre, 'simpatizantes');
        header('Location: simpatizantes.php?msg=simpatizante_actualizado');
        exit;
    } catch (Exception $e) {
        header('Location: simpatizantes.php?msg=simpatizante_error');
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'alternar_estado_simpatizante') {
    csrf_verify();

    $id = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT * FROM simpatizantes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $simpatizante = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$simpatizante) {
            header('Location: simpatizantes.php?msg=simpatizante_no_encontrado');
            exit;
        }

        $nuevo_estado = (($simpatizante['estado'] ?? 'activo') === 'bloqueado') ? 'activo' : 'bloqueado';
        $upd = $pdo->prepare("UPDATE simpatizantes SET estado = ? WHERE id = ?");
        $upd->execute([$nuevo_estado, $id]);

        $accion = $nuevo_estado === 'bloqueado' ? 'Bloqueo' : 'Desbloqueo';
        log_activity($pdo, $accion . ' al simpatizante: ' . $simpatizante['nombre'], 'simpatizantes');
        header('Location: simpatizantes.php?msg=' . ($nuevo_estado === 'bloqueado' ? 'simpatizante_bloqueado' : 'simpatizante_desbloqueado'));
        exit;
    } catch (Exception $e) {
        header('Location: simpatizantes.php?msg=simpatizante_error');
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'eliminar_simpatizante') {
    csrf_verify();

    $id = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT * FROM simpatizantes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $simpatizante = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$simpatizante) {
            header('Location: simpatizantes.php?msg=simpatizante_no_encontrado');
            exit;
        }

        $del = $pdo->prepare("DELETE FROM simpatizantes WHERE id = ?");
        $del->execute([$id]);

        log_activity($pdo, 'Elimino al simpatizante: ' . $simpatizante['nombre'], 'simpatizantes');
        header('Location: simpatizantes.php?msg=simpatizante_eliminado');
        exit;
    } catch (Exception $e) {
        header('Location: simpatizantes.php?msg=simpatizante_error');
        exit;
    }
}

$buscar       = trim($_GET['q'] ?? '');
$distrito_fil = trim($_GET['distrito'] ?? '');
$fecha_desde  = trim($_GET['desde'] ?? '');
$fecha_hasta  = trim($_GET['hasta'] ?? '');
$orden        = in_array($_GET['orden'] ?? '', ['asc','desc']) ? $_GET['orden'] : 'desc';
$por_pag      = 15;
$pag          = max(1, (int)($_GET['pag'] ?? 1));
$offset       = ($pag - 1) * $por_pag;

$stats = [
    'total' => 0,
    'semana' => 0,
    'mes' => 0,
    'distrito_fuerte' => ['distrito' => 'Sin datos', 'total' => 0],
    'distrito_debil' => ['distrito' => 'Sin datos', 'total' => 0],
    'distritos' => [],
];
$cargos_militante = [];

try {
    try {
        $cargos_militante = $pdo->query(
            "SELECT id, nombre FROM militante_cargos WHERE activo=1 ORDER BY orden ASC, nombre ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cargos_militante = [];
    }

    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM simpatizantes")->fetchColumn();
    $stats['semana'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM simpatizantes
         WHERE YEARWEEK(fecha_registro, 1) = YEARWEEK(CURDATE(), 1)"
    )->fetchColumn();
    $stats['mes'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM simpatizantes
         WHERE YEAR(fecha_registro) = YEAR(CURDATE())
           AND MONTH(fecha_registro) = MONTH(CURDATE())"
    )->fetchColumn();

    $distritos_stmt = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(distrito), ''), 'Sin distrito') AS distrito,
                COUNT(*) AS total
         FROM simpatizantes
         GROUP BY COALESCE(NULLIF(TRIM(distrito), ''), 'Sin distrito')
         ORDER BY total DESC, distrito ASC"
    );
    $stats['distritos'] = $distritos_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($stats['distritos'])) {
        $stats['distrito_fuerte'] = $stats['distritos'][0];
        $stats['distrito_debil'] = $stats['distritos'][count($stats['distritos']) - 1];
    }

    $where_parts = [];
    $bind_count  = [];
    $bind_data   = [];

    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $where_parts[] = "(s.nombre LIKE ? OR s.dni LIKE ?)";
        $bind_count[]  = $like; $bind_count[]  = $like;
        $bind_data[]   = $like; $bind_data[]   = $like;
    }
    if ($distrito_fil !== '') {
        $where_parts[] = "s.distrito = ?";
        $bind_count[]  = $distrito_fil;
        $bind_data[]   = $distrito_fil;
    }
    if ($fecha_desde !== '') {
        $where_parts[] = "DATE(s.fecha_registro) >= ?";
        $bind_count[]  = $fecha_desde;
        $bind_data[]   = $fecha_desde;
    }
    if ($fecha_hasta !== '') {
        $where_parts[] = "DATE(s.fecha_registro) <= ?";
        $bind_count[]  = $fecha_hasta;
        $bind_data[]   = $fecha_hasta;
    }
    $where_sql  = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";
    $order_dir  = $orden === 'asc' ? 'ASC' : 'DESC';

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM simpatizantes s $where_sql");
    $count_stmt->execute($bind_count);
    $total = (int)$count_stmt->fetchColumn();

    $data_stmt = $pdo->prepare(
        "SELECT s.*, NULL AS militante_id
         FROM simpatizantes s
         $where_sql
         ORDER BY s.fecha_registro $order_dir, s.id $order_dir
         LIMIT ? OFFSET ?"
    );
    $bind_data[] = $por_pag;
    $bind_data[] = $offset;
    $data_stmt->execute($bind_data);
    $registros = $data_stmt->fetchAll();
} catch (Exception $e) { $total = 0; $registros = []; }

$pages = ceil($total / $por_pag);

$page_title = 'Simpatizantes';
include __DIR__ . '/layout.php';
?>

<?php if ($flash): ?>
<div class="mb-5 rounded-2xl px-5 py-4 text-sm font-bold border <?= $flash_type === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-6">
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-black uppercase tracking-wide text-gray-400">Total registrados</p>
    <p class="text-3xl font-black text-[#1E3A8A] mt-1"><?= (int)$stats['total'] ?></p>
    <p class="text-xs text-gray-400 mt-1">Base completa</p>
  </div>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-black uppercase tracking-wide text-gray-400">Esta semana</p>
    <p class="text-3xl font-black text-green-600 mt-1"><?= (int)$stats['semana'] ?></p>
    <p class="text-xs text-gray-400 mt-1">Semana actual</p>
  </div>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-black uppercase tracking-wide text-gray-400">Este mes</p>
    <p class="text-3xl font-black text-blue-600 mt-1"><?= (int)$stats['mes'] ?></p>
    <p class="text-xs text-gray-400 mt-1">Mes actual</p>
  </div>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-black uppercase tracking-wide text-gray-400">Distrito fuerte</p>
    <p class="text-base font-black text-[#1E3A8A] mt-2 truncate" title="<?= htmlspecialchars($stats['distrito_fuerte']['distrito']) ?>">
      <?= htmlspecialchars($stats['distrito_fuerte']['distrito']) ?>
    </p>
    <p class="text-xs text-gray-400 mt-1"><?= (int)$stats['distrito_fuerte']['total'] ?> simpatizantes</p>
  </div>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-black uppercase tracking-wide text-gray-400">Distrito debil</p>
    <p class="text-base font-black text-red-500 mt-2 truncate" title="<?= htmlspecialchars($stats['distrito_debil']['distrito']) ?>">
      <?= htmlspecialchars($stats['distrito_debil']['distrito']) ?>
    </p>
    <p class="text-xs text-gray-400 mt-1"><?= (int)$stats['distrito_debil']['total'] ?> simpatizantes</p>
  </div>
</div>


<?php
$pdf_url          = 'exportar-pdf.php';
$pdf_url_embed    = 'exportar-pdf.php?embed=1';
$pdf_download_url = 'exportar-pdf.php?download=1';
?>

<div x-data="{
  pdfModal: false,
  pdfUrl: '<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>',
  pdfEmbedUrl: '<?= htmlspecialchars($pdf_url_embed, ENT_QUOTES) ?>',
  pdfDownloadUrl: '<?= htmlspecialchars($pdf_download_url, ENT_QUOTES) ?>',
  printPdf() {
    const f = this.$refs.pdfFrame;
    if (f && f.contentWindow) { f.contentWindow.focus(); f.contentWindow.print(); }
  },
  downloadPdf() { window.open(this.pdfDownloadUrl, '_blank'); },
}">

<form id="filtros-form" method="GET" class="mb-5 space-y-3">
  <!-- Fila 1: búsqueda + PDF -->
  <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
    <div class="relative flex-1 sm:max-w-sm">
      <input type="text" name="q" id="buscador" value="<?= htmlspecialchars($buscar) ?>"
             placeholder="Buscar por nombre o DNI..."
             autocomplete="off"
             class="w-full border border-gray-200 rounded-xl pl-9 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      <?php if ($buscar || $distrito_fil || $fecha_desde || $fecha_hasta): ?>
      <a href="simpatizantes.php" class="text-sm text-gray-400 hover:text-red-500 font-semibold px-3 py-2.5">✕ Limpiar</a>
      <?php endif; ?>
      <button type="button" @click="pdfModal = true"
              class="inline-flex items-center gap-2 border border-red-300 text-red-600 hover:bg-red-50 font-semibold text-sm px-4 py-2.5 rounded-xl transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        PDF
      </button>
    </div>
  </div>
  <!-- Fila 2: filtros adicionales -->
  <div class="flex flex-wrap items-center gap-3">
    <select name="distrito" onchange="document.getElementById('filtros-form').submit()"
            class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none bg-white">
      <option value="">Todos los distritos</option>
      <?php foreach ($stats['distritos'] as $d): ?>
      <option value="<?= htmlspecialchars($d['distrito']) ?>" <?= $distrito_fil === $d['distrito'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($d['distrito']) ?> (<?= (int)$d['total'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>"
           onchange="document.getElementById('filtros-form').submit()"
           title="Desde"
           class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none bg-white">
    <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>"
           onchange="document.getElementById('filtros-form').submit()"
           title="Hasta"
           class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none bg-white">
    <select name="orden" onchange="document.getElementById('filtros-form').submit()"
            class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none bg-white">
      <option value="desc" <?= $orden === 'desc' ? 'selected' : '' ?>>Más recientes primero</option>
      <option value="asc"  <?= $orden === 'asc'  ? 'selected' : '' ?>>Más antiguos primero</option>
    </select>
  </div>
</form>
<script>
(function(){
  const inp = document.getElementById('buscador');
  if (!inp) return;
  let t = null;
  inp.addEventListener('input', function() {
    clearTimeout(t);
    t = setTimeout(() => document.getElementById('filtros-form').submit(), 400);
  });
})();
</script>

<!-- ── Modal PDF PRO ──────────────────────────────────────── -->
<div x-show="pdfModal" x-cloak class="fixed inset-0 z-[95] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-gray-950/60 backdrop-blur-sm" @click="pdfModal = false"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-6xl h-[90vh] overflow-hidden flex flex-col">

    <!-- Header -->
    <div class="bg-[#1E3A8A] px-5 py-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
      <div>
        <h2 class="text-white font-black text-lg">Vista previa PDF</h2>
        <p class="text-blue-100 text-sm mt-0.5">Lista completa de simpatizantes registrados.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" @click="printPdf()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-black bg-[#FACC15] text-[#1E3A8A] border border-yellow-300 hover:bg-yellow-300 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
          Imprimir
        </button>
        <button type="button" @click="downloadPdf()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-black bg-white text-[#1E3A8A] border border-blue-100 hover:bg-blue-50 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
          Descargar PDF
        </button>
        <a :href="pdfUrl" target="_blank"
           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-black bg-white/10 text-white border border-white/20 hover:bg-white/20 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
          Abrir pestaña
        </a>
        <button type="button" @click="pdfModal = false"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-black bg-white/10 text-white border border-white/20 hover:bg-white/20 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          Cerrar
        </button>
      </div>
    </div>

    <!-- Iframe -->
    <div class="bg-gray-100 p-3 flex-1 min-h-0">
      <iframe x-ref="pdfFrame" :src="pdfEmbedUrl"
              class="w-full h-full bg-white rounded-xl border border-gray-200"></iframe>
    </div>

  </div>
</div>

</div><!-- /x-data pdfModal -->

<p class="text-sm text-gray-400 mb-4"><?= $total ?> simpatizantes <?= $buscar ? "encontrados para \"$buscar\"" : 'registrados' ?></p>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
     x-data="{
       modal: false, simpatizante: {}, openConvertir(row) { this.simpatizante = row; this.modal = true; },
       editModal: false, editar: {},
       openEditar(row) { this.editar = { ...row }; this.editModal = true; },
     }">
  <?php if (empty($registros)): ?>
  <p class="text-center text-gray-400 py-16 text-sm">Sin registros<?= $buscar ? ' para esa búsqueda' : '' ?>.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
        <tr>
          <th class="px-4 py-3 text-left">#</th>
          <th class="px-4 py-3 text-left">Apellidos y nombres</th>
          <th class="px-4 py-3 text-left">DNI</th>
          <th class="px-4 py-3 text-left">Cel</th>
          <th class="px-4 py-3 text-left">WhatsApp</th>
          <th class="px-4 py-3 text-left">Correo</th>
          <th class="px-4 py-3 text-left">Distrito</th>
          <th class="px-4 py-3 text-left">Cómo apoya</th>
          <th class="px-4 py-3 text-left">Fecha</th>
          <th class="px-4 py-3 text-left">Accion</th>
          <th class="px-4 py-3 text-left">Gestión</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($registros as $i => $r): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="px-4 py-3 text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
          <td class="px-4 py-3">
            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($r['nombre']) ?></p>
          </td>
          <td class="px-4 py-3">
            <p class="font-mono text-gray-600 text-sm"><?= htmlspecialchars($r['dni']) ?></p>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
            <?php $cel = $r['celular'] ?: ($r['telefono'] ?? ''); ?>
            <?= $cel !== '' ? htmlspecialchars($cel) : '<span class="text-gray-300">-</span>' ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
            <?= !empty($r['whatsapp']) ? htmlspecialchars($r['whatsapp']) : '<span class="text-gray-300">-</span>' ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-500 max-w-[180px] break-words">
            <?= !empty($r['correo']) ? htmlspecialchars($r['correo']) : '<span class="text-gray-300">-</span>' ?>
          </td>
          <td class="px-4 py-3">
            <span class="bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded-full font-medium">
              <?= htmlspecialchars($r['distrito'] ?? '') ?>
            </span>
          </td>
          <td class="px-4 py-3 text-xs text-gray-500 max-w-[180px]">
            <?= htmlspecialchars($r['formas_apoyo'] ?? '—') ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap"><?= date('d/m/Y', strtotime($r['fecha_registro'])) ?></td>
          <td class="px-4 py-3 whitespace-nowrap">
            <?php if (!empty($r['militante_id'])): ?>
            <span class="inline-flex items-center bg-green-50 text-green-700 text-xs font-bold px-3 py-1.5 rounded-full">
              Militante
            </span>
            <?php elseif (!$can_manage_militantes): ?>
            <span class="inline-flex items-center bg-gray-50 text-gray-400 text-xs font-bold px-3 py-1.5 rounded-full">
              Sin permiso
            </span>
            <?php else: ?>
            <div class="flex items-center gap-1.5">
              <button type="button"
                      title="Convertir a Militante"
                      @click='openConvertir(<?= json_encode([
                          'id' => (int)$r['id'],
                          'nombre' => $r['nombre'],
                          'dni' => $r['dni'],
                      ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>)'
                      class="inline-flex items-center gap-1.5 bg-[#1E3A8A] hover:bg-blue-900 text-white text-xs font-bold px-3 py-1.5 rounded-full transition-colors">
                Mil
              </button>
              <a href="personeros.php?from_simpatizante=<?= $r['id'] ?>"
                 title="Convertir a Personero"
                 class="inline-flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold px-3 py-1.5 rounded-full transition-colors">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/>
                </svg>
                Pers
              </a>
            </div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 whitespace-nowrap">
            <div class="flex items-center gap-1.5">
              <!-- Editar -->
              <button type="button"
                      title="Editar"
                      @click='openEditar(<?= json_encode([
                          'id' => (int)$r['id'],
                          'nombre' => $r['nombre'],
                          'dni' => $r['dni'],
                          'celular' => $r['celular'] ?: ($r['telefono'] ?? ''),
                          'whatsapp' => $r['whatsapp'] ?? '',
                          'correo' => $r['correo'] ?? '',
                          'distrito' => $r['distrito'] ?? '',
                          'formas_apoyo' => $r['formas_apoyo'] ?? '',
                      ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>)'
                      class="w-8 h-8 flex items-center justify-center rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
              </button>
              <!-- Bloquear / Desbloquear -->
              <form method="POST" class="inline"
                    onsubmit="return confirm('<?= ($r['estado'] ?? 'activo') === 'bloqueado' ? '¿Desbloquear' : '¿Bloquear' ?> a <?= htmlspecialchars(addslashes($r['nombre']), ENT_QUOTES) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="alternar_estado_simpatizante">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <?php if (($r['estado'] ?? 'activo') === 'bloqueado'): ?>
                <button type="submit" title="Desbloquear"
                        class="w-8 h-8 flex items-center justify-center rounded-xl bg-green-50 text-green-600 hover:bg-green-100 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 11V7a4 4 0 118 0m-9 4h10a1 1 0 011 1v7a1 1 0 01-1 1H7a1 1 0 01-1-1v-7a1 1 0 011-1z"/>
                  </svg>
                </button>
                <?php else: ?>
                <button type="submit" title="Bloquear"
                        class="w-8 h-8 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                </button>
                <?php endif; ?>
              </form>
              <!-- Eliminar -->
              <form method="POST" class="inline"
                    onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars(addslashes($r['nombre']), ENT_QUOTES) ?>? Esta acción no se puede deshacer.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="eliminar_simpatizante">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" title="Eliminar"
                        class="w-8 h-8 flex items-center justify-center rounded-xl bg-red-50 text-red-500 hover:bg-red-100 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <div x-show="modal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="modal = false"></div>
    <form method="POST" class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-lg overflow-hidden">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="convertir_militante">
      <input type="hidden" name="simpatizante_id" :value="simpatizante.id || ''">

      <div class="bg-[#1E3A8A] px-6 py-4">
        <h2 class="text-white font-black text-lg">Convertir en Militante</h2>
        <p class="text-blue-100 text-sm mt-1">Asignacion oficial dentro de la estructura del partido.</p>
      </div>

      <div class="p-6 space-y-5">
        <div class="bg-gray-50 rounded-xl p-4">
          <p class="text-xs font-black uppercase tracking-wide text-gray-400 mb-1">Simpatizante</p>
          <p class="font-black text-gray-800" x-text="simpatizante.nombre"></p>
          <p class="text-sm text-gray-500">DNI: <span x-text="simpatizante.dni"></span></p>
        </div>

        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Fecha de ingreso</label>
          <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>

        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cargo</label>
          <select name="cargo_id"
                  class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
            <option value="">Sin cargo por ahora</option>
            <?php foreach ($cargos_militante as $cargo): ?>
            <option value="<?= (int)$cargo['id'] ?>"><?= htmlspecialchars($cargo['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($cargos_militante)): ?>
          <p class="text-xs text-amber-600 mt-2">No hay cargos activos. Ejecuta la migracion de militantes.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3">
        <button type="button" @click="modal = false"
                class="px-5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-600 text-sm font-bold hover:bg-gray-100">
          Cancelar
        </button>
        <button type="submit"
                class="px-5 py-2.5 rounded-xl bg-[#1E3A8A] text-white text-sm font-bold hover:bg-blue-900">
          Crear militante
        </button>
      </div>
    </form>
  </div>

  <!-- Modal: Editar simpatizante -->
  <div x-show="editModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="editModal = false"></div>
    <form method="POST" class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-lg overflow-hidden max-h-[90vh] overflow-y-auto">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="editar_simpatizante">
      <input type="hidden" name="id" :value="editar.id || ''">

      <div class="bg-[#1E3A8A] px-6 py-4">
        <h2 class="text-white font-black text-lg">Editar simpatizante</h2>
        <p class="text-blue-100 text-sm mt-1">Actualiza los datos de contacto y apoyo.</p>
      </div>

      <div class="p-6 space-y-4">
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Apellidos y nombres</label>
          <input type="text" name="nombre" x-model="editar.nombre" required
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">DNI</label>
            <input type="text" name="dni" x-model="editar.dni" maxlength="8" inputmode="numeric" required
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Distrito</label>
            <input type="text" name="distrito" x-model="editar.distrito"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">Celular</label>
            <input type="text" name="celular" x-model="editar.celular"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          </div>
          <div>
            <label class="block text-xs font-black text-gray-500 uppercase mb-1">WhatsApp</label>
            <input type="text" name="whatsapp" x-model="editar.whatsapp"
                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
          </div>
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Correo electrónico</label>
          <input type="email" name="correo" x-model="editar.correo"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
        <div>
          <label class="block text-xs font-black text-gray-500 uppercase mb-1">Cómo apoya</label>
          <input type="text" name="formas_apoyo" x-model="editar.formas_apoyo"
                 class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-[#1E3A8A] outline-none">
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3">
        <button type="button" @click="editModal = false"
                class="px-5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-600 text-sm font-bold hover:bg-gray-100">
          Cancelar
        </button>
        <button type="submit"
                class="px-5 py-2.5 rounded-xl bg-[#1E3A8A] text-white text-sm font-bold hover:bg-blue-900">
          Guardar cambios
        </button>
      </div>
    </form>
  </div>

  <?php if ($pages > 1):
    $qs = array_filter([
      'q'        => $buscar,
      'distrito' => $distrito_fil,
      'desde'    => $fecha_desde,
      'hasta'    => $fecha_hasta,
      'orden'    => $orden !== 'desc' ? $orden : '',
    ]);
    $qs_base = $qs ? '&' . http_build_query($qs) : '';
  ?>
  <div class="flex items-center justify-center gap-1.5 py-5 border-t border-gray-50 flex-wrap">
    <?php if ($pag > 1): ?>
    <a href="?pag=<?= $pag-1 ?><?= $qs_base ?>"
       class="px-3 h-8 flex items-center justify-center rounded-lg text-sm font-semibold text-gray-500 hover:bg-gray-100 transition-colors">
      ← Ant
    </a>
    <?php endif; ?>
    <?php
      $rango_ini = max(1, $pag - 2);
      $rango_fin = min($pages, $pag + 2);
      if ($rango_ini > 1): ?>
        <a href="?pag=1<?= $qs_base ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-semibold text-gray-500 hover:bg-gray-100">1</a>
        <?php if ($rango_ini > 2): ?><span class="text-gray-300 text-sm">…</span><?php endif; ?>
    <?php endif; ?>
    <?php for ($i = $rango_ini; $i <= $rango_fin; $i++): ?>
    <a href="?pag=<?= $i ?><?= $qs_base ?>"
       class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-semibold transition-colors
              <?= $i===$pag ? 'bg-[#1E3A8A] text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
      <?= $i ?>
    </a>
    <?php endfor; ?>
    <?php if ($rango_fin < $pages): ?>
        <?php if ($rango_fin < $pages - 1): ?><span class="text-gray-300 text-sm">…</span><?php endif; ?>
        <a href="?pag=<?= $pages ?><?= $qs_base ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-semibold text-gray-500 hover:bg-gray-100"><?= $pages ?></a>
    <?php endif; ?>
    <?php if ($pag < $pages): ?>
    <a href="?pag=<?= $pag+1 ?><?= $qs_base ?>"
       class="px-3 h-8 flex items-center justify-center rounded-lg text-sm font-semibold text-gray-500 hover:bg-gray-100 transition-colors">
      Sig →
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

    </main>
  </div>
</body>
</html>
