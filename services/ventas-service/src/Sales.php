<?php
declare(strict_types=1);

// Guarda la venta y sus detalles en ventas_db, con precios confirmados por Inventario.
final class Sales
{
    public function __construct(private PDO $db) {}

    public function find(int $id, object $claims): array
    {
        $q = $this->db->prepare('SELECT id,referencia,usuario_id,total,moneda,metodo_pago,iva,cliente_id,estado,anulacion_motivo,anulacion_usuario_id,anulada_en,creado_en,UNIX_TIMESTAMP(creado_en) AS fecha_unix,comprador_nombre,comprador_nit,comprador_direccion FROM ventas WHERE id=? AND total IS NOT NULL');
        $q->execute([$id]); $sale = $q->fetch();
        if (!$sale || ($claims->role !== 'administrador' && (string)$sale['usuario_id'] !== $claims->sub)) {
            throw new HttpError(404, 'Venta no encontrada');
        }
        $q = $this->db->prepare('SELECT producto_id,codigo,nombre,cantidad,precio,subtotal,categoria_nombre FROM detalle_ventas WHERE venta_id=? ORDER BY producto_id');
        $q->execute([$id]); $sale['productos'] = $q->fetchAll();
        foreach ($sale['productos'] as &$line) { $line['cantidad'] = (int)$line['cantidad']; }
        $q=$this->db->prepare('SELECT estado,proveedor,importe_proveedor,moneda_proveedor,tasa_demo,captura_externa FROM pagos WHERE venta_id=?');
        $q->execute([$id]); $sale['pago']=$q->fetch() ?: null;
        return $sale;
    }

    // La factura usa los datos guardados de la venta, aunque luego cambie el producto.
    public function invoice(int $id, object $claims): array
    {
        $sale = $this->find($id, $claims);
        if($sale['estado']==='pendiente_pago' || ($sale['metodo_pago']==='tarjeta' && empty($sale['pago']['captura_externa']))) throw new HttpError(409,'La factura estará disponible al confirmar el pago');
        $total = Checkout::cents($sale['total']);
        // Precios con IVA incluido: base = total / 1.12. Redondea una vez a centavos.
        $base = intdiv($total * 100 + 56, 112);
        $tax = $total - $base;
        return [
            'metodo_pago'=>$sale['metodo_pago'],'pago'=>$sale['pago'],
            'estado'=>$sale['estado'],'anulacion_motivo'=>$sale['anulacion_motivo'],
            'numero'=>'FAC-' . str_pad((string)$sale['id'], 6, '0', STR_PAD_LEFT),
            'venta_id'=>(string)$sale['id'], 'referencia'=>$sale['referencia'],
            'fecha'=>gmdate('Y-m-d\TH:i:s\Z', (int)$sale['fecha_unix']),
            'usuario_id'=>(string)$sale['usuario_id'], 'moneda'=>$sale['moneda'],
            'precios_incluyen_iva'=>true, 'iva_porcentaje'=>12,
            'subtotal'=>Checkout::money($base), 'iva'=>Checkout::money($tax),
            'total'=>$sale['total'], 'productos'=>$sale['productos'],
            'comprador'=>['nombre'=>$sale['comprador_nombre'],'nit'=>$sale['comprador_nit'],'direccion'=>$sale['comprador_direccion']],
        ];
    }

    public function create(object $data, object $claims): array
    {
        [$reference, $items] = Checkout::validate($data);
        $method=$data->metodo_pago ?? 'efectivo';
        if(!in_array($method,['efectivo','tarjeta'],true)) throw new HttpError(422,'Método de pago inválido');
        $rate=null; $minimum=0;
        if($method==='tarjeta') {
            PayPalClient::requireConfiguration(); $rate=PayPalClient::rate();
            [$whole,$fraction]=array_pad(explode('.',$rate,2),2,'');
            $scaled=(int)$whole*10000+(int)str_pad($fraction,4,'0');
            $minimum=intdiv($scaled+19999,20000);
        }
        $buyer = Buyer::normalize($data->comprador ?? null);
        $legacyHash = Checkout::hash($claims->sub, $items);
        // Vincula también el comprador a los reintentos de esta misma compra.
        $hash = hash('sha256', json_encode($method==='efectivo' ? [$legacyHash,$buyer] : [$legacyHash,$buyer,$method], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('INSERT INTO ventas(referencia,usuario_id,solicitud_hash,comprador_nombre,comprador_nit,comprador_direccion,metodo_pago) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE referencia=VALUES(referencia)');
            $q->execute([$reference, $claims->sub, $hash,$buyer['nombre'],$buyer['nit'],$buyer['direccion'],$method]);
            $q = $this->db->prepare('SELECT * FROM ventas WHERE referencia=? FOR UPDATE');
            $q->execute([$reference]); $sale = $q->fetch(); $id = (int)$sale['id'];
            $oldCf = $method==='efectivo' && $sale['metodo_pago']==='efectivo' && $buyer['nit']===null && $sale['comprador_nit']===null && $sale['solicitud_hash']===$legacyHash;
            if ((string)$sale['usuario_id'] !== $claims->sub || ($sale['solicitud_hash'] !== $hash && !$oldCf)) {
                throw new HttpError(409, 'La referencia ya corresponde a otra compra');
            }
            if ($sale['total'] === null) {
                $cash=$method==='efectivo' ? (new CashRegister($this->db))->active($claims->sub) : null;
                // Si se corta la respuesta entre servicios, la misma referencia recupera el descuento ya hecho.
                $confirmed = InventoryClient::discount($reference, $items, $buyer['nit']!==null,$minimum);
                Buyer::requireForTotal($buyer, $confirmed['total']);
                $q = $this->db->prepare('INSERT INTO detalle_ventas(venta_id,producto_id,codigo,nombre,cantidad,precio,subtotal,categoria_nombre) VALUES (?,?,?,?,?,?,?,?)');
                foreach ($confirmed['productos'] as $line) {
                    $q->execute([$id, $line['producto_id'], $line['codigo'], $line['nombre'], $line['cantidad'], $line['precio'], $line['subtotal'], $line['categoria_nombre'] ?? null]);
                }
                // Registra cliente y cobro en la misma transacción de la venta.
                $client=Customers::save($this->db,$buyer);
                $cents=Checkout::cents($confirmed['total']); $tax=$cents-intdiv($cents*100+56,112);
                $q = $this->db->prepare('UPDATE ventas SET total=?,iva=?,cliente_id=?,estado=? WHERE id=?');
                $q->execute([$confirmed['total'],Checkout::money($tax),$client,$method==='tarjeta' ? 'pendiente_pago' : 'confirmada',$id]);
                $q=$this->db->prepare('INSERT INTO pagos(venta_id,metodo,proveedor,importe,moneda,estado,importe_proveedor,moneda_proveedor,tasa_demo) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$id,$method,$method==='tarjeta' ? 'paypal_sandbox' : 'caja',$confirmed['total'],'GTQ',
                    $method==='tarjeta' ? 'pendiente' : 'aprobado',$method==='tarjeta' ? PayPalClient::dollars($confirmed['total'],$rate) : null,
                    $method==='tarjeta' ? 'USD' : null,$rate]);
                if($cash!==null) (new CashRegister($this->db))->movement((int)$cash['id'],$id,'venta',$confirmed['total']);
            }
            $result = $this->find($id, $claims);
            $this->db->commit(); return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
