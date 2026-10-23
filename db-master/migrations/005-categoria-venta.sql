-- Guarda el nombre de la categoría al vender. No inventa categorías para ventas previas.
ALTER TABLE ventas_db.detalle_ventas
    ADD COLUMN IF NOT EXISTS categoria_nombre VARCHAR(100) NULL;
