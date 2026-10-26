<?php
declare(strict_types=1);

// Coordina el pago externo con la venta local. Las existencias se apartan antes del checkout.
final class Payments
{
    private PayPalClient $provider;
    public function __construct(private PDO $db,?PayPalClient $provider=null) {$this->provider=$provider ?? new PayPalClient();}

    private function lock(int $id,object $claims): array
    {
        $q=$this->db->prepare('SELECT * FROM ventas WHERE id=? AND total IS NOT NULL FOR UPDATE');
        $q->execute([$id]); $sale=$q->fetch();
        if(!$sale || ($claims->role!=='administrador' && (string)$sale['usuario_id']!==$claims->sub)) throw new HttpError(404,'Venta no encontrada');
        $q=$this->db->prepare('SELECT * FROM pagos WHERE venta_id=? FOR UPDATE'); $q->execute([$id]); $payment=$q->fetch();
        if(!$payment || $sale['metodo_pago']!=='tarjeta' || $payment['proveedor']!=='paypal_sandbox') throw new HttpError(409,'La venta no corresponde a PayPal Sandbox');
        return [$sale,$payment];
    }

    public function order(int $id,object $claims): array
    {
        $this->db->beginTransaction();
        try {
            [$sale,$payment]=$this->lock($id,$claims);
            if(!in_array($sale['estado'],['pendiente_pago','confirmada'],true)) throw new HttpError(409,'La venta está anulada o en anulación');
            $url=null; $authorized=false;
            if($payment['estado']!=='aprobado') {
                if($payment['orden_externa']===null) {
                    $created=$this->provider->create($sale,$payment);
                    if(!is_string($created['id'] ?? null)) throw new HttpError(503,'No se recibió la orden de PayPal');
                    $payment['orden_externa']=$created['id'];
                    $order=$this->provider->order($payment['orden_externa']);
                    PayPalClient::validate($order,$sale,$payment);
                    // Valida el enlace antes de guardarlo o presentarlo al usuario.
                    $url=PayPalClient::approval($order);
                    $q=$this->db->prepare('UPDATE pagos SET orden_externa=? WHERE id=?'); $q->execute([$payment['orden_externa'],$payment['id']]);
                } else {
                    $order=$this->provider->order($payment['orden_externa']); PayPalClient::validate($order,$sale,$payment);
                    $authorized=in_array($order['status'] ?? '',['APPROVED','COMPLETED'],true);
                    if(!$authorized) $url=PayPalClient::approval($order);
                }
            }
            $this->db->commit();
            return ['venta_id'=>(string)$id,'estado'=>$payment['estado'],'url'=>$url,'autorizado'=>$authorized,
                'total_quetzales'=>$sale['total'],'importe_usd'=>$payment['importe_proveedor'],'tasa_demo'=>$payment['tasa_demo']];
        } catch(Throwable $error) {if($this->db->inTransaction()) $this->db->rollBack();throw $error;}
    }

