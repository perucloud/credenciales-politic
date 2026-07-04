<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/../includes/helpers/config.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php'); exit;
}

try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS remember_token VARCHAR(64) NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL,
    token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token), INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}

$login_logo     = '/assets/img/logos/logorp.webp';
$login_subtitle = 'Panel Administrativo';
$login_hero_img = '';
$brand_name     = 'Sistema de Gestión de Credenciales';
try {
    $cfg_login = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('login_logo','login_subtitle','login_hero_img','site_brand_name')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty(trim($cfg_login['login_logo']     ?? ''))) $login_logo     = trim($cfg_login['login_logo']);
    if (!empty(trim($cfg_login['login_subtitle'] ?? ''))) $login_subtitle = trim($cfg_login['login_subtitle']);
    if (!empty(trim($cfg_login['login_hero_img'] ?? ''))) $login_hero_img = trim($cfg_login['login_hero_img']);
    if (!empty(trim($cfg_login['site_brand_name'] ?? ''))) $brand_name     = trim($cfg_login['site_brand_name']);
} catch (Exception $e) {}

function login_img(string $p): string {
    if (preg_match('#^https?://#', $p)) return $p;
    return BASE_URL . '/' . ltrim($p, '/');
}

// Auto-login cookie
if (empty($_SESSION['admin_id']) && !empty($_COOKIE['remember_admin'])) {
    $rt = $_COOKIE['remember_admin'];
    if (strlen($rt) === 64 && ctype_alnum($rt)) {
        try {
            $s = $pdo->prepare("SELECT id,nombre,rol,activo FROM usuarios WHERE remember_token=? LIMIT 1");
            $s->execute([$rt]);
            $au = $s->fetch();
            if ($au && $au['activo']) {
                $_SESSION['admin_id']=$au['id']; $_SESSION['admin_nombre']=$au['nombre'];
                $_SESSION['admin_rol']=$au['rol'];
                session_regenerate_id(true);
                header('Location: dashboard.php'); exit;
            }
        } catch (Exception $e) {}
    }
    setcookie('remember_admin','',time()-3600,'/',false,false,true);
}

// CAPTCHA
function gen_captcha(): array {
    $a=random_int(2,12); $b=random_int(1,10);
    $op=random_int(0,1)?'+':'-';
    return ['q'=>"$a $op $b",'r'=>$op==='+'?$a+$b:$a-$b];
}
if (empty($_SESSION['captcha_result'])||($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    $c=gen_captcha(); $_SESSION['captcha_result']=$c['r']; $_SESSION['captcha_q']=$c['q'];
}

$error=''; $shake=false;

if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    csrf_verify();
    $email=trim($_POST['email']??'');
    $password=trim($_POST['password']??'');
    $remember=!empty($_POST['remember_me']);
    $captcha=(int)trim($_POST['captcha']??'');

    if ($captcha!==(int)$_SESSION['captcha_result']) {
        $error='Respuesta de seguridad incorrecta.'; $shake=true;
        $c=gen_captcha(); $_SESSION['captcha_result']=$c['r']; $_SESSION['captcha_q']=$c['q'];
    } elseif ($email&&$password) {
        $stmt=$pdo->prepare("SELECT id,nombre,password,rol,activo FROM usuarios WHERE email=? LIMIT 1");
        $stmt->execute([$email]); $user=$stmt->fetch();
        if ($user&&password_verify($password,$user['password'])) {
            if (!$user['activo']) { $error='Tu cuenta está desactivada.'; $shake=true; }
            else {
                $_SESSION['admin_id']=$user['id']; $_SESSION['admin_nombre']=$user['nombre'];
                $_SESSION['admin_rol']=$user['rol'];
                session_regenerate_id(true);
                if ($remember) {
                    $tok=bin2hex(random_bytes(32));
                    try { $pdo->prepare("UPDATE usuarios SET remember_token=? WHERE id=?")->execute([$tok,$user['id']]); } catch(Exception $e){}
                    setcookie('remember_admin',$tok,time()+60*60*24*30,'/',false,false,true);
                }
                try { $pdo->prepare("UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?")->execute([$user['id']]); } catch(Exception $e){}
                header('Location: dashboard.php'); exit;
            }
        } else { $error='Correo o contraseña incorrectos.'; $shake=true;
            $c=gen_captcha(); $_SESSION['captcha_result']=$c['r']; $_SESSION['captcha_q']=$c['q'];
        }
    } else { $error='Completa todos los campos.'; $shake=true; }
}

