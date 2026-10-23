-- Cada servicio guarda sus propios datos. No hay claves foráneas entre bases.
CREATE TABLE IF NOT EXISTS inventario_db.descuentos_venta (
    referencia CHAR(36) PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    solicitud_hash CHAR(64) NOT NULL,
    respuesta LONGTEXT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ventas_db.ventas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referencia CHAR(36) NOT NULL UNIQUE,
    usuario_id BIGINT UNSIGNED NOT NULL,
    solicitud_hash CHAR(64) NOT NULL,
    total DECIMAL(12,2) NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'GTQ',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (total IS NULL OR total >= 0)
) ENGINE=InnoDB;

-- Se conserva el nombre y precio de cada producto al momento de venderlo.
CREATE TABLE IF NOT EXISTS ventas_db.detalle_ventas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(64) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    precio DECIMAL(12,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas_db.ventas(id),
    UNIQUE (venta_id, producto_id),
    CHECK (cantidad > 0 AND precio >= 0 AND subtotal >= 0)
) ENGINE=InnoDB;
