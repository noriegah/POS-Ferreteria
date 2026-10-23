<?php
declare(strict_types=1);

// Descuenta la compra completa o no descuenta nada. Solo Ventas llama a esta operación.
final class StockSales
{
    public static function discount(PDO $db, object $data, string $userId): array
    {
        [$reference, $items] = Checkout::validate($data);
        $hash = Checkout::hash($userId, $items);
        $db->beginTransaction();
        try {
            // Una referencia única impide descontar dos veces aunque Ventas reintente.
            $q = $db->prepare('INSERT INTO descuentos_venta(referencia,usuario_id,solicitud_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE referencia=VALUES(referencia)');
            $q->execute([$reference, $userId, $hash]);
            $q = $db->prepare('SELECT * FROM descuentos_venta WHERE referencia=? FOR UPDATE');
            $q->execute([$reference]); $saved = $q->fetch();
            if ((string)$saved['usuario_id'] !== $userId || $saved['solicitud_hash'] !== $hash) {
                throw new HttpError(409, 'La referencia ya corresponde a otra compra');
            }
            if ($saved['respuesta'] !== null) {
                $result = json_decode($saved['respuesta'], true, 32, JSON_THROW_ON_ERROR);
                self::checkBuyer($result['total'], $data);
                $db->commit(); return $result;
            }
            $products = new Products($db); $lines = []; $total = 0;
            foreach ($items as $item) {
                $product = $products->find($item['producto_id'], true);
                $before = (float)$product['stock'];
                if (floor($before) !== $before) { throw new HttpError(409, 'El producto tiene stock fraccionado; revisa Inventario'); }
                $quantity = $item['cantidad'];
                if ($before < $quantity) { throw new HttpError(409, 'Stock insuficiente para ' . $product['codigo']); }
                $subtotal = Checkout::cents($product['precio']) * $quantity;
                if ($subtotal > 999999999999 - $total) { throw new HttpError(422, 'El total de la venta supera el límite permitido'); }
                $total += $subtotal; $after = (int)$before - $quantity;
                $q = $db->prepare('UPDATE productos SET stock=? WHERE id=?');
                $q->execute([$after, $product['id']]);
                $q = $db->prepare('INSERT INTO movimientos_stock(producto_id,usuario_id,cantidad,stock_anterior,stock_nuevo,motivo) VALUES (?,?,?,?,?,?)');
                $q->execute([$product['id'], $userId, -$quantity, (int)$before, $after, 'Venta ' . $reference]);
                $lines[] = ['producto_id'=>(string)$product['id'], 'codigo'=>$product['codigo'], 'nombre'=>$product['nombre'],
                    'categoria_nombre'=>self::category($db, (string)$product['categoria_id']),
                    'cantidad'=>$quantity, 'precio'=>$product['precio'], 'subtotal'=>Checkout::money($subtotal)];
            }
            // Se comprueba dentro de la transacción: un comprador incompleto no descuenta stock.
            self::checkBuyer(Checkout::money($total), $data);
            $result = ['referencia'=>$reference, 'moneda'=>'GTQ', 'total'=>Checkout::money($total), 'productos'=>$lines];
            $q = $db->prepare('UPDATE descuentos_venta SET respuesta=? WHERE referencia=?');
            $q->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $reference]);
            $db->commit(); return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }
    // Copia el nombre al confirmar la venta para conservar la categoría histórica.
    private static function category(PDO $db, string $id): ?string
    {
        $q = $db->prepare('SELECT nombre FROM categorias WHERE id=?'); $q->execute([$id]);
        $name = $q->fetchColumn(); return $name === false ? null : $name;
    }
    private static function checkBuyer(string $total, object $data): void
    {
        // Ventas puede exigir un mínimo antes de que se confirme el descuento completo.
        $minimum=$data->total_minimo_centavos ?? 0;
        if(!is_int($minimum) || $minimum<0 || $minimum>999999999999) throw new HttpError(422,'Mínimo de compra inválido');
        if(Checkout::cents($total)<$minimum) throw new HttpError(422,'El total es demasiado pequeño para pagar con PayPal Sandbox');
        if (Checkout::cents($total)>=250000 && ($data->comprador_identificado ?? false)!==true) throw new HttpError(422, 'Desde Q 2,500.00 se requiere NIT, nombre y dirección del comprador');
    }
}
