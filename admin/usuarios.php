<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';

require_login();
require_rol('superadmin');

$page_title = 'Usuarios';

$modulos_disponibles = [
    'simpatizantes'          => 'Simpatizantes',
    'personeros'              => 'Personeros',
    'credenciales_modulo'      => 'Credenciales',
    'credenciales_escaneadas'  => 'Credenciales escaneadas',
];

$flash = null;
$flash_type = 'success';
if (isset($_GET['msg'])) {
    $messages = [
        'usuario_creado'       => ['success', 'Usuario creado correctamente.'],
        'usuario_actualizado'  => ['success', 'Usuario actualizado correctamente.'],
        'usuario_eliminado'    => ['success', 'Usuario eliminado correctamente.'],
        'usuario_error'        => ['error', 'No se pudo completar la acción. Revisa los datos.'],
        'usuario_duplicado'    => ['error', 'Ya existe un usuario con ese correo.'],
        'usuario_propio'       => ['error', 'No puedes eliminar tu propia cuenta.'],
    ];
    if (isset($messages[$_GET['msg']])) {
        [$flash_type, $flash] = $messages[$_GET['msg']];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'guardar_usuario') {
    csrf_verify();

    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $rol    = in_array($_POST['rol'] ?? '', ['superadmin', 'admin', 'editor'], true) ? $_POST['rol'] : 'editor';
    $activo = !empty($_POST['activo']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');
    $modulos  = array_values(array_intersect((array)($_POST['modulos'] ?? []), array_keys($modulos_disponibles)));

    if ($nombre === '' || $email === '') {
        header('Location: usuarios.php?msg=usuario_error'); exit;
    }

    try {
        $dup = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn()) {
            header('Location: usuarios.php?msg=usuario_duplicado'); exit;
        }

        if ($id > 0) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, activo=?, password=? WHERE id=?")
                    ->execute([$nombre, $email, $rol, $activo, $hash, $id]);
            } else {
                $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, activo=? WHERE id=?")
                    ->execute([$nombre, $email, $rol, $activo, $id]);
            }
            log_activity($pdo, 'Actualizó usuario: ' . $nombre, 'usuarios');
            $msg = 'usuario_actualizado';
        } else {
            if ($password === '') {
                header('Location: usuarios.php?msg=usuario_error'); exit;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?,?,?,?,?)")
                ->execute([$nombre, $email, $hash, $rol, $activo]);
            $id = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Creó usuario: ' . $nombre, 'usuarios');
            $msg = 'usuario_creado';
        }

        $pdo->prepare("DELETE FROM usuario_permisos_modulo WHERE usuario_id = ?")->execute([$id]);
        if ($rol === 'admin' && $modulos) {
            $ins = $pdo->prepare("INSERT INTO usuario_permisos_modulo (usuario_id, modulo) VALUES (?, ?)");
            foreach ($modulos as $modulo) {
                $ins->execute([$id, $modulo]);
            }
        }

        header('Location: usuarios.php?msg=' . $msg); exit;
    } catch (Exception $e) {
        header('Location: usuarios.php?msg=usuario_error'); exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'eliminar_usuario') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)($_SESSION['admin_id'] ?? 0)) {
        header('Location: usuarios.php?msg=usuario_propio'); exit;
    }

    try {
        $r = $pdo->prepare("SELECT nombre FROM usuarios WHERE id=?");
        $r->execute([$id]);
        $nombre = $r->fetchColumn();
        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Eliminó usuario: ' . $nombre, 'usuarios');
        header('Location: usuarios.php?msg=usuario_eliminado'); exit;
    } catch (Exception $e) {
        header('Location: usuarios.php?msg=usuario_error'); exit;
    }
}

$usuarios = $pdo->query("SELECT id, nombre, email, rol, activo, ultimo_acceso FROM usuarios ORDER BY rol DESC, nombre ASC")->fetchAll();

$permisos_por_usuario = [];
$permisos_rows = $pdo->query("SELECT usuario_id, modulo FROM usuario_permisos_modulo")->fetchAll();
foreach ($permisos_rows as $row) {
    $permisos_por_usuario[$row['usuario_id']][] = $row['modulo'];
}

include __DIR__ . '/layout.php';
?>

