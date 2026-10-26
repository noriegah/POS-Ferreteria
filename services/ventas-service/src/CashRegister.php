<?php
declare(strict_types=1);

// Cada turno conserva su fondo, movimientos y cierre. Los cierres no se editan.
final class CashRegister
{
    public function __construct(private PDO $db) {}

    private static function amount(mixed $value): string
    {
        if((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D',(string)$value))
            throw new HttpError(422,'Ingresa un monto entre Q 0.00 y Q 99999999.99, con hasta dos decimales');
        return Checkout::money(Checkout::cents((string)$value));
    }

    // Se llama dentro de la transacción: impide cerrar mientras se registra una venta.
    public function active(string $user): array
    {
        $q=$this->db->prepare("SELECT * FROM cajas WHERE usuario_id=? AND estado='abierta' FOR UPDATE");
        $q->execute([$user]); $cash=$q->fetch();
        if(!$cash) throw new HttpError(409,'Abre tu turno en Cuadre de caja antes de cobrar o devolver efectivo');
        return $cash;
    }

    public function movement(int $cash,int $sale,string $type,string $amount): void
    {
        $q=$this->db->prepare('INSERT INTO caja_movimientos(caja_id,venta_id,tipo,importe) VALUES (?,?,?,?)');
        $q->execute([$cash,$sale,$type,$amount]);
    }

    public function open(object $data,object $claims): array
    {
        $amount=self::amount($data->fondo ?? null);
        try {
            $q=$this->db->prepare('INSERT INTO cajas(usuario_id,fondo) VALUES (?,?)');$q->execute([$claims->sub,$amount]);
        } catch(PDOException $error) {
            if($error->getCode()==='23000') throw new HttpError(409,'Ya tienes un turno abierto; consulta Cuadre de caja');
            throw $error;
        }
        return $this->detail((int)$this->db->lastInsertId(),$claims);
    }

    public function detail(int $id,object $claims): array
    {
        $q=$this->db->prepare("SELECT c.*, COALESCE(SUM(CASE WHEN m.tipo='venta' THEN m.importe ELSE 0 END),0) AS ventas_efectivo, COALESCE(SUM(CASE WHEN m.tipo='devolucion' THEN m.importe ELSE 0 END),0) AS devoluciones, c.fondo+COALESCE(SUM(CASE WHEN m.tipo='venta' THEN m.importe ELSE -m.importe END),0) AS esperado FROM cajas c LEFT JOIN caja_movimientos m ON m.caja_id=c.id WHERE c.id=? GROUP BY c.id");
        $q->execute([$id]);$cash=$q->fetch();
        if(!$cash || ($claims->role!=='administrador' && (string)$cash['usuario_id']!==$claims->sub)) throw new HttpError(404,'Turno no encontrado');
        $q=$this->db->prepare('SELECT venta_id,tipo,importe,creado_en FROM caja_movimientos WHERE caja_id=? ORDER BY id');
        $q->execute([$id]);$cash['movimientos']=$q->fetchAll();return $cash;
    }

    public function listing(object $claims): array
    {
        $q=$this->db->prepare('SELECT id,usuario_id,estado,abierta_en,cerrada_en,fondo,contado,esperado_cierre,diferencia FROM cajas '.($claims->role==='administrador' ? '' : 'WHERE usuario_id=? ').'ORDER BY id DESC LIMIT 100');
        $q->execute($claims->role==='administrador' ? [] : [$claims->sub]);$rows=$q->fetchAll();
        $q=$this->db->prepare("SELECT id FROM cajas WHERE usuario_id=? AND estado='abierta'");$q->execute([$claims->sub]);$id=$q->fetchColumn();
        return ['turnos'=>$rows,'actual'=>$id ? $this->detail((int)$id,$claims) : null];
    }

    public function close(int $id,object $data,object $claims): array
    {
        $counted=self::amount($data->contado ?? null);
        $note=$data->observaciones ?? '';
        if(!is_string($note) || strlen($note)>1000 || preg_match('/[\x00-\x1F\x7F]/u',$note) || preg_match_all('/./us',$note)>255) throw new HttpError(422,'Observación inválida; máximo 255 caracteres');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM cajas WHERE id=? FOR UPDATE');$q->execute([$id]);$cash=$q->fetch();
            // El administrador consulta otros turnos, pero cada cajero cuenta y cierra el suyo.
            if(!$cash || (string)$cash['usuario_id']!==$claims->sub) throw new HttpError(404,'Turno propio no encontrado');
            if($cash['estado']==='cerrada') {
                if($cash['contado']!==$counted || ($cash['observaciones'] ?? '')!==trim($note)) throw new HttpError(409,'El turno ya está cerrado y no se puede modificar');
            } else {
                $q=$this->db->prepare("SELECT id FROM ventas WHERE caja_devolucion_id=? AND estado='anulando' LIMIT 1");$q->execute([$id]);
                if($q->fetch()) throw new HttpError(409,'Termina las anulaciones pendientes antes de cerrar este turno');
                $detail=$this->detail($id,$claims);$expected=Checkout::cents(ltrim($detail['esperado'],'-'))*(str_starts_with($detail['esperado'],'-') ? -1 : 1);$difference=Checkout::cents($counted)-$expected;
                if($difference!=0 && trim($note)==='') throw new HttpError(422,'Escribe una observación para explicar el faltante o sobrante');
                $q=$this->db->prepare("UPDATE cajas SET estado='cerrada',cerrada_en=UTC_TIMESTAMP(),contado=?,esperado_cierre=?,diferencia=CAST(? AS DECIMAL(12,2))-CAST(? AS DECIMAL(12,2)),observaciones=? WHERE id=?");
                $q->execute([$counted,$detail['esperado'],$counted,$detail['esperado'],trim($note),$id]);
            }
            $result=$this->detail($id,$claims);$this->db->commit();return $result;
        } catch(Throwable $error) {if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
}
