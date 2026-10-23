-- Los comprobantes se conservan. El estado separa ventas válidas y anulaciones.
ALTER TABLE ventas_db.ventas
    ADD COLUMN IF NOT EXISTS estado ENUM('confirmada','anulando','anulada') NOT NULL DEFAULT 'confirmada',
    ADD COLUMN IF NOT EXISTS anulacion_motivo VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS anulacion_usuario_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS anulada_en DATETIME NULL;

-- No hay FK a usuarios: Autenticación pertenece a otro microservicio.
CREATE TABLE IF NOT EXISTS ventas_db.venta_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    accion VARCHAR(30) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    datos_antes LONGTEXT NOT NULL,
    datos_despues LONGTEXT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venta_id) REFERENCES ventas_db.ventas(id) ON DELETE CASCADE,
    INDEX (venta_id, creado_en)
) ENGINE=InnoDB;

-- Cada referencia se devuelve solo una vez, incluso si se corta la comunicación REST.
CREATE TABLE IF NOT EXISTS inventario_db.devoluciones_venta (
    referencia CHAR(36) PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    respuesta LONGTEXT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referencia) REFERENCES inventario_db.descuentos_venta(referencia) ON DELETE CASCADE
) ENGINE=InnoDB;
