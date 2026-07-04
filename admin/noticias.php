<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_rol('editor');
require_modulo($pdo, 'noticias');

$page_title = 'Noticias';

$flash = null;
$flash_type = 'success';
if (isset($_GET['msg'])) {
    $messages = [
        'noticia_creada'      => ['success', 'Noticia creada correctamente.'],
        'noticia_actualizada' => ['success', 'Noticia actualizada correctamente.'],
        'noticia_eliminada'   => ['success', 'Noticia eliminada correctamente.'],
        'noticia_error'       => ['error', 'No se pudo completar la acción. Revisa los datos.'],
    ];
    if (isset($messages[$_GET['msg']])) {
        [$flash_type, $flash] = $messages[$_GET['msg']];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'guardar_noticia') {
    csrf_verify();

    $id        = (int)($_POST['id'] ?? 0);
    $titulo    = trim($_POST['titulo'] ?? '');
    $contenido = trim($_POST['contenido'] ?? '');
    $imagen    = trim($_POST['imagen'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'General') ?: 'General';
    $estado    = in_array($_POST['estado'] ?? '', ['publicado', 'borrador'], true) ? $_POST['estado'] : 'borrador';
    $fecha     = trim($_POST['fecha'] ?? '') ?: date('Y-m-d H:i:s');

    if ($titulo === '') {
        header('Location: noticias.php?msg=noticia_error'); exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE noticias SET titulo=?, contenido=?, imagen=?, categoria=?, estado=?, fecha=? WHERE id=?")
                ->execute([$titulo, $contenido, $imagen, $categoria, $estado, $fecha, $id]);
            log_activity($pdo, 'Actualizó noticia: ' . $titulo, 'noticias');
            $msg = 'noticia_actualizada';
        } else {
            $pdo->prepare("INSERT INTO noticias (titulo, contenido, imagen, categoria, estado, fecha) VALUES (?,?,?,?,?,?)")
                ->execute([$titulo, $contenido, $imagen, $categoria, $estado, $fecha]);
            log_activity($pdo, 'Creó noticia: ' . $titulo, 'noticias');
            $msg = 'noticia_creada';
        }
        header('Location: noticias.php?msg=' . $msg); exit;
    } catch (Exception $e) {
        header('Location: noticias.php?msg=noticia_error'); exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'eliminar_noticia') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM noticias WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Eliminó noticia #' . $id, 'noticias');
        header('Location: noticias.php?msg=noticia_eliminada'); exit;
    } catch (Exception $e) {
        header('Location: noticias.php?msg=noticia_error'); exit;
    }
}

$noticias = $pdo->query("SELECT * FROM noticias ORDER BY fecha DESC")->fetchAll();

include __DIR__ . '/layout.php';
?>

<?php if ($flash): ?>
<div class="mb-5 rounded-2xl px-5 py-4 text-sm font-bold border <?= $flash_type === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div x-data="noticiasModulo()" x-cloak>

  <div class="flex items-center justify-between mb-5">
    <h2 class="text-lg font-black text-gray-800">Noticias</h2>
    <button @click="abrirNuevo()" class="bg-[#049CD4] text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-[#028FB7] transition-all">
      + Nueva noticia
    </button>
  </div>

  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold tracking-wide">
        <tr>
          <th class="text-left px-5 py-3">Título</th>
          <th class="text-left px-5 py-3">Categoría</th>
          <th class="text-left px-5 py-3">Fecha</th>
          <th class="text-left px-5 py-3">Estado</th>
          <th class="text-right px-5 py-3">Acciones</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($noticias as $n): ?>
        <tr>
          <td class="px-5 py-3 font-semibold text-gray-700"><?= htmlspecialchars($n['titulo']) ?></td>
          <td class="px-5 py-3 text-gray-500"><?= htmlspecialchars($n['categoria']) ?></td>
          <td class="px-5 py-3 text-gray-500"><?= htmlspecialchars(date('d/m/Y', strtotime($n['fecha']))) ?></td>
          <td class="px-5 py-3">
            <?= $n['estado'] === 'publicado'
                  ? '<span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded-full">Publicado</span>'
                  : '<span class="bg-gray-100 text-gray-500 text-xs font-bold px-2 py-0.5 rounded-full">Borrador</span>' ?>
          </td>
          <td class="px-5 py-3 text-right space-x-2">
            <button @click='abrirEditar(<?= json_encode([
                "id" => $n["id"], "titulo" => $n["titulo"], "contenido" => $n["contenido"],
                "imagen" => $n["imagen"], "categoria" => $n["categoria"], "estado" => $n["estado"],
                "fecha" => date("Y-m-d\TH:i", strtotime($n["fecha"])),
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-blue-600 hover:underline text-xs font-bold">Editar</button>
            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar esta noticia?');">
              <input type="hidden" name="action" value="eliminar_noticia">
              <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button type="submit" class="text-red-500 hover:underline text-xs font-bold">Eliminar</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($noticias)): ?>
        <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">Sin noticias todavía.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Modal crear/editar -->
  <div x-show="modalAbierto" x-transition class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 py-10">
    <div @click.outside="modalAbierto = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
      <div class="bg-[#049CD4] px-6 py-4">
        <h3 class="text-white font-black text-sm" x-text="form.id ? 'Editar noticia' : 'Nueva noticia'"></h3>
      </div>
      <form method="POST" class="p-6 space-y-4">
        <input type="hidden" name="action" value="guardar_noticia">
        <input type="hidden" name="id" x-model="form.id">

        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Título</label>
          <input type="text" name="titulo" x-model="form.titulo" required class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Contenido</label>
          <textarea name="contenido" x-model="form.contenido" rows="4" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm resize-none"></textarea>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Imagen (URL)</label>
          <div class="flex gap-2">
            <input type="text" name="imagen" x-model="form.imagen" class="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-2.5 text-sm font-mono">
            <button type="button" @click="openMediaPicker((picked) => { form.imagen = picked }, 'image')" class="px-4 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold border border-indigo-100 flex-shrink-0">Media</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Categoría</label>
            <input type="text" name="categoria" x-model="form.categoria" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Fecha</label>
            <input type="datetime-local" name="fecha" x-model="form.fecha" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Estado</label>
          <select name="estado" x-model="form.estado" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
            <option value="borrador">Borrador</option>
            <option value="publicado">Publicado</option>
          </select>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="modalAbierto = false" class="flex-1 py-2.5 rounded-xl border border-gray-200 text-gray-500 font-bold text-sm">Cancelar</button>
          <button type="submit" class="flex-1 py-2.5 rounded-xl bg-[#049CD4] text-white font-bold text-sm hover:bg-[#028FB7]">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function noticiasModulo() {
  return {
    modalAbierto: false,
    form: { id: '', titulo: '', contenido: '', imagen: '', categoria: 'General', estado: 'borrador', fecha: '' },
    abrirNuevo() {
      const now = new Date();
      const localIso = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      this.form = { id: '', titulo: '', contenido: '', imagen: '', categoria: 'General', estado: 'borrador', fecha: localIso };
      this.modalAbierto = true;
    },
    abrirEditar(n) {
      this.form = { id: n.id, titulo: n.titulo, contenido: n.contenido || '', imagen: n.imagen || '', categoria: n.categoria || 'General', estado: n.estado, fecha: n.fecha };
      this.modalAbierto = true;
    }
  };
}
</script>

<?php include __DIR__ . '/_media-picker.php'; ?>

    </main>
  </div>
</body>
</html>
