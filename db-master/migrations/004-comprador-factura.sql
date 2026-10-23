-- Conserva los datos del comprador al registrar la venta; las ventas previas permanecen como CF.
ALTER TABLE ventas_db.ventas
    ADD COLUMN IF NOT EXISTS comprador_nombre VARCHAR(150) NOT NULL DEFAULT 'CF',
    ADD COLUMN IF NOT EXISTS comprador_nit VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS comprador_direccion VARCHAR(255) NULL;
