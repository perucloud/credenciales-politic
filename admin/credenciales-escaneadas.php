<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/reniec.php';

require_login();
require_modulo($pdo, 'credenciales_escaneadas');

$page_title = 'Credenciales escaneadas';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function scanned_json(array $data, int $status = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function scanned_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS credenciales_escaneadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dni CHAR(8) NOT NULL,
        nombres_completos VARCHAR(220) NOT NULL,
        lugar VARCHAR(180) NULL,
        archivo VARCHAR(300) NOT NULL,
        archivo_nombre VARCHAR(180) NULL,
        creado_por INT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NULL,
        INDEX idx_ce_dni (dni),
        INDEX idx_ce_nombre (nombres_completos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function scanned_find_name(PDO $pdo, string $dni): ?string {
    if (!preg_match('/^\d{8}$/', $dni)) return null;
    $queries = [
        "SELECT nombre FROM militantes WHERE dni = ? LIMIT 1",
        "SELECT nombre FROM simpatizantes WHERE dni = ? LIMIT 1",
        "SELECT nombres_completos FROM credenciales WHERE dni = ? ORDER BY id DESC LIMIT 1",
        "SELECT nombres_completos FROM credenciales_escaneadas WHERE dni = ? ORDER BY id DESC LIMIT 1",
    ];
    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dni]);
            $name = trim((string)$stmt->fetchColumn());
            if ($name !== '') return $name;
        } catch (Exception $e) {}
    }
    $reniec = consultar_reniec_dni($dni);
    return $reniec['nombre_completo'] ?? null;
}

function scanned_upload(array $file, ?string $old = null): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($old) return $old;
        throw new RuntimeException('Adjunta la credencial escaneada.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        throw new RuntimeException('No se pudo recibir el archivo.');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('El archivo debe pesar maximo 8 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
    }

    $dir = dirname(__DIR__) . '/uploads/credenciales-escaneadas/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de uploads.');
    }

    $ext = $allowed[$mime];
    $name = 'credencial_escaneada_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    if ($old) {
        $old_path = dirname(__DIR__) . '/' . ltrim($old, '/');
        $root = realpath(dirname(__DIR__) . '/uploads/credenciales-escaneadas');
        $old_real = is_file($old_path) ? realpath($old_path) : false;
        if ($root && $old_real && str_starts_with($old_real, $root)) @unlink($old_real);
    }

    return 'uploads/credenciales-escaneadas/' . $name;
}

