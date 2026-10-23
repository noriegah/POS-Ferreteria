-- Datos opcionales para demostrar Inventario. Ejecutar solo en el entorno de demostración.
-- No crea usuarios ni incluye contraseñas públicas.
START TRANSACTION;
INSERT IGNORE INTO inventario_db.categorias(nombre) VALUES ('Demostración de herramientas');
INSERT IGNORE INTO inventario_db.productos(categoria_id,codigo,nombre,precio,stock,stock_minimo,activo)
SELECT id,'DEMO-MARTILLO','Martillo de demostración',75.00,20,5,1 FROM inventario_db.categorias WHERE nombre='Demostración de herramientas';
INSERT IGNORE INTO inventario_db.productos(categoria_id,codigo,nombre,precio,stock,stock_minimo,activo)
SELECT id,'DEMO-DESTORNILLADOR','Destornillador de demostración',25.00,15,5,1 FROM inventario_db.categorias WHERE nombre='Demostración de herramientas';
COMMIT;