$captcha_q=$_SESSION['captcha_q']??'? + ?';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso — <?= htmlspecialchars($brand_name) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; margin: 0; overflow: hidden; }

    /* ── Fondo animado ──────────────────────────────────────── */
    .bg-animated {
      background: linear-gradient(135deg, #020c1f 0%, #0a1628 30%, #0f2057 60%, #1a3a8a 100%);
      background-size: 400% 400%;
      animation: bgShift 12s ease infinite;
    }
    @keyframes bgShift {
      0%,100% { background-position: 0% 50%; }
      50%      { background-position: 100% 50%; }
    }

    /* ── Partículas flotantes ───────────────────────────────── */
    .particle {
      position: absolute; border-radius: 50%;
      background: rgba(250,204,21,0.08);
      animation: float linear infinite;
      pointer-events: none;
    }
    @keyframes float {
      0%   { transform: translateY(110vh) scale(0); opacity: 0; }
      10%  { opacity: 1; }
      90%  { opacity: .6; }
      100% { transform: translateY(-10vh) scale(1.2); opacity: 0; }
    }
    .ring {
      position: absolute; border-radius: 50%;
      border: 1px solid rgba(56,189,248,0.12);
      animation: pulse-ring 6s ease-in-out infinite;
      pointer-events: none;
    }
    @keyframes pulse-ring {
      0%,100% { transform: scale(1);   opacity: .4; }
      50%      { transform: scale(1.08); opacity: .1; }
    }

    /* ── Card entrada ───────────────────────────────────────── */
    .login-card {
      animation: cardIn .7s cubic-bezier(.34,1.56,.64,1) both;
    }
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(40px) scale(.95); }
      to   { opacity: 1; transform: translateY(0)    scale(1); }
    }

    /* ── Shake error ────────────────────────────────────────── */
    .shake { animation: shake .45s cubic-bezier(.36,.07,.19,.97) both; }
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      15%     { transform: translateX(-8px); }
      30%     { transform: translateX(8px); }
      45%     { transform: translateX(-6px); }
      60%     { transform: translateX(6px); }
      75%     { transform: translateX(-3px); }
      90%     { transform: translateX(3px); }
    }

    /* ── Inputs ─────────────────────────────────────────────── */
    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      width: 16px; height: 16px; color: #94a3b8;
      transition: color .2s;
      pointer-events: none;
    }
    .form-input {
      width: 100%; padding: 13px 14px 13px 42px;
      background: #f8faff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      font-size: 14px; color: #1e293b;
      outline: none;
      transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .form-input::placeholder { color: #94a3b8; }
    .form-input:focus {
      border-color: #1E3A8A;
      background: #fff;
      box-shadow: 0 0 0 4px rgba(30,58,138,.1);
    }
    .input-wrap:focus-within .input-icon { color: #1E3A8A; }

    /* ── Botón shimmer ──────────────────────────────────────── */
    .btn-primary {
      position: relative; overflow: hidden;
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, #1E3A8A 0%, #2563eb 50%, #1E3A8A 100%);
      background-size: 200% 100%;
      border: none; border-radius: 14px;
      color: #fff; font-weight: 800; font-size: 15px;
      cursor: pointer; letter-spacing: .3px;
      box-shadow: 0 4px 24px rgba(30,58,138,.4);
      transition: background-position .4s, transform .15s, box-shadow .2s;
    }
    .btn-primary:hover {
      background-position: 100% 0;
      transform: translateY(-1px);
      box-shadow: 0 8px 32px rgba(30,58,138,.5);
    }
    .btn-primary:active { transform: translateY(0); }
    .btn-primary::after {
      content: '';
      position: absolute; top: 0; left: -100%;
      width: 60%; height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.25), transparent);
      transform: skewX(-20deg);
      transition: left .5s;
    }
    .btn-primary:hover::after { left: 160%; }

    /* ── Panel foto ─────────────────────────────────────────── */
    .hero-img {
      width: 100%; height: 100%;
      object-fit: cover; object-position: center top;
      transition: transform 8s ease;
    }
    .hero-img:hover { transform: scale(1.03); }

    /* ── Scrollbar ──────────────────────────────────────────── */
    ::-webkit-scrollbar { width: 0; }

    /* ── Badge animado ──────────────────────────────────────── */
    .badge-pulse::before {
      content: '';
      position: absolute; inset: -2px;
      border-radius: 9999px;
      background: rgba(250,204,21,.3);
      animation: badgePulse 2.5s ease-in-out infinite;
    }
    @keyframes badgePulse {
      0%,100% { transform: scale(1);   opacity: .5; }
      50%      { transform: scale(1.15); opacity: 0; }
    }

    /* ── Separador ──────────────────────────────────────────── */
    .divider {
      display: flex; align-items: center; gap: 10px;
      color: #cbd5e1; font-size: 11px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .08em;
    }
    .divider::before, .divider::after {
      content: ''; flex: 1; height: 1px; background: #e2e8f0;
    }

    /* ── Captcha ────────────────────────────────────────────── */
    .captcha-input {
      width: 80px; padding: 10px 8px;
      border: 1.5px solid #fcd34d;
      border-radius: 10px; background: #fffbeb;
      font-size: 16px; font-weight: 800;
      text-align: center; color: #92400e; outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .captcha-input:focus {
      border-color: #f59e0b;
      box-shadow: 0 0 0 3px rgba(245,158,11,.15);
    }

    /* ── Toggle password ────────────────────────────────────── */
    .toggle-pass {
      position: absolute; right: 13px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: #94a3b8; padding: 2px;
      transition: color .15s;
    }
    .toggle-pass:hover { color: #1E3A8A; }

    /* Mobile */
    @media (max-width: 1023px) {
      body { overflow: auto; }
      .hero-panel { display: none; }
    }
  </style>
</head>
<body class="bg-animated min-h-screen flex items-center justify-center p-4 relative">

  <!-- Partículas decorativas -->
  <div class="particle" style="width:6px;height:6px;left:10%;animation-duration:18s;animation-delay:0s"></div>
  <div class="particle" style="width:10px;height:10px;left:25%;animation-duration:22s;animation-delay:3s"></div>
  <div class="particle" style="width:4px;height:4px;left:40%;animation-duration:15s;animation-delay:6s"></div>
  <div class="particle" style="width:8px;height:8px;left:55%;animation-duration:20s;animation-delay:1s"></div>
  <div class="particle" style="width:5px;height:5px;left:70%;animation-duration:17s;animation-delay:4s"></div>
  <div class="particle" style="width:9px;height:9px;left:85%;animation-duration:24s;animation-delay:8s"></div>
  <div class="particle" style="width:6px;height:6px;left:5%;animation-duration:19s;animation-delay:10s;background:rgba(56,189,248,.1)"></div>
  <div class="particle" style="width:7px;height:7px;left:92%;animation-duration:21s;animation-delay:2s;background:rgba(56,189,248,.1)"></div>

  <!-- Anillos decorativos de fondo -->
  <div class="ring" style="width:500px;height:500px;top:-100px;left:-150px"></div>
  <div class="ring" style="width:350px;height:350px;bottom:-80px;right:-80px;animation-delay:3s"></div>
  <div class="ring" style="width:200px;height:200px;top:40%;left:30%;animation-delay:1.5s"></div>

  <!-- Card principal -->
  <div class="login-card w-full max-w-4xl rounded-3xl overflow-hidden shadow-2xl flex"
       style="min-height:580px; box-shadow:0 32px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.05);">

    <!-- ── Panel izquierdo: foto ──────────────────────────── -->
    <div class="hero-panel relative w-3/5 flex-shrink-0 overflow-hidden"
         style="background:#0f2057;">
      <?php if ($login_hero_img !== ''): ?>
      <img src="<?= htmlspecialchars(login_img($login_hero_img)) ?>"
           alt="<?= htmlspecialchars($brand_name) ?>" class="hero-img absolute inset-0"
           onerror="this.style.display='none'">
      <?php endif; ?>

      <!-- Degradados sobre la foto -->
      <div class="absolute inset-0"
           style="background: linear-gradient(to right, transparent 40%, rgba(15,32,87,.7));"></div>
      <div class="absolute inset-0"
           style="background: linear-gradient(to top, rgba(15,32,87,.95) 0%, transparent 50%);"></div>

      <!-- Patrón de puntos decorativo -->
      <div class="absolute inset-0 opacity-10"
           style="background-image: radial-gradient(rgba(250,204,21,.6) 1px, transparent 1px);
                  background-size: 28px 28px;"></div>

      <!-- Contenido inferior -->
      <div class="absolute bottom-0 left-0 right-0 p-8">
        <!-- Badge -->
        <div class="inline-flex items-center gap-2 mb-5">
          <div class="relative badge-pulse">
            <div class="w-2.5 h-2.5 bg-[#FACC15] rounded-full relative z-10"></div>
          </div>
          <span class="text-[10px] font-black text-[#FACC15] uppercase tracking-widest">
            Panel Administrativo
          </span>
        </div>

        <!-- Nombre -->
        <h1 class="text-white font-black leading-none mb-1"
            style="font-size:2.2rem; text-shadow:0 2px 20px rgba(0,0,0,.4);">
          <?= htmlspecialchars($brand_name) ?>
        </h1>
        <p class="text-blue-300 font-medium text-sm tracking-wide">
          <?= htmlspecialchars($login_subtitle) ?>
        </p>

        <!-- Línea decorativa -->
        <div class="mt-5 flex items-center gap-3">
          <div class="h-0.5 w-10 bg-[#FACC15] rounded-full"></div>
          <div class="h-0.5 w-5 bg-[#FACC15]/40 rounded-full"></div>
          <div class="h-0.5 w-2 bg-[#FACC15]/20 rounded-full"></div>
        </div>
      </div>

      <!-- Orbe decorativo superior derecho -->
      <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full opacity-10"
           style="background:radial-gradient(circle, #FACC15, transparent);"></div>
    </div>

    <!-- ── Panel derecho: formulario ─────────────────────── -->
    <div class="flex-1 bg-white flex flex-col justify-center px-8 py-10 relative overflow-hidden">

      <!-- Orbe decorativo de fondo -->
      <div class="absolute -top-20 -right-20 w-64 h-64 rounded-full pointer-events-none"
           style="background:radial-gradient(circle, rgba(30,58,138,.05), transparent);"></div>
      <div class="absolute -bottom-16 -left-16 w-48 h-48 rounded-full pointer-events-none"
           style="background:radial-gradient(circle, rgba(250,204,21,.06), transparent);"></div>

      <!-- Logo + título -->
      <div class="text-center mb-7 relative z-10">
        <div class="inline-block mb-3 p-2 rounded-2xl bg-blue-50">
          <img src="<?= htmlspecialchars(login_img($login_logo)) ?>"
               alt="Logo" class="h-12 mx-auto"
               onerror="this.style.display='none'">
        </div>
        <h2 class="text-xl font-black text-[#0f2057] leading-tight">Panel Administrativo</h2>
        <p class="text-slate-400 text-sm mt-1 font-medium"><?= htmlspecialchars($login_subtitle) ?></p>
      </div>

      <!-- Error -->
      <?php if ($error): ?>
      <div class="mb-5 px-4 py-3 rounded-xl bg-red-50 border border-red-200
                  flex items-center gap-3 relative z-10
                  <?= $shake ? 'shake' : '' ?>">
        <div class="w-7 h-7 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
          <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                  d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </div>
        <p class="text-sm font-semibold text-red-700"><?= htmlspecialchars($error) ?></p>
      </div>
      <?php endif; ?>

      <!-- Formulario -->
      <form method="POST" class="space-y-4 relative z-10" id="loginForm">
        <?= csrf_field() ?>

        <!-- Email -->
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">
            Correo electrónico
          </label>
          <div class="input-wrap">
            <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <input type="email" name="email" required autocomplete="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="admin@tudominio.pe"
                   class="form-input">
          </div>
        </div>

        <!-- Contraseña -->
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">
            Contraseña
          </label>
          <div class="input-wrap">
            <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <input type="password" name="password" id="passInput" required autocomplete="current-password"
                   placeholder="••••••••"
                   class="form-input" style="padding-right:42px;">
            <button type="button" class="toggle-pass" onclick="togglePass()" title="Mostrar/ocultar">
              <svg id="eyeShow" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
              <svg id="eyeHide" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- CAPTCHA -->
        <div class="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-yellow-50 px-4 py-3">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-6 h-6 rounded-lg bg-amber-400 flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <span class="text-[10px] font-black text-amber-700 uppercase tracking-widest">Verificación de seguridad</span>
          </div>
          <div class="flex items-center gap-3">
            <div class="flex-1 px-4 py-2.5 rounded-xl bg-white border border-amber-200 text-sm font-black text-slate-700 text-center shadow-sm">
              ¿Cuánto es <?= htmlspecialchars($captcha_q) ?> ?
            </div>
            <input type="number" name="captcha" required placeholder="?"
                   class="captcha-input">
          </div>
        </div>

        <!-- Recordar + recuperar -->
        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2.5 cursor-pointer group select-none">
            <div class="relative">
              <input type="checkbox" name="remember_me" value="1" id="rememberCheck" class="sr-only peer">
              <div class="w-9 h-5 rounded-full border-2 border-slate-200 bg-slate-100
                          peer-checked:bg-[#1E3A8A] peer-checked:border-[#1E3A8A]
                          transition-all duration-200"></div>
              <div class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow
                          transition-transform duration-200
                          peer-checked:translate-x-4"></div>
            </div>
            <span class="text-sm font-semibold text-slate-500 group-hover:text-slate-700 transition-colors">
              Recordarme
            </span>
          </label>
          <a href="<?= BASE_URL ?>/admin/recuperar-password.php"
             class="text-sm font-bold text-[#1E3A8A] hover:text-blue-700
                    relative after:absolute after:bottom-0 after:left-0
                    after:w-0 after:h-0.5 after:bg-[#1E3A8A]
                    hover:after:w-full after:transition-all after:duration-200">
            ¿Olvidaste tu contraseña?
          </a>
        </div>

        <!-- Botón submit -->
        <button type="submit" class="btn-primary mt-1">
          <span class="flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                    d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
            </svg>
            Ingresar al panel
          </span>
        </button>
      </form>

      <!-- Footer -->
      <div class="mt-6 text-center relative z-10">
        <div class="divider mb-3"><span>Acceso restringido</span></div>
        <p class="text-xs text-slate-400 font-medium">
          Solo personal autorizado · Sistema seguro
        </p>
      </div>

    </div>
  </div>

  <script>
    function togglePass() {
      const inp = document.getElementById('passInput');
      const show = document.getElementById('eyeShow');
      const hide = document.getElementById('eyeHide');
      if (inp.type === 'password') {
        inp.type = 'text';
        show.classList.add('hidden');
        hide.classList.remove('hidden');
      } else {
        inp.type = 'password';
        show.classList.remove('hidden');
        hide.classList.add('hidden');
      }
    }

    // Auto-focus al primer campo vacío
    document.addEventListener('DOMContentLoaded', () => {
      const email = document.querySelector('input[name="email"]');
      const captcha = document.querySelector('input[name="captcha"]');
      if (email && !email.value) email.focus();
      else if (captcha) captcha.focus();
    });
  </script>

</body>
</html>