function scanned_rows(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM credenciales_escaneadas ORDER BY id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

scanned_schema($pdo);

if (isset($_GET['lookup_dni'])) {
    $dni = preg_replace('/\D+/', '', (string)($_GET['dni'] ?? ''));
    if (!preg_match('/^\d{8}$/', $dni)) scanned_json(['ok' => false, 'msg' => 'El DNI debe tener 8 digitos.'], 422);
    $name = scanned_find_name($pdo, $dni);
    if (!$name) scanned_json(['ok' => false, 'msg' => 'No se encontro informacion para este DNI.'], 404);
    scanned_json(['ok' => true, 'nombre' => $name]);
}

if (isset($_GET['json'])) {
    scanned_json(['ok' => true, 'rows' => scanned_rows($pdo)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['save', 'delete'], true)) csrf_verify(true);

    if ($action === 'save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $dni = preg_replace('/\D+/', '', (string)($_POST['dni'] ?? ''));
            $nombres = trim((string)($_POST['nombres_completos'] ?? ''));
            $lugar = trim((string)($_POST['lugar'] ?? ''));

            if (!preg_match('/^\d{8}$/', $dni)) throw new RuntimeException('El DNI debe tener 8 digitos.');
            if ($nombres === '') throw new RuntimeException('Consulta o ingresa los nombres y apellidos.');
            if ($lugar === '') throw new RuntimeException('Ingresa el lugar de la credencial.');

            $old = null;
            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT archivo FROM credenciales_escaneadas WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $old = $stmt->fetchColumn() ?: null;
            }

            $archivo = scanned_upload($_FILES['archivo'] ?? [], $old);
            $archivo_nombre = basename($archivo);

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE credenciales_escaneadas
                    SET dni=?, nombres_completos=?, lugar=?, archivo=?, archivo_nombre=?, actualizado_en=NOW()
                    WHERE id=?");
                $stmt->execute([$dni, $nombres, $lugar, $archivo, $archivo_nombre, $id]);
                log_activity($pdo, 'Actualizo credencial escaneada: ' . $nombres . ' (' . $dni . ')', 'credenciales_escaneadas');
            } else {
                $stmt = $pdo->prepare("INSERT INTO credenciales_escaneadas
                    (dni, nombres_completos, lugar, archivo, archivo_nombre, creado_por)
                    VALUES (?,?,?,?,?,?)");
                $stmt->execute([$dni, $nombres, $lugar, $archivo, $archivo_nombre, (int)($_SESSION['admin_id'] ?? 0)]);
                $id = (int)$pdo->lastInsertId();
                log_activity($pdo, 'Registro credencial escaneada: ' . $nombres . ' (' . $dni . ')', 'credenciales_escaneadas');
            }

            scanned_json(['ok' => true, 'id' => $id, 'rows' => scanned_rows($pdo)]);
        } catch (Throwable $e) {
            scanned_json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT archivo, nombres_completos, dni FROM credenciales_escaneadas WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) scanned_json(['ok' => false, 'msg' => 'Registro no encontrado.'], 404);

        $pdo->prepare("DELETE FROM credenciales_escaneadas WHERE id=?")->execute([$id]);
        $path = dirname(__DIR__) . '/' . ltrim((string)$row['archivo'], '/');
        $root = realpath(dirname(__DIR__) . '/uploads/credenciales-escaneadas');
        $real = is_file($path) ? realpath($path) : false;
        if ($root && $real && str_starts_with($real, $root)) @unlink($real);
        log_activity($pdo, 'Elimino credencial escaneada: ' . $row['nombres_completos'] . ' (' . $row['dni'] . ')', 'credenciales_escaneadas');
        scanned_json(['ok' => true, 'rows' => scanned_rows($pdo)]);
    }
}

$rows = scanned_rows($pdo);
require_once __DIR__ . '/layout.php';
?>

