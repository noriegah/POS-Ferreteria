-- Clientes identificados. CF no necesita un registro compartido de cliente.
CREATE TABLE IF NOT EXISTS ventas_db.clientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nit VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
ALTER TABLE ventas_db.ventas
    ADD COLUMN IF NOT EXISTS cliente_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS metodo_pago ENUM('efectivo','tarjeta') NOT NULL DEFAULT 'efectivo',
    ADD COLUMN IF NOT EXISTS iva DECIMAL(12,2) NULL;

-- Nombre fijo: repetir la migración no agrega otra relación equivalente.
ALTER TABLE ventas_db.ventas ADD CONSTRAINT fk_ventas_cliente FOREIGN KEY IF NOT EXISTS fk_ventas_cliente (cliente_id) REFERENCES ventas_db.clientes(id);

-- Importe y estado del cobro pertenecen a la venta; no se guardan datos de tarjetas.
CREATE TABLE IF NOT EXISTS ventas_db.pagos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL UNIQUE,
    metodo ENUM('efectivo','tarjeta') NOT NULL,
    proveedor VARCHAR(30) NOT NULL,
    referencia_externa VARCHAR(255) NULL UNIQUE,
    importe DECIMAL(12,2) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'GTQ',
    estado ENUM('aprobado','anulado') NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venta_id) REFERENCES ventas_db.ventas(id) ON DELETE CASCADE,
    CHECK (importe >= 0)
) ENGINE=InnoDB;

-- Para cada NIT existente toma los datos de su última venta, sin alterar las facturas.
INSERT IGNORE INTO ventas_db.clientes(nit,nombre,direccion)
SELECT v.comprador_nit,v.comprador_nombre,v.comprador_direccion
FROM ventas_db.ventas v JOIN
    (SELECT comprador_nit,MAX(id) AS id FROM ventas_db.ventas WHERE comprador_nit IS NOT NULL GROUP BY comprador_nit) ultimo
ON v.id=ultimo.id;
UPDATE ventas_db.ventas v JOIN ventas_db.clientes c ON c.nit=v.comprador_nit
SET v.cliente_id=c.id WHERE v.cliente_id IS NULL;
UPDATE ventas_db.ventas SET iva=total-ROUND(total/1.12,2) WHERE total IS NOT NULL AND iva IS NULL;
-- Hasta este bloque todas las ventas se registraban en efectivo, sin proveedor externo.
INSERT IGNORE INTO ventas_db.pagos(venta_id,metodo,proveedor,importe,moneda,estado)
SELECT id,'efectivo','caja',total,moneda,IF(estado='confirmada','aprobado','anulado')
FROM ventas_db.ventas WHERE total IS NOT NULL;

