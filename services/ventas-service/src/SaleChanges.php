<?php
declare(strict_types=1);

// Cambios administrativos separados del registro normal de ventas.
final class SaleChanges
{
    public function __construct(private PDO $db) {}

    private static function reason(object $data): string
    {
        $reason=$data->motivo ?? null;
        if(!is_string($reason) || mb_strlen(trim($reason))<5 || mb_strlen(trim($reason))>255 || preg_match('/[\x00-\x1F\x7F]/u',$reason)) throw new HttpError(422,'Ingresa un motivo de 5 a 255 caracteres');
        return trim($reason);
    }

    private function lock(int $id): array
    {
        $q=$this->db->prepare('SELECT * FROM ventas WHERE id=? AND total IS NOT NULL FOR UPDATE');
        $q->execute([$id]); $sale=$q->fetch();
        if(!$sale) throw new HttpError(404,'Venta no encontrada');
        return $sale;
    }

    private function event(array $sale, string $actor, string $action, string $reason, array $after): void
    {
        $q=$this->db->prepare('INSERT INTO venta_eventos(venta_id,usuario_id,accion,motivo,datos_antes,datos_despues) VALUES (?,?,?,?,?,?)');
        $q->execute([$sale['id'],$actor,$action,$reason,json_encode($sale,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    // Primero guarda la intención. Si falla REST, otro intento puede terminar la misma anulación.
    public function cancel(int $id, object $data, object $claims): array
    {
        TokenService::requireRole($claims,['administrador']);
        $reason=self::reason($data);
        $this->db->beginTransaction();
        try {
            $sale=$this->lock($id);
            if($sale['estado']==='confirmada') {
                $q=$this->db->prepare("UPDATE ventas SET estado='anulando',anulacion_motivo=?,anulacion_usuario_id=? WHERE id=?");
                $q->execute([$reason,$claims->sub,$id]);
                $this->event($sale,$claims->sub,'solicitar_anulacion',$reason,['estado'=>'anulando']);
            }
            $this->db->commit();
        } catch(Throwable $error) { if($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        if($sale['estado']!=='anulada') {
            // Inventario conserva su recibo: repetir esta llamada no devuelve dos veces.
            InventoryClient::restore($sale['referencia']);
            $this->db->beginTransaction();
            try {
                $saved=$this->lock($id);
                if($saved['estado']==='anulando') {
                    $q=$this->db->prepare("UPDATE ventas SET estado='anulada',anulada_en=UTC_TIMESTAMP() WHERE id=?"); $q->execute([$id]);
                    $q=$this->db->prepare("UPDATE pagos SET estado='anulado' WHERE venta_id=? AND metodo='efectivo'"); $q->execute([$id]);
                    $this->event($saved,$claims->sub,'anular',$saved['anulacion_motivo'],['estado'=>'anulada']);
                }
                $this->db->commit();
            } catch(Throwable $error) { if($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        }
        return (new Sales($this->db))->find($id,$claims);
    }

    public function history(int $id, object $claims): array
    {
        TokenService::requireRole($claims,['administrador']);
        (new Sales($this->db))->find($id,$claims);
        $q=$this->db->prepare('SELECT id,usuario_id,accion,motivo,creado_en FROM venta_eventos WHERE venta_id=? ORDER BY id');
        $q->execute([$id]); return $q->fetchAll();
    }

    // Conserva productos, precios y vendedor originales. Solo corrige al comprador.
    public function edit(int $id, object $data, object $claims): array
    {
        TokenService::requireRole($claims,['administrador']);
        foreach(array_keys(get_object_vars($data)) as $field) {
            if(!in_array($field,['comprador','motivo'],true)) throw new HttpError(422,'Solo se pueden corregir los datos del comprador; para productos o cantidades, anula y registra otra venta');
        }
        if(!property_exists($data,'comprador')) throw new HttpError(422,'Faltan los datos del comprador');
        $reason=self::reason($data); $buyer=Buyer::normalize($data->comprador);
        $this->db->beginTransaction();
        try {
            $sale=$this->lock($id);
            if($sale['estado']!=='confirmada') throw new HttpError(409,'No se puede editar una venta anulada o en proceso de anulación');
            Buyer::requireForTotal($buyer,$sale['total']);
            $old=['nombre'=>$sale['comprador_nombre'],'nit'=>$sale['comprador_nit'],'direccion'=>$sale['comprador_direccion']];
            if($old!==$buyer) {
                $q=$this->db->prepare('UPDATE ventas SET comprador_nombre=?,comprador_nit=?,comprador_direccion=?,cliente_id=? WHERE id=?');
                $q->execute([$buyer['nombre'],$buyer['nit'],$buyer['direccion'],Customers::save($this->db,$buyer),$id]);
                $this->event($sale,$claims->sub,'editar_comprador',$reason,['comprador'=>$buyer]);
            }
            $result=(new Sales($this->db))->find($id,$claims);
            $this->db->commit(); return $result;
        } catch(Throwable $error) { if($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
}