<div class="space-y-6" x-data="credencialesEscaneadas()" x-init="init()">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <div class="flex items-center gap-2 text-sm text-gray-400 mb-2">
        <a href="credenciales-modulo.php" class="hover:text-[#1E3A8A] font-bold">Credenciales</a>
        <i class="ti ti-chevron-right text-xs"></i>
        <span>Escaneadas</span>
      </div>
      <h1 class="text-2xl font-black text-gray-900">Credenciales escaneadas</h1>
      <p class="text-sm text-gray-500 mt-1">Registra las credenciales fisicas ya entregadas y conserva su archivo escaneado.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a href="exportar-credenciales-escaneadas-lista-pdf.php" target="_blank"
         class="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-black text-[#1E3A8A] shadow-sm hover:bg-blue-50">
        <i class="ti ti-file-type-pdf"></i>
        Imprimir lista detallada
      </a>
      <button type="button" @click="openModal()"
              class="inline-flex items-center gap-2 rounded-xl bg-[#1E3A8A] px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-blue-900/20 hover:bg-blue-900">
        <i class="ti ti-upload"></i>
        Subir credencial escaneada
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
    <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
      <p class="text-xs font-black uppercase text-gray-400">Total</p>
      <p class="mt-1 text-2xl font-black text-gray-900" x-text="rows.length"></p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
      <p class="text-xs font-black uppercase text-gray-400">Con lugar registrado</p>
      <p class="mt-1 text-2xl font-black text-emerald-600" x-text="rows.filter(r => (r.lugar || '').trim()).length"></p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
      <p class="text-xs font-black uppercase text-gray-400">Ultimo registro</p>
      <p class="mt-1 truncate text-sm font-bold text-gray-700" x-text="rows[0]?.nombres_completos || 'Sin registros'"></p>
    </div>
  </div>

  <div class="rounded-2xl bg-white shadow-sm border border-gray-100 overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-gray-100 p-4 md:flex-row md:items-center md:justify-between">
      <div class="relative w-full md:max-w-md">
        <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        <input type="search" x-model="q" placeholder="Buscar por DNI, nombre o lugar..."
               class="w-full rounded-xl border border-gray-200 py-2.5 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-[#1E3A8A]">
      </div>
      <button type="button" @click="q=''" class="text-xs font-bold text-gray-400 hover:text-gray-700">Limpiar</button>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-black uppercase text-gray-400">
          <tr>
            <th class="px-4 py-3">N</th>
            <th class="px-4 py-3">DNI</th>
            <th class="px-4 py-3">Nombres y apellidos</th>
            <th class="px-4 py-3">Lugar</th>
            <th class="px-4 py-3">Credencial</th>
            <th class="px-4 py-3 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <template x-for="(row, idx) in filteredRows()" :key="row.id">
            <tr class="hover:bg-blue-50/30">
              <td class="px-4 py-3 font-mono text-xs text-gray-400" x-text="idx + 1"></td>
              <td class="px-4 py-3 font-mono font-bold text-gray-700" x-text="row.dni"></td>
              <td class="px-4 py-3">
                <p class="font-black text-gray-900" x-text="row.nombres_completos"></p>
                <p class="text-xs text-gray-400" x-text="formatDate(row.creado_en)"></p>
              </td>
              <td class="px-4 py-3 text-gray-600" x-text="row.lugar || '-'"></td>
              <td class="px-4 py-3">
                <button type="button" @click="preview(row)" class="group flex items-center gap-3 text-left">
                  <img :src="asset(row.archivo)" class="h-14 w-20 rounded-lg border border-gray-200 object-cover shadow-sm group-hover:ring-2 group-hover:ring-blue-200" alt="">
                  <span class="text-xs font-bold text-[#1E3A8A] group-hover:underline">Vista previa</span>
                </button>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5">
                  <button type="button" @click="openPdf(row)" title="PDF"
                          class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600">
                    <i class="ti ti-file-type-pdf"></i>
                  </button>
                  <button type="button" @click="openModal(row)" title="Editar"
                          class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-blue-50 hover:text-[#1E3A8A]">
                    <i class="ti ti-pencil"></i>
                  </button>
                  <button type="button" @click="deleteRow(row)" title="Eliminar"
                          class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600">
                    <i class="ti ti-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </template>
          <tr x-show="filteredRows().length === 0">
            <td colspan="6" class="px-4 py-16 text-center text-sm font-bold text-gray-400">No hay credenciales escaneadas para mostrar.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <template x-if="modal.open">
    <div class="fixed inset-0 z-[10020] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" @click.self="closeModal()">
      <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
        <form @submit.prevent="save()" enctype="multipart/form-data">
          <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
            <div>
              <h2 class="text-lg font-black text-gray-900" x-text="form.id ? 'Editar credencial escaneada' : 'Subir credencial escaneada'"></h2>
              <p class="text-xs font-bold text-gray-400">Archivo fisico entregado en campo</p>
            </div>
            <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-700">
              <i class="ti ti-x text-xl"></i>
            </button>
          </div>

          <div class="space-y-4 p-6">
            <template x-if="form.error">
              <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700" x-text="form.error"></div>
            </template>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-black uppercase text-gray-500">DNI</label>
                <div class="relative">
                  <input type="text" x-model="form.dni" maxlength="8" inputmode="numeric"
                         @input="form.dni=form.dni.replace(/\D/g,'').slice(0,8); lookupDni()"
                         class="w-full rounded-xl border border-gray-200 px-3 py-2.5 pr-9 text-sm font-mono outline-none focus:ring-2 focus:ring-[#1E3A8A]">
                  <i x-show="form.lookup" class="ti ti-loader-2 absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-gray-400"></i>
                </div>
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-black uppercase text-gray-500">Lugar</label>
                <input type="text" x-model="form.lugar" maxlength="180" placeholder="Ej. Puerto Ocopa, Rio Negro"
                       class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#1E3A8A]">
              </div>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-black uppercase text-gray-500">Nombres y apellidos</label>
              <input type="text" x-model="form.nombres_completos"
                     class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-bold text-gray-700 outline-none">
              <p x-show="form.dniMsg" class="mt-1.5 text-xs font-bold text-amber-600" x-text="form.dniMsg"></p>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-black uppercase text-gray-500">Adjuntar credencial escaneada</label>
              <div class="grid grid-cols-1 gap-4 rounded-2xl border-2 border-dashed border-gray-200 p-4 md:grid-cols-[120px,1fr]">
                <div class="flex h-32 w-full items-center justify-center overflow-hidden rounded-xl bg-gray-50">
                  <img x-show="form.preview" :src="form.preview" class="h-full w-full object-cover" alt="">
                  <i x-show="!form.preview" class="ti ti-photo-scan text-4xl text-gray-300"></i>
                </div>
                <div class="flex flex-col justify-center">
                  <input type="file" x-ref="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                         @change="setFile($event.target.files[0])"
                         class="text-sm text-gray-500 file:mr-3 file:rounded-xl file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-black file:text-[#1E3A8A] hover:file:bg-blue-100">
                  <p class="mt-2 text-xs font-bold text-gray-400">JPG, PNG o WEBP. Maximo 8 MB.</p>
                  <p x-show="form.id" class="mt-1 text-xs text-gray-400">Si no adjuntas una nueva imagen, se conserva la actual.</p>
                </div>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50 px-6 py-4">
            <button type="button" @click="closeModal()" class="px-4 py-2.5 text-sm font-black text-gray-500 hover:text-gray-800">Cancelar</button>
            <button type="submit" :disabled="form.saving"
                    class="inline-flex items-center gap-2 rounded-xl bg-[#1E3A8A] px-5 py-2.5 text-sm font-black text-white hover:bg-blue-900 disabled:opacity-60">
              <i x-show="form.saving" class="ti ti-loader-2 animate-spin"></i>
              <i x-show="!form.saving" class="ti ti-device-floppy"></i>
              <span x-text="form.saving ? 'Guardando...' : 'Guardar credencial escaneada'"></span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <template x-if="previewModal.open">
    <div class="fixed inset-0 z-[10030] flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm" @click.self="previewModal.open=false">
      <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <div class="min-w-0">
            <h3 class="truncate font-black text-gray-900" x-text="previewModal.row?.nombres_completos"></h3>
            <p class="text-xs font-bold text-gray-400" x-text="previewModal.row?.dni + ' - ' + (previewModal.row?.lugar || '')"></p>
          </div>
          <button type="button" @click="previewModal.open=false" class="text-gray-400 hover:text-gray-700"><i class="ti ti-x text-xl"></i></button>
        </div>
        <div class="flex-1 overflow-auto bg-slate-100 p-4">
          <img :src="asset(previewModal.row?.archivo)" class="mx-auto max-h-[75vh] rounded-xl bg-white object-contain shadow-xl" alt="">
        </div>
      </div>
    </div>
  </template>

  <template x-if="pdfModal.open">
    <div class="fixed inset-0 z-[10030] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" @click.self="pdfModal.open=false">
      <div class="flex h-[94vh] w-full max-w-7xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-gradient-to-r from-slate-950 via-[#1E3A8A] to-slate-950 px-4 py-4 text-white sm:px-5">
          <div class="min-w-0">
            <h3 class="truncate font-black" x-text="pdfModal.title"></h3>
            <p class="mt-0.5 truncate text-xs font-bold text-blue-100" x-text="pdfModal.subtitle"></p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="shareWhatsApp()" :disabled="pdfModal.sharing"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-xs font-black text-white hover:bg-emerald-600 disabled:opacity-60 sm:text-sm">
              <i x-show="pdfModal.sharing" class="ti ti-loader-2 animate-spin"></i>
              <i x-show="!pdfModal.sharing" class="ti ti-brand-whatsapp"></i>
              <span x-text="pdfModal.sharing ? 'Preparando...' : 'WhatsApp'"></span>
            </button>
            <a :href="pdfModal.url + '&download=1'" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-2 text-xs font-black hover:bg-white/20 sm:text-sm">
              <i class="ti ti-download"></i> Descargar
            </a>
            <a :href="pdfModal.url" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-2 text-xs font-black hover:bg-white/20 sm:text-sm">
              <i class="ti ti-printer"></i> Imprimir
            </a>
            <button type="button" @click="pdfModal.open=false" class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 hover:bg-red-500"><i class="ti ti-x"></i></button>
          </div>
        </div>
        <div class="grid min-h-0 flex-1 grid-cols-1 bg-slate-100 lg:grid-cols-[300px,1fr]">
          <aside class="border-b border-slate-200 bg-white p-4 lg:border-b-0 lg:border-r">
            <p class="mb-3 text-xs font-black uppercase tracking-wide text-gray-400">Datos del registro</p>
            <div class="rounded-2xl bg-blue-50 p-4 text-sm text-blue-950">
              <p class="font-black leading-snug" x-text="pdfModal.row?.nombres_completos"></p>
              <p class="mt-2 font-mono text-xs font-bold text-blue-700" x-text="pdfModal.row?.dni"></p>
              <p class="mt-2 text-xs font-bold text-blue-700" x-text="pdfModal.row?.lugar || 'Sin lugar'"></p>
            </div>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold leading-relaxed text-amber-800">
              Esta vista muestra la imagen escaneada. Los botones superiores generan el PDF A4 para descargar, imprimir o compartir.
            </div>
          </aside>
          <section class="min-h-0 overflow-auto p-3 sm:p-4">
            <div class="mx-auto flex min-h-[420px] max-w-4xl items-center justify-center rounded-2xl border border-slate-200 bg-white p-3 shadow-inner sm:p-5">
              <img :src="asset(pdfModal.row?.archivo)"
                   class="max-h-[72vh] w-auto max-w-full rounded-xl object-contain shadow-xl"
                   alt="Credencial escaneada">
            </div>
          </section>
        </div>
      </div>
    </div>
  </template>
</div>

<script>
const CE_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const CE_CSRF = '<?= h(csrf_token()) ?>';
const CE_BASE = '<?= h(BASE_URL) ?>';

function credencialesEscaneadas() {
  return {
    rows: [],
    q: '',
    lookupSeq: 0,
    modal: { open: false },
    previewModal: { open: false, row: null },
    pdfModal: { open: false, url: '', title: '', subtitle: '', row: null, sharing: false, shareUrl: '' },
    form: {},

    init() {
      this.rows = CE_ROWS;
      this.resetForm();
    },
    asset(path) {
      if (!path) return '';
      return CE_BASE + '/' + String(path).replace(/^\/+/, '');
    },
    resetForm() {
      this.form = { id: 0, dni: '', nombres_completos: '', lugar: '', archivo: '', preview: '', file: null, saving: false, error: '', lookup: false, dniMsg: '' };
      if (this.$refs.file) this.$refs.file.value = '';
    },
    filteredRows() {
      const s = this.q.trim().toLowerCase();
      if (!s) return this.rows;
      return this.rows.filter(r => [r.dni, r.nombres_completos, r.lugar].join(' ').toLowerCase().includes(s));
    },
    formatDate(v) {
      if (!v) return '';
      return String(v).replace(' ', '  ');
    },
    openModal(row = null) {
      this.resetForm();
      if (row) {
        this.form = { ...this.form, id: Number(row.id), dni: row.dni || '', nombres_completos: row.nombres_completos || '', lugar: row.lugar || '', archivo: row.archivo || '', preview: this.asset(row.archivo) };
      }
      this.modal.open = true;
    },
    closeModal() {
      this.modal.open = false;
    },
    async lookupDni() {
      this.form.dniMsg = '';
      this.form.nombres_completos = '';
      if (this.form.dni.length !== 8) return;
      const seq = ++this.lookupSeq;
      this.form.lookup = true;
      try {
        const res = await fetch('credenciales-escaneadas.php?lookup_dni=1&dni=' + encodeURIComponent(this.form.dni));
        const data = await res.json();
        if (seq !== this.lookupSeq) return;
        if (data.ok) this.form.nombres_completos = data.nombre || '';
        else this.form.dniMsg = data.msg || 'No se encontro informacion.';
      } catch(e) {
        if (seq === this.lookupSeq) this.form.dniMsg = 'Error de red al consultar DNI.';
      }
      if (seq === this.lookupSeq) this.form.lookup = false;
    },
    setFile(file) {
      this.form.file = file || null;
      if (!file) return;
      this.form.preview = URL.createObjectURL(file);
    },
    async save() {
      this.form.error = '';
      if (this.form.dni.length !== 8) { this.form.error = 'Ingresa un DNI valido de 8 digitos.'; return; }
      if (!this.form.nombres_completos.trim()) { this.form.error = 'Consulta el DNI para obtener nombres y apellidos.'; return; }
      if (!this.form.lugar.trim()) { this.form.error = 'Ingresa el lugar.'; return; }
      if (!this.form.id && !this.form.file) { this.form.error = 'Adjunta la credencial escaneada.'; return; }

      this.form.saving = true;
      const fd = new FormData();
      fd.append('_csrf', CE_CSRF);
      fd.append('action', 'save');
      fd.append('id', this.form.id);
      fd.append('dni', this.form.dni);
      fd.append('nombres_completos', this.form.nombres_completos);
      fd.append('lugar', this.form.lugar);
      if (this.form.file) fd.append('archivo', this.form.file);
      try {
        const res = await fetch('credenciales-escaneadas.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { this.form.error = data.msg || 'No se pudo guardar.'; this.form.saving = false; return; }
        this.rows = data.rows || [];
        this.closeModal();
      } catch(e) {
        this.form.error = 'Error de red al guardar.';
      }
      this.form.saving = false;
    },
    preview(row) {
      this.previewModal = { open: true, row };
    },
    openPdf(row) {
      this.pdfModal = {
        open: true,
        url: 'exportar-credencial-escaneada-pdf.php?id=' + encodeURIComponent(row.id),
        title: 'Credencial escaneada - ' + row.dni,
        subtitle: row.nombres_completos + (row.lugar ? ' - ' + row.lugar : ''),
        row,
        sharing: false,
        shareUrl: '',
      };
    },
    async shareWhatsApp() {
      const row = this.pdfModal.row;
      if (!row || this.pdfModal.sharing) return;
      this.pdfModal.sharing = true;
      try {
        if (!this.pdfModal.shareUrl) {
          const res = await fetch('exportar-credencial-escaneada-pdf.php?id=' + encodeURIComponent(row.id) + '&save=1', {
            headers: { 'Accept': 'application/json' }
          });
          const data = await res.json();
          if (!data.ok || !data.url) throw new Error(data.msg || 'No se pudo preparar el PDF.');
          this.pdfModal.shareUrl = data.url;
        }
        const msg = [
          'Credencial escaneada',
          'DNI: ' + row.dni,
          'Titular: ' + row.nombres_completos,
          row.lugar ? 'Lugar: ' + row.lugar : '',
          this.pdfModal.shareUrl
        ].filter(Boolean).join('\n');
        window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener,noreferrer');
      } catch(e) {
        alert(e.message || 'No se pudo compartir por WhatsApp.');
      }
      this.pdfModal.sharing = false;
    },
    async deleteRow(row) {
      if (!confirm('Eliminar la credencial escaneada de ' + row.nombres_completos + '?')) return;
      const fd = new FormData();
      fd.append('_csrf', CE_CSRF);
      fd.append('action', 'delete');
      fd.append('id', row.id);
      try {
        const data = await (await fetch('credenciales-escaneadas.php', { method: 'POST', body: fd })).json();
        if (data.ok) this.rows = data.rows || [];
        else alert(data.msg || 'No se pudo eliminar.');
      } catch(e) {
        alert('Error de red al eliminar.');
      }
    },
  };
}
</script>

    </main>
  </div>
</body>
</html>