<?php if ($flash): ?>
<div class="mb-5 rounded-2xl px-5 py-4 text-sm font-bold border <?= $flash_type === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div x-data="usuariosModulo()" x-cloak>

  <div class="flex items-center justify-between mb-5">
    <h2 class="text-lg font-black text-gray-800">Usuarios y permisos</h2>
    <button @click="abrirNuevo()" class="bg-[#1E3A8A] text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-blue-900 transition-all">
      + Nuevo usuario
    </button>
  </div>

  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold tracking-wide">
        <tr>
          <th class="text-left px-5 py-3">Nombre</th>
          <th class="text-left px-5 py-3">Correo</th>
          <th class="text-left px-5 py-3">Rol</th>
          <th class="text-left px-5 py-3">Módulos asignados</th>
          <th class="text-left px-5 py-3">Estado</th>
          <th class="text-right px-5 py-3">Acciones</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($usuarios as $u): ?>
        <tr>
          <td class="px-5 py-3 font-semibold text-gray-700"><?= htmlspecialchars($u['nombre']) ?></td>
          <td class="px-5 py-3 text-gray-500"><?= htmlspecialchars($u['email']) ?></td>
          <td class="px-5 py-3"><?= rol_badge($u['rol']) ?></td>
          <td class="px-5 py-3 text-gray-500 text-xs">
            <?php if ($u['rol'] === 'admin'): ?>
              <?= !empty($permisos_por_usuario[$u['id']])
                    ? htmlspecialchars(implode(', ', array_map(fn($m) => $modulos_disponibles[$m] ?? $m, $permisos_por_usuario[$u['id']])))
                    : '<span class="text-red-400">Sin módulos asignados</span>' ?>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3">
            <?= $u['activo']
                  ? '<span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded-full">Activo</span>'
                  : '<span class="bg-gray-100 text-gray-500 text-xs font-bold px-2 py-0.5 rounded-full">Inactivo</span>' ?>
          </td>
          <td class="px-5 py-3 text-right space-x-2">
            <button @click='abrirEditar(<?= json_encode([
                "id" => $u["id"], "nombre" => $u["nombre"], "email" => $u["email"],
                "rol" => $u["rol"], "activo" => (int)$u["activo"],
                "modulos" => $permisos_por_usuario[$u["id"]] ?? [],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-blue-600 hover:underline text-xs font-bold">Editar</button>
            <?php if ((int)$u['id'] !== (int)($_SESSION['admin_id'] ?? 0)): ?>
            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este usuario?');">
              <input type="hidden" name="action" value="eliminar_usuario">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="text-red-500 hover:underline text-xs font-bold">Eliminar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Modal crear/editar -->
  <div x-show="modalAbierto" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
    <div @click.outside="modalAbierto = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
      <div class="bg-[#1E3A8A] px-6 py-4">
        <h3 class="text-white font-black text-sm" x-text="form.id ? 'Editar usuario' : 'Nuevo usuario'"></h3>
      </div>
      <form method="POST" class="p-6 space-y-4">
        <input type="hidden" name="action" value="guardar_usuario">
        <input type="hidden" name="id" x-model="form.id">

        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nombre</label>
          <input type="text" name="nombre" x-model="form.nombre" required class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Correo</label>
          <input type="email" name="email" x-model="form.email" required class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">
            Contraseña <span class="normal-case font-normal text-gray-400" x-text="form.id ? '(dejar en blanco para no cambiar)' : ''"></span>
          </label>
          <input type="password" name="password" x-model="form.password" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Rol</label>
          <select name="rol" x-model="form.rol" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
            <option value="editor">Editor</option>
            <option value="admin">Admin (módulos asignados)</option>
            <option value="superadmin">Superadmin (control total)</option>
          </select>
        </div>

        <div x-show="form.rol === 'admin'" class="border border-blue-100 bg-blue-50 rounded-xl p-3">
          <p class="text-xs font-black text-blue-700 uppercase mb-2">Módulos permitidos</p>
          <div class="space-y-1.5">
            <?php foreach ($modulos_disponibles as $clave => $etiqueta): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="modulos[]" value="<?= htmlspecialchars($clave) ?>"
                     :checked="form.modulos.includes('<?= htmlspecialchars($clave) ?>')">
              <?= htmlspecialchars($etiqueta) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <label class="flex items-center gap-2">
          <input type="checkbox" name="activo" value="1" x-model="form.activo">
          <span class="text-sm font-semibold text-gray-600">Usuario activo</span>
        </label>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="modalAbierto = false" class="flex-1 py-2.5 rounded-xl border border-gray-200 text-gray-500 font-bold text-sm">Cancelar</button>
          <button type="submit" class="flex-1 py-2.5 rounded-xl bg-[#1E3A8A] text-white font-bold text-sm hover:bg-blue-900">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function usuariosModulo() {
  return {
    modalAbierto: false,
    form: { id: '', nombre: '', email: '', password: '', rol: 'editor', activo: true, modulos: [] },
    abrirNuevo() {
      this.form = { id: '', nombre: '', email: '', password: '', rol: 'editor', activo: true, modulos: [] };
      this.modalAbierto = true;
    },
    abrirEditar(u) {
      this.form = { id: u.id, nombre: u.nombre, email: u.email, password: '', rol: u.rol, activo: !!u.activo, modulos: u.modulos || [] };
      this.modalAbierto = true;
    }
  };
}
</script>

    </main>
  </div>
</body>
</html>
