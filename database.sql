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

-- Inventario de equipos mediante agente Windows
CREATE TABLE IF NOT EXISTS agentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id CHAR(32) NOT NULL UNIQUE,
    machine_guid VARCHAR(128) NOT NULL UNIQUE,
    hostname VARCHAR(255) NULL,
    username VARCHAR(255) NULL,
    domain_name VARCHAR(255) NULL,
    os_name VARCHAR(255) NULL,
    os_version VARCHAR(100) NULL,
    os_build VARCHAR(100) NULL,
    architecture VARCHAR(50) NULL,
    ip_origen VARCHAR(45) NULL,
    cpu_json LONGTEXT NULL,
    memory_json LONGTEXT NULL,
    bios_json LONGTEXT NULL,
    motherboard_json LONGTEXT NULL,
    system_product_json LONGTEXT NULL,
    disks_json LONGTEXT NULL,
    gpus_json LONGTEXT NULL,
    network_json LONGTEXT NULL,
    logical_disks_json LONGTEXT NULL,
    installed_software_json LONGTEXT NULL,
    antivirus_json LONGTEXT NULL,
    full_inventory_json LONGTEXT NULL,
    claim_token_hash CHAR(64) NULL,
    claim_expires_at DATETIME NULL,
    claim_used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agentes_hostname (hostname),
    INDEX idx_agentes_username (username),
    INDEX idx_agentes_ip (ip_origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agente_descargas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    ip_origen VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    downloaded_at DATETIME NULL,
    registered_at DATETIME NULL,
    INDEX idx_descargas_ip (ip_origen),
    INDEX idx_descargas_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
