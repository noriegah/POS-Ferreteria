-- Retira únicamente las relaciones sin nombre generadas en la primera ejecución del bloque.
-- fk_ventas_cliente, creada por 007, conserva la integridad de los mismos datos.
ALTER TABLE ventas_db.ventas
    DROP FOREIGN KEY IF EXISTS ventas_ibfk_1,
    DROP FOREIGN KEY IF EXISTS ventas_ibfk_2;
