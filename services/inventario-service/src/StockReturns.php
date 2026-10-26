<?php
declare(strict_types=1);

// Devuelve las cantidades del descuento original; nunca acepta cantidades del navegador.
final class StockReturns
{
    public static function restore(PDO $db, object $data, string $administrator,bool $ownOnly=false): array
    {
        $reference=$data->referencia ?? null;
        if(!is_string($reference) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$reference)) throw new HttpError(422,'Referencia inválida');
        $db->beginTransaction();
        try {
            // Bloquear el descuento hace que dos solicitudes no repongan el mismo stock.
            $q=$db->prepare('SELECT respuesta,usuario_id FROM descuentos_venta WHERE referencia=? FOR UPDATE');
            $q->execute([$reference]); $original=$q->fetch();
            if(!$original || $original['respuesta']===null) throw new HttpError(409,'No existe un descuento confirmado');
            if($ownOnly && (string)$original['usuario_id']!==$administrator) throw new HttpError(403,'Solo puedes liberar la reserva de tu propia venta');
            $q=$db->prepare('SELECT respuesta FROM devoluciones_venta WHERE referencia=?');
            $q->execute([$reference]); $saved=$q->fetchColumn();
            if($saved!==false) { $db->commit(); return json_decode($saved,true,32,JSON_THROW_ON_ERROR); }
            $discount=json_decode($original['respuesta'],true,32,JSON_THROW_ON_ERROR);
            $lines=$discount['productos'];
            usort($lines,fn($a,$b)=>(int)$a['producto_id']<=>(int)$b['producto_id']);
            foreach($lines as $line) {
                $q=$db->prepare('SELECT stock FROM productos WHERE id=? FOR UPDATE');
                $q->execute([$line['producto_id']]); $stock=$q->fetchColumn();
                if($stock===false) throw new HttpError(409,'No se encontró un producto de la venta');
                $before=(float)$stock; $after=$before+(int)$line['cantidad'];
                if($after>99999999999.999) throw new HttpError(409,'La devolución supera el límite de existencias');
                // Un producto desactivado también recupera sus existencias.
                $q=$db->prepare('UPDATE productos SET stock=? WHERE id=?'); $q->execute([$after,$line['producto_id']]);
                $q=$db->prepare('INSERT INTO movimientos_stock(producto_id,usuario_id,cantidad,stock_anterior,stock_nuevo,motivo) VALUES (?,?,?,?,?,?)');
                $q->execute([$line['producto_id'],$administrator,$line['cantidad'],$before,$after,'Anulación '.$reference]);
            }
            $result=['referencia'=>$reference,'devuelta'=>true];
            $q=$db->prepare('INSERT INTO devoluciones_venta(referencia,usuario_id,respuesta) VALUES (?,?,?)');
            $q->execute([$reference,$administrator,json_encode($result,JSON_THROW_ON_ERROR)]);
            $db->commit(); return $result;
        } catch(Throwable $error) {
            if($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