    public function capture(int $id,object $claims): array
    {
        $this->db->beginTransaction();
        try {
            // El bloqueo también serializa capturas y anulaciones entre las dos instancias.
            [$sale,$payment]=$this->lock($id,$claims);
            if($sale['estado']==='confirmada' && $payment['estado']==='aprobado') {
                $this->db->commit(); return (new Sales($this->db))->find($id,$claims);
            }
            if($sale['estado']!=='pendiente_pago' || $payment['estado']!=='pendiente') throw new HttpError(409,'La venta ya no admite un cobro');
            if(!$payment['orden_externa']) throw new HttpError(409,'Primero prepara el pago de PayPal');
            // Consulta antes de capturar: recupera también una respuesta perdida después del cobro.
            $order=$this->provider->order($payment['orden_externa']); $capture=PayPalClient::validate($order,$sale,$payment);
            if($capture===null) {
                if(($order['status'] ?? '')!=='APPROVED') throw new HttpError(409,'Autoriza el pago en PayPal Sandbox antes de verificarlo');
                // Conservar la intención incluso si se pierde la respuesta del proveedor.
                $q=$this->db->prepare('INSERT INTO venta_eventos(venta_id,usuario_id,accion,motivo,datos_antes,datos_despues) VALUES (?,?,?,?,?,?)');
                $q->execute([$id,$claims->sub,'solicitar_cobro','Solicitud de captura PayPal','{}','{}']);
                $this->db->commit();
                $this->db->beginTransaction();
                [$sale,$payment]=$this->lock($id,$claims);
                if($sale['estado']==='confirmada' && $payment['estado']==='aprobado') {$this->db->commit();return (new Sales($this->db))->find($id,$claims);}
                if($sale['estado']!=='pendiente_pago' || $payment['estado']!=='pendiente') throw new HttpError(409,'La venta ya no admite un cobro');
                $this->provider->capture($payment['orden_externa'],$sale['referencia']);
                // La respuesta de captura puede ser mínima; consulta la orden completa.
                $order=$this->provider->order($payment['orden_externa']);
                $capture=PayPalClient::validate($order,$sale,$payment);
            }
            if(($order['status'] ?? '')!=='COMPLETED' || ($capture['status'] ?? '')!=='COMPLETED') throw new HttpError(503,'El cobro todavía no está completado en PayPal. Reintenta la verificación');
            $q=$this->db->prepare("UPDATE pagos SET estado='aprobado',captura_externa=?,referencia_externa=? WHERE id=?");
            $q->execute([$capture['id'],$capture['id'],$payment['id']]);
            $q=$this->db->prepare("UPDATE ventas SET estado='confirmada' WHERE id=?"); $q->execute([$id]);
            $q=$this->db->prepare('INSERT INTO venta_eventos(venta_id,usuario_id,accion,motivo,datos_antes,datos_despues) VALUES (?,?,?,?,?,?)');
            $q->execute([$id,$claims->sub,'pago_confirmado','Confirmación de PayPal Sandbox','{"estado":"pendiente_pago"}','{"estado":"confirmada"}']);
            $result=(new Sales($this->db))->find($id,$claims); $this->db->commit();return $result;
        } catch(Throwable $error) {if($this->db->inTransaction()) $this->db->rollBack();throw $error;}
    }

