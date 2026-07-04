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

CREATE TABLE `usuario_permisos_modulo` (
  `usuario_id` int NOT NULL,
  `modulo` varchar(60) NOT NULL,
  PRIMARY KEY (`usuario_id`,`modulo`),
  CONSTRAINT `fk_upm_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario superadmin inicial (control total, sin restricciones de modulo).
-- Contraseña en texto plano: admin123
-- Hash generado con password_hash('admin123', PASSWORD_DEFAULT)
INSERT IGNORE INTO usuarios (nombre, email, password, rol) VALUES
('Administrador', 'admin@credenciales-app.local', '$2y$10$RiX7Owy6hHUfg1mKiLBFeuFQR1jtbE0KHdzrQw03INTtt9DDgJ6yC', 'superadmin');
