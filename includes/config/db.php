<?php
// ============================================================
// CONFIGURACION BASE DE DATOS - Credenciales App
// Prioridad: 1) variables de entorno  2) db.local.php  3) default local
// En produccion: edita includes/config/db.local.php (NO subir a git)
// ============================================================
require_once __DIR__ . '/logger.php';

$_db_local_file = __DIR__ . '/db.local.php';
$_db_local = is_file($_db_local_file) ? require $_db_local_file : [];
if (!is_array($_db_local)) $_db_local = [];

define('DB_HOST',    getenv('CRED_DB_HOST')    ?: ($_db_local['host']    ?? 'localhost'));
define('DB_USER',    getenv('CRED_DB_USER')    ?: ($_db_local['user']    ?? 'dev'));
define('DB_PASS',    getenv('CRED_DB_PASS')    ?: ($_db_local['pass']    ?? '1234'));
define('DB_NAME',    getenv('CRED_DB_NAME')    ?: ($_db_local['name']    ?? 'credenciales_app'));
define('DB_CHARSET', getenv('CRED_DB_CHARSET') ?: ($_db_local['charset'] ?? 'utf8mb4'));

// Zona horaria Peru
date_default_timezone_set('America/Lima');

// URL base del sitio (sin barra final). Se detecta automaticamente del host.
// Esta app solo tiene admin/ e includes/ (sin sitio publico).
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(admin|includes)$#', '', $scriptDir);
$basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
define('BASE_URL', $scheme . '://' . $host . $basePath);

// URL publica usada en codigos QR impresos. Debe ser un dominio real para que
// los lectores moviles la reconozcan como enlace web y no como texto local.
$qrPublicBase = getenv('CRED_QR_PUBLIC_BASE_URL') ?: ($_db_local['qr_public_base_url'] ?? $host);
define('QR_PUBLIC_BASE_URL', rtrim((string)$qrPublicBase, '/'));

// Conexion PDO. El instalador puede cargar constantes sin conectar.
if (!defined('SKIP_DB_CONNECT')) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        app_log('FATAL', 'Fallo conexion PDO: ' . $e->getMessage());
        die(json_encode(['error' => 'Error de conexion a la base de datos.']));
    }

    // Migraciones automáticas ligeras (columnas opcionales)
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS remember_token VARCHAR(64) NULL DEFAULT NULL");
    } catch (Exception $_e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('superadmin','admin','editor') NOT NULL DEFAULT 'editor'");
    } catch (Exception $_e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_permisos_modulo (
            usuario_id INT NOT NULL,
            modulo VARCHAR(60) NOT NULL,
            PRIMARY KEY (usuario_id, modulo),
            CONSTRAINT fk_upm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $_e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS militante_cargos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL UNIQUE,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO militante_cargos (nombre, orden) VALUES
            ('Coordinador Provincial', 10), ('Coordinador Distrital', 20),
            ('Secretario', 30), ('Delegado', 40), ('Dirigente', 50)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS militantes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            simpatizante_id INT NULL,
            nombre VARCHAR(150) NOT NULL,
            dni CHAR(8) NOT NULL UNIQUE,
            celular VARCHAR(20) NULL,
            whatsapp VARCHAR(20) NULL,
            correo VARCHAR(150) NULL,
            cargo_id INT NULL,
            fecha_ingreso DATE NOT NULL,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_militantes_estado (estado),
            INDEX idx_militantes_cargo (cargo_id),
            INDEX idx_militantes_fecha (fecha_ingreso),
            CONSTRAINT fk_militantes_simpatizante FOREIGN KEY (simpatizante_id) REFERENCES simpatizantes(id) ON DELETE SET NULL,
            CONSTRAINT fk_militantes_cargo FOREIGN KEY (cargo_id) REFERENCES militante_cargos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS militante_mensajes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            canal ENUM('whatsapp','sms','correo') NOT NULL,
            asunto VARCHAR(180) NULL,
            mensaje TEXT NOT NULL,
            alcance ENUM('individual','grupo','masivo') NOT NULL DEFAULT 'individual',
            adjunto_nombre VARCHAR(180) NULL,
            adjunto_ruta VARCHAR(255) NULL,
            adjunto_tipo VARCHAR(120) NULL,
            adjunto_tamanio INT NULL,
            creado_por INT NULL,
            creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_militante_mensajes_canal (canal),
            INDEX idx_militante_mensajes_creado (creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS militante_mensaje_destinatarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mensaje_id INT NOT NULL,
            militante_id INT NOT NULL,
            estado ENUM('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
            enviado_en DATETIME NULL,
            error TEXT NULL,
            UNIQUE KEY uq_mensaje_militante (mensaje_id, militante_id),
            CONSTRAINT fk_mmd_mensaje FOREIGN KEY (mensaje_id) REFERENCES militante_mensajes(id) ON DELETE CASCADE,
            CONSTRAINT fk_mmd_militante FOREIGN KEY (militante_id) REFERENCES militantes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS militante_mensaje_canales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mensaje_id INT NOT NULL,
            canal ENUM('whatsapp','sms','correo') NOT NULL,
            UNIQUE KEY uq_mensaje_canal (mensaje_id, canal),
            CONSTRAINT fk_mmc_mensaje FOREIGN KEY (mensaje_id) REFERENCES militante_mensajes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS militante_wa_plantillas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(200) NOT NULL,
            contenido TEXT NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $_e) {}
}