    // Cancela una reserva sin cobro. Usa el mismo bloqueo que captura y anulación.
    public function cancelPending(int $id,object $claims): array
    {
        TokenService::requireRole($claims,['administrador','cajero']);
        $reason='Cancelación de pago pendiente';
        $this->db->beginTransaction();
        try {
            [$sale,$payment]=$this->lock($id,$claims);
            if($sale['estado']==='anulada' && $payment['estado']==='anulado') {
                $this->db->commit();return (new Sales($this->db))->find($id,$claims);
            }
            $resuming=$sale['estado']==='anulando' && $payment['estado']==='anulado' && ($sale['anulacion_motivo'] ?? null)===$reason;
            if(!$resuming) {
                if($sale['estado']!=='pendiente_pago' || $payment['estado']!=='pendiente' || !empty($payment['captura_externa'])) throw new HttpError(409,'La venta ya tiene un cobro o una anulación en proceso. Verifica el pago; un administrador debe anular las ventas pagadas');
                if($payment['orden_externa']!==null) {
                    $order=$this->provider->order($payment['orden_externa']);
                    $capture=PayPalClient::validate($order,$sale,$payment);
                    if($capture!==null || ($order['status'] ?? '')==='COMPLETED') throw new HttpError(409,'PayPal ya procesó un intento de cobro. Usa Verificar pago antes de solicitar una anulación');
                    $q=$this->db->prepare("SELECT 1 FROM venta_eventos WHERE venta_id=? AND accion='solicitar_cobro' LIMIT 1");$q->execute([$id]);
                    if($q->fetchColumn()!==false) throw new HttpError(409,'Hay una solicitud de cobro por resolver. Verifica el pago antes de cancelar');
                    if(!in_array($order['status'] ?? '',['CREATED','SAVED','APPROVED','VOIDED','PAYER_ACTION_REQUIRED'],true)) throw new HttpError(503,'No se pudo confirmar que el pago siga sin cobrar. Reintenta la cancelación');
                }
                $q=$this->db->prepare("UPDATE ventas SET estado='anulando',anulacion_motivo=?,anulacion_usuario_id=? WHERE id=?");
                $q->execute([$reason,$claims->sub,$id]);
                $q=$this->db->prepare("UPDATE pagos SET estado='anulado' WHERE id=?");$q->execute([$payment['id']]);
                $q=$this->db->prepare('INSERT INTO venta_eventos(venta_id,usuario_id,accion,motivo,datos_antes,datos_despues) VALUES (?,?,?,?,?,?)');
                $q->execute([$id,$claims->sub,'solicitar_cancelacion',$reason,'{"estado":"pendiente_pago"}','{"estado":"anulando"}']);
            }
            $this->db->commit();
        } catch(Throwable $error) {if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        // La intención confirmada bloquea capturas nuevas. El recibo de Inventario evita dobles devoluciones.
        InventoryClient::restore($sale['referencia'],true);
        $this->db->beginTransaction();
        try {
            [$saved,$payment]=$this->lock($id,$claims);
            if($saved['estado']==='anulando' && $payment['estado']==='anulado' && $saved['anulacion_motivo']===$reason) {
                $q=$this->db->prepare("UPDATE ventas SET estado='anulada',anulada_en=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);
                $q=$this->db->prepare('INSERT INTO venta_eventos(venta_id,usuario_id,accion,motivo,datos_antes,datos_despues) VALUES (?,?,?,?,?,?)');
                $q->execute([$id,$claims->sub,'cancelar_pago_pendiente',$reason,'{"estado":"anulando"}','{"estado":"anulada"}']);
            } elseif($saved['estado']!=='anulada') throw new HttpError(409,'La venta cambió de estado; revisa el historial');
            $result=(new Sales($this->db))->find($id,$claims);$this->db->commit();return $result;
        } catch(Throwable $error) {if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    // Solo el administrador puede cerrar una reserva o reembolsar un cobro al anular.
    public function refund(int $id,object $claims): void
    {
        TokenService::requireRole($claims,['administrador']);
        $this->db->beginTransaction(); $pending=false;
        try {
            [$sale,$payment]=$this->lock($id,$claims);
            if($sale['estado']!=='anulando') throw new HttpError(409,'Primero solicita la anulación de la venta');
            if($payment['estado']==='anulado') {$this->db->commit();return;}
            $refundId=$payment['reembolso_externo']; $captureId=$payment['captura_externa'];
            if($payment['orden_externa']!==null) {
                $order=$this->provider->order($payment['orden_externa']); $capture=PayPalClient::validate($order,$sale,$payment);
                if($capture===null && (($order['status'] ?? '')==='COMPLETED' || $payment['estado']==='aprobado')) throw new HttpError(503,'PayPal no entregó el detalle del cobro. Reintenta antes de devolver existencias');
                if($capture===null) {
                    $q=$this->db->prepare("SELECT 1 FROM venta_eventos WHERE venta_id=? AND accion='solicitar_cobro' LIMIT 1");$q->execute([$id]);
                    if($q->fetchColumn()!==false) throw new HttpError(503,'Hay un cobro por resolver en PayPal. Reintenta la anulación cuando se confirme su estado');
                }
                if($capture!==null) {
                    $captureId=$capture['id'];
                    if(!in_array($capture['status'] ?? '',['COMPLETED','REFUNDED'],true)) throw new HttpError(503,'Espera a que PayPal resuelva el cobro antes de anular');
                    if($capture['status']!=='REFUNDED' || $refundId!==null) {
                        $refund=$refundId ? $this->provider->refundStatus($refundId) : $this->provider->refund($capture['id'],$sale['referencia']);
                        if(!is_string($refund['id'] ?? null) || !preg_match('/^[A-Za-z0-9]{1,100}$/D',$refund['id'])
                            || ($refund['amount']['currency_code'] ?? '')!=='USD' || ($refund['amount']['value'] ?? null)!==$payment['importe_proveedor']) throw new HttpError(503,'El reembolso no coincide con el pago');
                        if(!in_array($refund['status'] ?? '',['PENDING','COMPLETED'],true)) throw new HttpError(503,'PayPal no completó el reembolso. Revisa la cuenta Sandbox');
                        $refundId=$refund['id']; $pending=$refund['status']!=='COMPLETED';
                    }
                }
            }
            $q=$this->db->prepare('UPDATE pagos SET estado=?,reembolso_externo=?,captura_externa=?,referencia_externa=? WHERE id=?');
            $q->execute([$pending ? 'reembolso_pendiente' : 'anulado',$refundId,$captureId,$captureId,$payment['id']]);
            $this->db->commit();
        } catch(Throwable $error) {if($this->db->inTransaction()) $this->db->rollBack();throw $error;}
        if($pending) throw new HttpError(503,'Reembolso en proceso en PayPal. Reintenta la anulación más tarde');
    }
}
