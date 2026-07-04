<?php
// ============================================================
// _media-picker.php - Modal reutilizable de Biblioteca de Medios
// Requiere $pdo y BASE_URL. JS: openMediaPicker(callback, type)
// type: image | pdf | all
// ============================================================

$_mp_files = [];
try {
    $_mp_stmt = $pdo->query("SELECT id, nombre, ruta, tipo, tamanio FROM media_files ORDER BY creado_en DESC");
    foreach ($_mp_stmt->fetchAll() as $_f) {
        $tipo = (string)($_f['tipo'] ?? '');
        $_mp_files[] = [
            'id'     => (int)$_f['id'],
            'nombre' => (string)$_f['nombre'],
            'url'    => BASE_URL . '/uploads/media/' . $_f['ruta'],
            'tipo'   => $tipo,
            'es_img' => str_starts_with($tipo, 'image/'),
            'es_pdf' => $tipo === 'application/pdf',
        ];
    }
} catch (Exception $_e) {
    $_mp_files = [];
}
$_mp_json = json_encode($_mp_files, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>

<script>
function mediaPicker() {
  return {
    isOpen: false,
    search: '',
    typeFilter: 'image',
    callback: null,
    selected: null,
    loading: false,
    error: '',
    copiedId: null,
    copiedTimer: null,
    files: <?= $_mp_json ?: '[]' ?>,

    init() {
      window.addEventListener('focus', () => {
        if (this.isOpen) this.refreshFiles();
      });
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden && this.isOpen) this.refreshFiles();
      });
    },

    get filtered() {
      let result = this.files;
      if (this.typeFilter === 'image') result = result.filter(file => file.es_img);
      if (this.typeFilter === 'pdf') result = result.filter(file => file.es_pdf);
      const term = this.search.trim().toLowerCase();
      if (term) result = result.filter(file => file.nombre.toLowerCase().includes(term));
      return result;
    },

    async refreshFiles() {
      this.loading = true;
      this.error = '';
      try {
        const response = await fetch('media-picker-data.php?t=' + Date.now(), {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo cargar la biblioteca.');
        this.files = Array.isArray(data.files) ? data.files : [];
      } catch (err) {
        this.error = err.message || 'No se pudo cargar la biblioteca.';
      } finally {
        this.loading = false;
      }
    },

    openPicker(callback, type = 'image') {
      this.callback = callback;
      this.typeFilter = ['image', 'pdf', 'all'].includes(type) ? type : 'image';
      this.search = '';
      this.selected = null;
      this.isOpen = true;
      this.refreshFiles();
    },

    select(file) {
      this.selected = file;
      if (this.callback) this.callback(file.url, file);
      this.close();
    },

    async copyUrl(file) {
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(file.url);
        } else {
          const input = document.createElement('textarea');
          input.value = file.url;
          input.style.position = 'fixed';
          input.style.opacity = '0';
          document.body.appendChild(input);
          input.select();
          document.execCommand('copy');
          document.body.removeChild(input);
        }
        this.copiedId = file.id;
        clearTimeout(this.copiedTimer);
        this.copiedTimer = setTimeout(() => { this.copiedId = null; }, 1800);
      } catch (err) {
        this.error = 'No se pudo copiar la URL.';
      }
    },

    close() {
      this.isOpen = false;
      this.callback = null;
      this.selected = null;
      this.copiedId = null;
    }
  };
}

window.openMediaPicker = function(callback, type = 'image') {
  const el = document.getElementById('media-picker-modal');
  if (!el || !window.Alpine) return;
  Alpine.$data(el).openPicker(callback, type);
};
</script>

