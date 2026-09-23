-- ESG - Entorno Seguro y Gestión
-- Base de datos para MySQL 5.7+/8.x

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pc_identificador VARCHAR(100) UNIQUE NOT NULL,
    nombre_usuario VARCHAR(100) NOT NULL,
    rol ENUM('superadmin','usuario') DEFAULT 'usuario',
    activo BOOLEAN DEFAULT TRUE,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_ultima_conexion DATETIME NULL,
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT NOT NULL,
    foto VARCHAR(255) NULL,
    pc_origen VARCHAR(100) NOT NULL,
    usuario_origen VARCHAR(100) NOT NULL,
    pc_identificador VARCHAR(100) NOT NULL,
    numero_identificacion_pc VARCHAR(100) NOT NULL DEFAULT '',
    ip_origen VARCHAR(45) NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('pendiente','resuelto') DEFAULT 'pendiente',
    resuelto_por VARCHAR(100) NULL,
    fecha_resolucion DATETIME NULL,
    INDEX idx_tickets_pc (pc_identificador),
    INDEX idx_tickets_ni (numero_identificacion_pc),
    INDEX idx_tickets_estado (estado),
    INDEX idx_tickets_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(100) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion (clave, valor)
VALUES ('version_sistema', '1.0'), ('tickets_por_pagina', '10')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
