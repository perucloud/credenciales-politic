<?php
// ============================================================
// media.php — Gestor de Archivos Multimedia
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_rol('editor');
require_modulo($pdo, 'media');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ── Constantes ────────────────────────────────────────────────
define('UPLOAD_DIR',  dirname(__DIR__) . '/uploads/media/');
define('MAX_IMG',     5  * 1024 * 1024);   // 5 MB
define('MAX_PDF',     20 * 1024 * 1024);   // 20 MB

require_once dirname(__DIR__) . '/includes/helpers/webp.php';

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$flash       = null;
$flash_type  = 'success';

// ── Acción: Eliminar archivo ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT ruta FROM media_files WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($file) {
                $path = UPLOAD_DIR . $file['ruta'];
                if (is_file($path)) {
                    @unlink($path);
                }
                $pdo->prepare("DELETE FROM media_files WHERE id = ?")->execute([$id]);
                log_activity($pdo, 'Eliminó archivo de media: ' . $file['ruta'], 'media');
                $flash = 'Archivo eliminado correctamente.';
            } else {
                $flash      = 'Archivo no encontrado.';
                $flash_type = 'error';
            }
        } catch (Exception $e) {
            $flash      = 'Error al eliminar: ' . htmlspecialchars($e->getMessage());
            $flash_type = 'error';
        }
    }
}

// ── Acción: Subir archivos ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $uploaded  = 0;
    $errors    = [];

    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length > 0 && empty($_FILES) && empty($_POST)) {
        $flash      = 'La solicitud supera el límite del servidor (' . ini_get('post_max_size') . '). Reduce el tamaño o sube de a un archivo.';
        $flash_type = 'error';
        goto end_upload;
    }

    $files_raw = $_FILES['archivos'] ?? null;

    $php_upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'supera el límite del servidor (' . ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_FORM_SIZE  => 'supera el límite del formulario',
        UPLOAD_ERR_PARTIAL    => 'se subió parcialmente — conexión interrumpida',
        UPLOAD_ERR_NO_FILE    => 'no se seleccionó ningún archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'el servidor no tiene directorio temporal configurado',
        UPLOAD_ERR_CANT_WRITE => 'el servidor no pudo escribir el archivo en disco',
        UPLOAD_ERR_EXTENSION  => 'bloqueado por una extensión PHP del servidor',
    ];

    if ($files_raw && is_array($files_raw['name'])) {
        $count = count($files_raw['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files_raw['error'][$i] !== UPLOAD_ERR_OK) {
                $err_code = $files_raw['error'][$i];
                $err_msg  = $php_upload_errors[$err_code] ?? "error desconocido (código $err_code)";
                $errors[] = htmlspecialchars($files_raw['name'][$i]) . ': ' . $err_msg . '.';
                continue;
            }

            $original_name = $files_raw['name'][$i];
            $tmp_path      = $files_raw['tmp_name'][$i];
            $size          = $files_raw['size'][$i];
            $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $tipo = '';

            $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowed_pdfs   = ['pdf'];

            if (in_array($ext, $allowed_images)) {
                if ($size > MAX_IMG) {
                    $errors[] = htmlspecialchars($original_name) . ': supera el límite de 5 MB para imágenes.';
                    continue;
                }
                $webp_data = img_to_webp($tmp_path, $ext);
                if ($webp_data !== false) {
                    $ext  = 'webp';
                    $tipo = 'image/webp';
                    $contenido_media = $webp_data;
                } else {
                    $tipo = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                    $contenido_media = @file_get_contents($tmp_path);
                }
            } elseif (in_array($ext, $allowed_pdfs)) {
                $tipo = 'application/pdf';
                if ($size > MAX_PDF) {
                    $errors[] = htmlspecialchars($original_name) . ': supera el límite de 20 MB para PDFs.';
                    continue;
                }
                $contenido_media = @file_get_contents($tmp_path);
            } else {
                $errors[] = htmlspecialchars($original_name) . ': tipo de archivo no permitido. Solo JPG, PNG, GIF, WebP o PDF.';
                continue;
            }

            $safe_name = uniqid('media_', true) . '.' . $ext;

            if ($contenido_media === false || strlen($contenido_media) === 0) {
                $errors[] = htmlspecialchars($original_name) . ': no se pudo leer el archivo.';
                continue;
            }
            $written_media = @file_put_contents(UPLOAD_DIR . $safe_name, $contenido_media);
            if ($written_media === false || !file_exists(UPLOAD_DIR . $safe_name)) {
                $errors[] = htmlspecialchars($original_name) . ': no se pudo guardar el archivo.';
                continue;
            }

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO media_files (nombre, ruta, tipo, tamanio, modulo, usuario_id, creado_en)
                     VALUES (?, ?, ?, ?, 'media', ?, NOW())"
                );
                $stmt->execute([
                    $original_name,
                    $safe_name,
                    $tipo,
                    strlen($contenido_media),
                    $_SESSION['admin_id'] ?? 0,
                ]);
                $uploaded++;
            } catch (Exception $e) {
                @unlink(UPLOAD_DIR . $safe_name);
                $errors[] = htmlspecialchars($original_name) . ': error al guardar en base de datos.';
            }
        }
    }

    if ($uploaded > 0) {
        log_activity($pdo, "Subió $uploaded archivo(s) a media", 'media');
    }

    if ($uploaded > 0 && empty($errors)) {
        $flash = "Se subieron $uploaded archivo(s) correctamente.";
    } elseif ($uploaded > 0 && !empty($errors)) {
        $flash      = "Se subieron $uploaded archivo(s). Errores: " . implode(' / ', $errors);
        $flash_type = 'warning';
    } else {
        $flash      = 'No se subió ningún archivo. ' . implode(' / ', $errors);
        $flash_type = 'error';
    }

    end_upload:;
}