<div id="media-picker-modal"
     x-data="mediaPicker()"
     x-init="init()"
     x-show="isOpen"
     x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-[99999] flex items-center justify-center px-4"
     style="display:none"
     x-transition>

  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="close()"></div>

  <div class="relative bg-white rounded-2xl shadow-2xl w-full flex flex-col overflow-hidden"
       style="max-width:860px; max-height:85vh;"
       x-transition>

    <div class="flex items-center gap-4 px-6 py-4 border-b border-gray-100 flex-shrink-0">
      <div class="flex items-center gap-2 flex-1 min-w-0">
        <h3 class="font-black text-gray-900 text-base truncate">Biblioteca de Medios</h3>
        <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full flex-shrink-0"
              x-text="loading ? 'Actualizando...' : filtered.length + ' archivo' + (filtered.length !== 1 ? 's' : '')"></span>
      </div>

      <div class="flex bg-gray-100 rounded-xl p-1 gap-1 flex-shrink-0">
        <button type="button" @click="typeFilter='image'"
                :class="typeFilter==='image' ? 'bg-white shadow text-indigo-600 font-bold' : 'text-gray-500'"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all">Imagenes</button>
        <button type="button" @click="typeFilter='pdf'"
                :class="typeFilter==='pdf' ? 'bg-white shadow text-red-600 font-bold' : 'text-gray-500'"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all">PDF</button>
        <button type="button" @click="typeFilter='all'"
                :class="typeFilter==='all' ? 'bg-white shadow text-gray-700 font-bold' : 'text-gray-500'"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all">Todos</button>
      </div>

      <div class="relative flex-shrink-0">
        <input type="text" x-model="search" placeholder="Buscar archivo..."
               class="pl-4 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-400 outline-none w-44">
      </div>

      <button type="button" @click="refreshFiles()"
              class="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-gray-100 text-gray-400 hover:text-indigo-600 transition-colors flex-shrink-0"
              title="Actualizar biblioteca">
        <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
      </button>

      <button type="button" @click="close()" class="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-colors flex-shrink-0">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="flex-1 overflow-y-auto p-5">
      <div x-show="error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700" x-text="error"></div>

      <div x-show="loading && files.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
        <p class="text-gray-400 font-semibold text-sm">Actualizando biblioteca...</p>
      </div>

      <div x-show="!loading && filtered.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
        <p class="text-gray-400 font-semibold text-sm">No hay archivos disponibles</p>
        <a href="media.php" target="_blank" class="text-indigo-500 text-xs font-bold hover:underline mt-1">
          Ir a Archivos y Media para subir
        </a>
      </div>

      <div x-show="filtered.length > 0" class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <template x-for="file in filtered" :key="file.id">
          <div class="group relative cursor-pointer rounded-xl overflow-hidden border-2 border-transparent hover:border-indigo-400 transition-all duration-150 bg-gray-50 text-left"
               @click="select(file)"
               :class="selected && selected.id === file.id ? 'border-indigo-500 ring-2 ring-indigo-300' : ''">
            <template x-if="file.es_img">
              <div class="aspect-square overflow-hidden">
                <img :src="file.url" :alt="file.nombre" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
              </div>
            </template>

            <template x-if="file.es_pdf">
              <div class="aspect-square flex flex-col items-center justify-center bg-red-50">
                <span class="text-xs font-black text-red-400 mt-1">PDF</span>
              </div>
            </template>

            <div class="absolute inset-0 bg-indigo-600/0 group-hover:bg-indigo-600/20 transition-all duration-150 flex items-center justify-center">
              <div class="bg-white rounded-full p-1.5 opacity-0 group-hover:opacity-100 transition-opacity shadow">
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
              </div>
            </div>

            <button type="button" @click.stop="copyUrl(file)"
                    class="absolute right-2 top-2 w-8 h-8 rounded-lg bg-white/95 text-gray-500 hover:text-indigo-600 shadow opacity-0 group-hover:opacity-100 transition-all flex items-center justify-center"
                    title="Copiar URL">
              <svg x-show="copiedId !== file.id" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
              </svg>
              <svg x-show="copiedId === file.id" class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
              </svg>
            </button>

            <div class="px-2 py-1.5 bg-white border-t border-gray-100">
              <p class="text-[10px] text-gray-600 font-medium truncate" x-text="file.nombre"></p>
              <button type="button" @click.stop="copyUrl(file)" class="mt-1 text-[10px] font-bold text-indigo-500 hover:text-indigo-700"
                      x-text="copiedId === file.id ? 'URL copiada' : 'Copiar URL'"></button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between flex-shrink-0">
      <a href="media.php" target="_blank" class="text-xs text-indigo-500 hover:text-indigo-700 font-semibold flex items-center gap-1 transition-colors">
        Subir nuevo archivo
      </a>
      <button type="button" @click="close()" class="text-xs text-gray-400 hover:text-gray-600 font-semibold">
        Cancelar
      </button>
    </div>
  </div>
</div>
