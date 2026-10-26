USE ventas_db;
-- Un turno por cajero. El índice permite muchos turnos cerrados, pero solo uno abierto.
CREATE TABLE IF NOT EXISTS cajas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id BIGINT UNSIGNED NOT NULL,
 fondo DECIMAL(12,2) NOT NULL,
 estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
 abierta_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 cerrada_en DATETIME NULL,
 contado DECIMAL(12,2) NULL,
 esperado_cierre DECIMAL(12,2) NULL,
 diferencia DECIMAL(12,2) NULL,
 observaciones VARCHAR(255) NULL,
 usuario_abierto BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN estado='abierta' THEN usuario_id ELSE NULL END) STORED,
 UNIQUE KEY uq_caja_abierta(usuario_abierto)
) ENGINE=InnoDB;
-- Los movimientos conservan el efectivo recibido o devuelto, incluso si la venta cambia de estado.
CREATE TABLE IF NOT EXISTS caja_movimientos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 caja_id BIGINT UNSIGNED NOT NULL,
 venta_id BIGINT UNSIGNED NOT NULL,
 tipo ENUM('venta','devolucion') NOT NULL,
 importe DECIMAL(12,2) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_caja_venta_tipo(venta_id,tipo),
 CONSTRAINT fk_movimiento_caja FOREIGN KEY(caja_id) REFERENCES cajas(id),
 CONSTRAINT fk_movimiento_venta FOREIGN KEY(venta_id) REFERENCES ventas(id)
) ENGINE=InnoDB;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS caja_devolucion_id BIGINT UNSIGNED NULL;

ALTER TABLE ventas_db.ventas ADD CONSTRAINT fk_venta_caja_devolucion FOREIGN KEY IF NOT EXISTS fk_venta_caja_devolucion (caja_devolucion_id) REFERENCES ventas_db.cajas(id);