// ── Cargar archivos de la DB ──────────────────────────────────
$archivos = [];
try {
    $stmt     = $pdo->query("SELECT * FROM media_files ORDER BY creado_en DESC");
    $archivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $archivos = [];
}

function formato_tamanio(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$public_base = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/media/';

$page_title = 'Gestor de Media';
include __DIR__ . '/layout.php';
?>

<div class="max-w-6xl mx-auto" x-data="mediaManager()">

  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
      <h1 class="text-2xl font-black text-gray-800">Gestor de Media</h1>
      <p class="text-sm text-gray-500 mt-1">
        <?= count($archivos) ?> archivo<?= count($archivos) !== 1 ? 's' : '' ?> almacenado<?= count($archivos) !== 1 ? 's' : '' ?>
      </p>
    </div>
    <button
      @click="showUpload = !showUpload"
      class="inline-flex items-center gap-2 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold
             px-5 py-2.5 rounded-xl text-sm transition-all duration-200 shadow-sm hover:shadow-md active:scale-95"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
      </svg>
      Subir archivos
    </button>
  </div>

  <?php if ($flash): ?>
  <div
    x-data="{ show: true }"
    x-show="show"
    x-init="setTimeout(() => show = false, 6000)"
    x-transition:leave="transition ease-in duration-300"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="mb-5 flex items-start gap-3 px-4 py-3 rounded-xl text-sm font-medium
      <?php
        echo match($flash_type) {
          'error'   => 'bg-red-50 text-red-700 border border-red-200',
          'warning' => 'bg-yellow-50 text-yellow-800 border border-yellow-200',
          default   => 'bg-green-50 text-green-700 border border-green-200',
        };
      ?>"
  >
    <span><?= htmlspecialchars($flash) ?></span>
    <button @click="show = false" class="ml-auto text-current opacity-50 hover:opacity-100 flex-shrink-0">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    </button>
  </div>
  <?php endif; ?>

  <div
    x-show="showUpload"
    x-transition
    class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6"
  >
    <h2 class="text-base font-bold text-gray-700 mb-4">Subir nuevos archivos</h2>
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">

      <div
        class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center cursor-pointer
               hover:border-yellow-400 hover:bg-yellow-50 transition-all duration-200"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="dragging = false; handleDrop($event)"
        :class="dragging ? 'border-yellow-400 bg-yellow-50' : ''"
        @click="$refs.fileInput.click()"
      >
        <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        <p class="text-sm font-semibold text-gray-600 mb-1">Arrastra archivos aquí o <span class="text-yellow-600">haz clic para seleccionar</span></p>
        <p class="text-xs text-gray-400">Imágenes (JPG, PNG, GIF, WebP) hasta 5 MB &bull; PDF hasta 20 MB</p>
        <p class="text-xs text-gray-300 mt-0.5">Límite del servidor: <?= ini_get('upload_max_filesize') ?> por archivo &bull; <?= ini_get('post_max_size') ?> por solicitud</p>
        <p class="text-xs text-gray-400 mt-1" x-text="selectedFiles.length > 0 ? selectedFiles.length + ' archivo(s) seleccionado(s)' : ''"></p>
      </div>

      <input type="file" name="archivos[]" multiple accept="image/*,.pdf" class="hidden" x-ref="fileInput" @change="handleFileSelect($event)">

      <template x-if="selectedFiles.length > 0">
        <div class="mt-4 space-y-2">
          <template x-for="(file, idx) in selectedFiles" :key="idx">
            <div class="flex items-center gap-3 bg-gray-50 rounded-lg px-3 py-2 text-sm">
              <span class="text-gray-700 truncate flex-1" x-text="file.name"></span>
              <span class="text-gray-400 flex-shrink-0" x-text="formatSize(file.size)"></span>
            </div>
          </template>
        </div>
      </template>

      <div class="flex justify-end mt-4">
        <button type="submit" :disabled="selectedFiles.length === 0"
                :class="selectedFiles.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-800'"
                class="inline-flex items-center gap-2 bg-[#1E3A8A] text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-all duration-200 shadow-sm">
          Subir <span x-show="selectedFiles.length > 0" x-text="'(' + selectedFiles.length + ')'"></span>
        </button>
      </div>
    </form>
  </div>

  <?php if (empty($archivos)): ?>
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
    <h3 class="text-base font-bold text-gray-400 mb-1">Sin archivos todavía</h3>
    <p class="text-sm text-gray-300">Sube tu primera imagen o PDF con el botón de arriba.</p>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
    <?php foreach ($archivos as $archivo):
      $es_imagen = str_starts_with($archivo['tipo'] ?? '', 'image/');
      $es_pdf    = ($archivo['tipo'] ?? '') === 'application/pdf';
      $public_url = $public_base . htmlspecialchars($archivo['ruta'], ENT_QUOTES);
      $nombre_seg = htmlspecialchars($archivo['nombre'], ENT_QUOTES);
      $fecha      = date('d/m/Y', strtotime($archivo['creado_en'] ?? 'now'));
    ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden group flex flex-col">
      <div class="relative bg-gray-50 aspect-square overflow-hidden flex items-center justify-center">
        <?php if ($es_imagen): ?>
        <img src="<?= $public_url ?>" alt="<?= $nombre_seg ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" loading="lazy">
        <?php elseif ($es_pdf): ?>
        <div class="flex flex-col items-center justify-center text-red-400 p-4">
          <span class="text-xs font-bold text-red-400 mt-1">PDF</span>
        </div>
        <?php else: ?>
        <div class="flex flex-col items-center justify-center text-gray-300 p-4">
          <span class="text-xs font-bold mt-1"><?= strtoupper(htmlspecialchars(pathinfo($archivo['ruta'], PATHINFO_EXTENSION))) ?></span>
        </div>
        <?php endif; ?>

        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-all duration-200 flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100">
          <button type="button" title="Copiar URL" @click.stop="copyUrl('<?= addslashes($public_url) ?>')"
                  class="bg-white/90 hover:bg-white text-gray-700 rounded-lg p-2 shadow transition-all duration-150 active:scale-90">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
          </button>
          <form method="POST" action="" onsubmit="return confirm('¿Eliminar este archivo? Esta acción no se puede deshacer.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$archivo['id'] ?>">
            <button type="submit" title="Eliminar" class="bg-red-500/90 hover:bg-red-600 text-white rounded-lg p-2 shadow transition-all duration-150 active:scale-90">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
          </form>
        </div>
      </div>

      <div class="p-3 flex-1 flex flex-col justify-between">
        <p class="text-xs font-semibold text-gray-700 truncate mb-1" title="<?= $nombre_seg ?>"><?= $nombre_seg ?></p>
        <div class="flex items-center justify-between mt-1">
          <span class="text-xs text-gray-400"><?= htmlspecialchars(formato_tamanio((int)$archivo['tamanio'])) ?></span>
          <span class="text-xs text-gray-400"><?= $fecha ?></span>
        </div>
        <button type="button" @click="copyUrl('<?= addslashes($public_url) ?>')"
                class="mt-2 w-full text-xs text-center text-gray-500 hover:text-gray-800 border border-gray-200 hover:border-gray-400 rounded-lg py-1.5 transition-all duration-150 flex items-center justify-center gap-1 sm:hidden">
          Copiar URL
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div x-show="toastVisible" x-transition
       class="fixed bottom-6 right-6 bg-gray-900 text-white text-sm font-medium px-4 py-2.5 rounded-xl shadow-lg z-50 flex items-center gap-2"
       style="display:none">
    URL copiada al portapapeles
  </div>

</div>

<script>
function mediaManager() {
  return {
    showUpload:    false,
    dragging:      false,
    selectedFiles: [],
    toastVisible:  false,
    toastTimer:    null,

    handleFileSelect(event) {
      this.selectedFiles = Array.from(event.target.files);
    },

    handleDrop(event) {
      const dt    = event.dataTransfer;
      const files = Array.from(dt.files);
      const dataTransfer = new DataTransfer();
      files.forEach(f => dataTransfer.items.add(f));
      this.$refs.fileInput.files = dataTransfer.files;
      this.selectedFiles = files;
    },

    formatSize(bytes) {
      if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
      if (bytes >= 1024)    return (bytes / 1024).toFixed(1) + ' KB';
      return bytes + ' B';
    },

    copyUrl(url) {
      navigator.clipboard.writeText(url).then(() => {
        this.toastVisible = true;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { this.toastVisible = false; }, 2500);
      }).catch(() => {
        const el = document.createElement('textarea');
        el.value = url;
        el.style.position = 'fixed';
        el.style.opacity  = '0';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        this.toastVisible = true;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { this.toastVisible = false; }, 2500);
      });
    },
  };
}
</script>

    </main>
  </div>
</body>
</html>
