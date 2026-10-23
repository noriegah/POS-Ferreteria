-- Crea las tablas de Fase 2 sin borrar datos. Se aplica con scripts/migrate.ps1 al volumen existente.
CREATE DATABASE IF NOT EXISTS auth_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS inventario_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Usuarios del sistema: guarda el hash de contraseña, el rol y si la cuenta está activa.
CREATE TABLE IF NOT EXISTS auth_db.usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('administrador', 'cajero') NOT NULL DEFAULT 'cajero',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_usuarios_email UNIQUE (email),
    CONSTRAINT ck_usuarios_activo CHECK (activo IN (0, 1))
) ENGINE=InnoDB;

-- Agrupa los productos, por ejemplo Herramientas o Pinturas.
CREATE TABLE IF NOT EXISTS inventario_db.categorias (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_categorias_nombre UNIQUE (nombre),
    CONSTRAINT ck_categorias_activo CHECK (activo IN (0, 1))
) ENGINE=InnoDB;

-- Guarda productos, precios y existencias. activo=0 permite retirarlos sin eliminarlos.
CREATE TABLE IF NOT EXISTS inventario_db.productos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(64) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    precio DECIMAL(12,2) NOT NULL,
    stock DECIMAL(14,3) NOT NULL DEFAULT 0,
    stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_productos_codigo UNIQUE (codigo),
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES inventario_db.categorias(id),
    CONSTRAINT ck_productos_precio CHECK (precio >= 0),
    CONSTRAINT ck_productos_stock CHECK (stock >= 0),
    CONSTRAINT ck_productos_stock_minimo CHECK (stock_minimo >= 0),
    CONSTRAINT ck_productos_activo CHECK (activo IN (0, 1)),
    INDEX idx_productos_nombre (nombre)
) ENGINE=InnoDB;

-- Registra quién cambió el stock y los valores anteriores/nuevos; usuario_id proviene del JWT.
CREATE TABLE IF NOT EXISTS inventario_db.movimientos_stock (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL COMMENT 'JWT subject; no cross-service foreign key',
    cantidad DECIMAL(14,3) NOT NULL COMMENT 'Signed stock adjustment',
    stock_anterior DECIMAL(14,3) NOT NULL,
    stock_nuevo DECIMAL(14,3) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movimientos_producto FOREIGN KEY (producto_id) REFERENCES inventario_db.productos(id),
    CONSTRAINT ck_movimientos_cantidad CHECK (cantidad <> 0),
    CONSTRAINT ck_movimientos_stock CHECK (stock_anterior >= 0 AND stock_nuevo >= 0),
    CONSTRAINT ck_movimientos_balance CHECK (stock_nuevo = stock_anterior + cantidad),
    INDEX idx_movimientos_producto_fecha (producto_id, creado_en)
) ENGINE=InnoDB;
