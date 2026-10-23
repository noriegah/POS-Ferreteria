-- Agrega la dirección de la imagen sin borrar ni cambiar los productos existentes.
ALTER TABLE inventario_db.productos ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(255) NULL;
