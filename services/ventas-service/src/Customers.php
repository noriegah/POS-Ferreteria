<?php
declare(strict_types=1);

// El cliente puede actualizarse; la venta conserva además su copia histórica del comprador.
final class Customers
{
    public static function save(PDO $db, array $buyer): ?int
    {
        if($buyer['nit']===null) return null;
        $q=$db->prepare('INSERT INTO clientes(nit,nombre,direccion) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),direccion=VALUES(direccion)');
        $q->execute([$buyer['nit'],$buyer['nombre'],$buyer['direccion']]);
        $q=$db->prepare('SELECT id FROM clientes WHERE nit=?'); $q->execute([$buyer['nit']]);
        return (int)$q->fetchColumn();
    }
}
