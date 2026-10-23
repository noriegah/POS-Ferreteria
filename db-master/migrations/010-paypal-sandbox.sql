-- El total sigue en GTQ. La conversión de demostración queda fijada en cada pago.
ALTER TABLE ventas_db.ventas MODIFY COLUMN estado ENUM('confirmada','pendiente_pago','anulando','anulada') NOT NULL DEFAULT 'confirmada';
ALTER TABLE ventas_db.pagos
    MODIFY COLUMN estado ENUM('pendiente','aprobado','reembolso_pendiente','anulado') NOT NULL,
    ADD COLUMN IF NOT EXISTS orden_externa VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS captura_externa VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS reembolso_externo VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS moneda_proveedor CHAR(3) NULL,
    ADD COLUMN IF NOT EXISTS importe_proveedor DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS tasa_demo DECIMAL(8,4) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_pago_orden ON ventas_db.pagos(orden_externa);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pago_captura ON ventas_db.pagos(captura_externa);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pago_reembolso ON ventas_db.pagos(reembolso_externo);
