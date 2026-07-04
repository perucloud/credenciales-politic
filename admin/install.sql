CREATE DATABASE IF NOT EXISTS credenciales_app
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE credenciales_app;

CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('superadmin','admin','editor') NOT NULL DEFAULT 'editor',
  `activo` tinyint(1) DEFAULT '1',
  `foto` varchar(255) DEFAULT NULL,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  `remember_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `usuario_nombre` varchar(150) DEFAULT NULL,
  `accion` varchar(255) DEFAULT NULL,
  `modulo` varchar(100) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `configuracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) NOT NULL,
  `valor` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `simpatizantes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `dni` char(8) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `distrito` varchar(100) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
  `tipo_documento` varchar(30) DEFAULT 'DNI',
  `correo` varchar(120) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `formas_apoyo` varchar(255) DEFAULT NULL,
  `estado` enum('activo','bloqueado') NOT NULL DEFAULT 'activo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `dni` (`dni`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personeros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombres` varchar(120) NOT NULL,
  `apellidos` varchar(120) NOT NULL,
  `dni` char(8) DEFAULT NULL,
  `carnet_extranjeria` varchar(9) DEFAULT NULL,
  `edad` tinyint unsigned DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `nacionalidad` varchar(80) NOT NULL DEFAULT 'Peruana',
  `cargo` enum('titular','alterno') NOT NULL DEFAULT 'titular',
  `celular` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `direccion` text,
  `local_votacion` varchar(200) DEFAULT NULL,
  `numero_mesa` varchar(30) DEFAULT NULL,
  `foto` varchar(300) DEFAULT NULL,
  `origen` enum('manual','militante','simpatizante') NOT NULL DEFAULT 'manual',
  `origen_id` int DEFAULT NULL,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personero_mensajes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `canal` varchar(20) NOT NULL DEFAULT 'correo',
  `asunto` varchar(200) NOT NULL,
  `mensaje` text NOT NULL,
  `alcance` varchar(20) NOT NULL DEFAULT 'grupo',
  `creado_por` int DEFAULT NULL,
  `adjunto_nombre` varchar(180) DEFAULT NULL,
  `adjunto_ruta` varchar(255) DEFAULT NULL,
  `adjunto_tipo` varchar(120) DEFAULT NULL,
  `adjunto_tamanio` int DEFAULT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personero_mensaje_canales` (
  `mensaje_id` int NOT NULL,
  `canal` varchar(20) NOT NULL,
  PRIMARY KEY (`mensaje_id`,`canal`),
  CONSTRAINT `fk_pmc_mensaje` FOREIGN KEY (`mensaje_id`) REFERENCES `personero_mensajes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personero_mensaje_destinatarios` (
  `mensaje_id` int NOT NULL,
  `personero_id` int NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `enviado_en` datetime DEFAULT NULL,
  `error` text,
  PRIMARY KEY (`mensaje_id`,`personero_id`),
  CONSTRAINT `fk_pmd_mensaje` FOREIGN KEY (`mensaje_id`) REFERENCES `personero_mensajes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personero_wa_plantillas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `contenido` text NOT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credenciales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `qr_token` char(32) NOT NULL,
  `persona_tipo` enum('militante','simpatizante') NOT NULL,
  `persona_id` int NOT NULL,
  `nombres_completos` varchar(200) NOT NULL,
  `dni` char(8) NOT NULL,
  `cargo` varchar(150) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `centro_poblado` varchar(150) DEFAULT NULL,
  `comunidad_nativa` varchar(150) DEFAULT NULL,
  `distrito` varchar(120) DEFAULT NULL,
  `provincia` varchar(120) NOT NULL DEFAULT 'Satipo',
  `region` varchar(120) NOT NULL DEFAULT 'Junin',
  `direccion` varchar(255) DEFAULT NULL,
  `foto` varchar(300) DEFAULT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `estado` enum('activo','anulado','vencido') NOT NULL DEFAULT 'activo',
  `creado_por` int DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  UNIQUE KEY `qr_token` (`qr_token`),
  KEY `idx_credenciales_estado` (`estado`),
  KEY `idx_credenciales_dni` (`dni`),
  KEY `idx_credenciales_persona` (`persona_tipo`,`persona_id`),
  KEY `idx_credenciales_vencimiento` (`fecha_vencimiento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credenciales_escaneadas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dni` char(8) NOT NULL,
  `nombres_completos` varchar(220) NOT NULL,
  `lugar` varchar(180) DEFAULT NULL,
  `archivo` varchar(300) NOT NULL,
  `archivo_nombre` varchar(180) DEFAULT NULL,
  `creado_por` int DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ce_dni` (`dni`),
  KEY `idx_ce_nombre` (`nombres_completos`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `militante_cargos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` int NOT NULL DEFAULT '0',
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO militante_cargos (nombre, orden) VALUES
  ('Coordinador Provincial', 10),
  ('Coordinador Distrital', 20),
  ('Secretario', 30),
  ('Delegado', 40),
  ('Dirigente', 50);

CREATE TABLE `militantes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `simpatizante_id` int DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `dni` char(8) NOT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `cargo_id` int DEFAULT NULL,
  `fecha_ingreso` date NOT NULL,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dni` (`dni`),
  KEY `idx_militantes_estado` (`estado`),
  KEY `idx_militantes_cargo` (`cargo_id`),
  KEY `idx_militantes_fecha` (`fecha_ingreso`),
  CONSTRAINT `fk_militantes_simpatizante` FOREIGN KEY (`simpatizante_id`) REFERENCES `simpatizantes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_militantes_cargo` FOREIGN KEY (`cargo_id`) REFERENCES `militante_cargos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `militante_mensajes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `canal` enum('whatsapp','sms','correo') NOT NULL,
  `asunto` varchar(180) DEFAULT NULL,
  `mensaje` text NOT NULL,
  `alcance` enum('individual','grupo','masivo') NOT NULL DEFAULT 'individual',
  `adjunto_nombre` varchar(180) DEFAULT NULL,
  `adjunto_ruta` varchar(255) DEFAULT NULL,
  `adjunto_tipo` varchar(120) DEFAULT NULL,
  `adjunto_tamanio` int DEFAULT NULL,
  `creado_por` int DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_militante_mensajes_canal` (`canal`),
  KEY `idx_militante_mensajes_creado` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `militante_mensaje_destinatarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mensaje_id` int NOT NULL,
  `militante_id` int NOT NULL,
  `estado` enum('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  `enviado_en` datetime DEFAULT NULL,
  `error` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mensaje_militante` (`mensaje_id`,`militante_id`),
  CONSTRAINT `fk_mmd_mensaje` FOREIGN KEY (`mensaje_id`) REFERENCES `militante_mensajes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mmd_militante` FOREIGN KEY (`militante_id`) REFERENCES `militantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `militante_mensaje_canales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mensaje_id` int NOT NULL,
  `canal` enum('whatsapp','sms','correo') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mensaje_canal` (`mensaje_id`,`canal`),
  CONSTRAINT `fk_mmc_mensaje` FOREIGN KEY (`mensaje_id`) REFERENCES `militante_mensajes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `militante_wa_plantillas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `contenido` text NOT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hero_slides` (
  `id` int NOT NULL AUTO_INCREMENT,
  `imagen` varchar(300) NOT NULL,
  `titulo` varchar(200) DEFAULT NULL,
  `subtitulo` varchar(255) DEFAULT NULL,
  `boton_texto` varchar(100) DEFAULT NULL,
  `boton_url` varchar(255) DEFAULT NULL,
  `orden` int NOT NULL DEFAULT '0',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `noticias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(300) NOT NULL,
  `contenido` text,
  `imagen` varchar(300) DEFAULT NULL,
  `categoria` varchar(100) NOT NULL DEFAULT 'General',
  `estado` enum('publicado','borrador') NOT NULL DEFAULT 'borrador',
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_noticias_estado` (`estado`),
  KEY `idx_noticias_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `ruta` varchar(255) NOT NULL,
  `tipo` varchar(120) NOT NULL,
  `tamanio` int NOT NULL DEFAULT '0',
  `modulo` varchar(60) NOT NULL DEFAULT 'media',
  `usuario_id` int DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_files_creado` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuario_permisos_modulo` (
  `usuario_id` int NOT NULL,
  `modulo` varchar(60) NOT NULL,
  PRIMARY KEY (`usuario_id`,`modulo`),
  CONSTRAINT `fk_upm_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario superadmin inicial (control total, sin restricciones de modulo).
-- Contraseña en texto plano: PeterJohn123;@
-- Hash generado con password_hash('PeterJohn123;@', PASSWORD_DEFAULT)
INSERT INTO usuarios (nombre, email, password, rol) VALUES
('Administrador', 'sistemas@perucloud.net.pe', '$2y$10$UiHfEIs7d2ngs3J/w97La.wdr0flmxhWLzypdo151aBh28ipB/8p2', 'superadmin')
ON DUPLICATE KEY UPDATE password = VALUES(password), rol = VALUES(rol), activo = 1;
