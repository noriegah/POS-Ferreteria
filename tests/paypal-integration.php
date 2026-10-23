<?php
declare(strict_types=1);
// Prueba local con Inventario real y respuestas controladas del contrato PayPal.
// No representa una transacción realizada contra la API externa.
require '/app/vendor/autoload.php';
foreach(['Database','TokenService','AuthClient','Checkout','Buyer','Customers','InventoryClient','Sales','SalesReports','Payments','SaleChanges'] as $class) require '/app/src/'.$class.'.php';
require '/app/src/integrations/PayPalClient.php';
$tokens=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$checks=0;
function check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function api(string $path,string $method,string $token,?array $body=null): array {
    $curl=curl_init('http://gateway/api/'.$path);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]]);
    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($raw===false)throw new RuntimeException('No se pudo consultar la API');
    return [$status,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];
}
function reference(): string {$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20);}
function errorCode(Closure $operation,int $expected,string $label): void {
    $caught=false;try{$operation();}catch(HttpError $e){$caught=$e->status===$expected;}check($caught,$label);
}
function stock(int|string $id): int {global $tokens;[$code,$body]=api('inventario/productos/'.$id,'GET',$tokens['admin']);if($code!==200)throw new RuntimeException('No se pudo leer stock');return (int)$body['producto']['stock'];}
function state(PDO $db,string $id): string {$q=$db->prepare('SELECT estado FROM ventas WHERE id=?');$q->execute([$id]);return $q->fetchColumn();}
$db=Database::connect();$cash=TokenService::verify($tokens['cashier']);$admin=TokenService::verify($tokens['admin']);$other=TokenService::verify($tokens['other']);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['cashier'];
[$code]=api('ventas/pagos/config','GET','');check($code===401,'La configuración de pago requiere JWT');
[$code,$config]=api('ventas/pagos/config','GET',$tokens['cashier']);check($code===200 && array_keys($config)===['paypal_disponible','tasa_demo','moneda'],'La configuración pública no expone el secreto');
$tag='repl_'.$tokens['tag'];[$code,$data]=api('inventario/categorias','POST',$tokens['admin'],['nombre'=>$tag]);check($code===201,'Categoría de prueba creada');$category=$data['categoria']['id'];
[$code,$data]=api('inventario/productos','POST',$tokens['admin'],['categoria_id'=>$category,'codigo'=>$tag,'nombre'=>'Herramienta PayPal de prueba','precio'=>'78.00','stock'=>20,'stock_minimo'=>1]);check($code===201,'Producto de prueba creado');$product=$data['producto']['id'];
$request=(object)['referencia'=>reference(),'productos'=>[(object)['producto_id'=>$product,'cantidad'=>2]],'metodo_pago'=>'tarjeta'];
$before=getenv('PAYPAL_CLIENT_ID');putenv('PAYPAL_CLIENT_ID=');
errorCode(fn()=>(new Sales($db))->create($request,$cash),503,'Sin credenciales no se inicia una reserva');check(stock($product)===20,'Sin credenciales no se descuenta stock');
putenv('PAYPAL_CLIENT_ID=sandbox_unit_client');putenv('PAYPAL_CLIENT_SECRET=sandbox_unit_secret');putenv('PAYPAL_USD_RATE=7.80');putenv('PAYPAL_RETURN_URL=http://localhost:8080/pago-retorno.html');
check(PayPalClient::dollars('156.00','7.80')==='20.00','La conversión de demostración usa centavos exactos');
check(PayPalClient::dollars('0.04','7.80')==='0.01','La conversión redondea el mínimo a un centavo');
errorCode(fn()=>PayPalClient::dollars('0.01','7.80'),422,'No acepta un pago convertido a cero');
$sales=new Sales($db);$sale=$sales->create($request,$cash);$id=(string)$sale['id'];
check($sale['estado']==='pendiente_pago' && $sale['pago']['estado']==='pendiente','La venta queda pendiente de pago');
check($sale['total']==='156.00' && $sale['pago']['importe_proveedor']==='20.00','La venta conserva Q y el pago guarda USD');
check(stock($product)===18,'La reserva aparta las unidades una sola vez');
$again=$sales->create($request,$cash);check((string)$again['id']===$id && stock($product)===18,'Reintentar recupera la misma reserva');
errorCode(fn()=>$sales->invoice((int)$id,$cash),409,'Una reserva pendiente no emite factura');
$day=(new DateTimeImmutable('now',new DateTimeZone('America/Guatemala')))->format('Y-m-d');
$reportHasProduct=function() use($db,$day,$product): bool {
    $report=SalesReports::summary($db,['desde'=>$day,'hasta'=>$day]);
    return count(array_filter($report['productos'],fn($line)=>(string)$line['producto_id']===(string)$product))>0;
};
check(!$reportHasProduct(),'La reserva no aparece como venta en los reportes');

$changed=clone $request;$changed->metodo_pago='efectivo';errorCode(fn()=>$sales->create($changed,$cash),409,'La referencia de tarjeta no puede convertirse en efectivo');
// El estado del proveedor vive solo en esta prueba, fuera de la transacción SQL.
$remote=[];$counts=['create'=>0,'capture'=>0,'refund'=>0];$loseCapture=false;$loseRefund=false;$pendingRefund=false;$requests=[];
$transport=function(string $method,string $path,mixed $body,array $headers,?string $basic) use (&$remote,&$counts,&$loseCapture,&$loseRefund,&$pendingRefund,&$requests): array {
    $requests[]=[$method,$path,$headers];
    if($path==='/v1/oauth2/token')return [200,['access_token'=>'unit_access_token']];
    if($path==='/v2/checkout/orders' && $method==='POST') {
        $counts['create']++;$oid='UNITORDER'.$counts['create'];
        $remote[$oid]=['id'=>$oid,'status'=>'CREATED','purchase_units'=>$body['purchase_units'],'links'=>[['rel'=>'payer-action','href'=>'https://www.sandbox.paypal.com/checkoutnow?token='.$oid]]];
        return [201,['id'=>$oid,'status'=>'CREATED','links'=>$remote[$oid]['links']]];
    }
    if(preg_match('#^/v2/checkout/orders/(UNITORDER[0-9]+)(/capture)?$#D',$path,$m)) {
        $oid=$m[1];if(!isset($remote[$oid]))throw new RuntimeException('Orden de prueba desconocida');
        if($method==='POST') {
            $counts['capture']++;$remote[$oid]['status']='COMPLETED';
            $remote[$oid]['purchase_units'][0]['payments']['captures']=[['id'=>'UNITCAP'.$oid,'status'=>'COMPLETED','amount'=>$remote[$oid]['purchase_units'][0]['amount']]];
            if($loseCapture){$loseCapture=false;throw new HttpError(503,'Respuesta de captura perdida en prueba');}
        }
        return [200,$method==='POST' ? ['id'=>$oid,'status'=>'COMPLETED'] : $remote[$oid]];
    }
    if(preg_match('#^/v2/payments/captures/UNITCAP(UNITORDER[0-9]+)/refund$#D',$path,$m)) {
        $counts['refund']++;$oid=$m[1];
        $refund=['id'=>'UNITREF'.$oid,'status'=>$pendingRefund ? 'PENDING' : 'COMPLETED','amount'=>$remote[$oid]['purchase_units'][0]['amount']];
        $remote[$oid]['refund']=$refund;
        if(!$pendingRefund)$remote[$oid]['purchase_units'][0]['payments']['captures'][0]['status']='REFUNDED';
        if($loseRefund){$loseRefund=false;throw new HttpError(503,'Respuesta de reembolso perdida en prueba');}
        return [201,$refund];
    }
    if(preg_match('#^/v2/payments/refunds/UNITREF(UNITORDER[0-9]+)$#D',$path,$m))return [200,$remote[$m[1]]['refund']];
    throw new RuntimeException('Solicitud de prueba inesperada: '.$path);
};
$provider=new PayPalClient($transport);$payments=new Payments($db,$provider);$changes=new SaleChanges($db,$payments);
errorCode(fn()=>$payments->order((int)$id,$other),404,'Otro cajero no puede preparar el pago');
$prepared=$payments->order((int)$id,$cash);check($prepared['importe_usd']==='20.00' && str_starts_with($prepared['url'],'https://www.sandbox.paypal.com/'),'La orden muestra moneda y enlace Sandbox');
$payments->order((int)$id,$cash);check($counts['create']===1,'Reintentar preparación no crea otra orden');
errorCode(fn()=>$payments->capture((int)$id,$cash),409,'No se confirma una orden sin autorización');
check($counts['capture']===0 && state($db,$id)==='pendiente_pago','La venta sigue pendiente si el comprador no autorizó');
$oid='UNITORDER1';$remote[$oid]['status']='APPROVED';$remote[$oid]['purchase_units'][0]['amount']['value']='1.00';
errorCode(fn()=>$payments->capture((int)$id,$cash),503,'Una moneda o importe diferente no confirma la venta');
$remote[$oid]['purchase_units'][0]['amount']['value']='20.00';
$remote[$oid]['purchase_units'][0]['amount']['currency_code']='EUR';
errorCode(fn()=>$payments->capture((int)$id,$cash),503,'La moneda del proveedor también debe coincidir');
$remote[$oid]['purchase_units'][0]['amount']['currency_code']='USD';$loseCapture=true;
errorCode(fn()=>$payments->capture((int)$id,$cash),503,'Se informa una respuesta de captura perdida');
check(state($db,$id)==='pendiente_pago','Una respuesta perdida no marca localmente el pago como aprobado');
$paid=$payments->capture((int)$id,$cash);check($paid['estado']==='confirmada' && $paid['pago']['estado']==='aprobado','Consultar PayPal recupera el cobro ya completado');
check($counts['capture']===1 && stock($product)===18,'Recuperar el pago no cobra ni descuenta dos veces');
check($reportHasProduct(),'El cobro confirmado habilita la venta en reportes');
$payments->capture((int)$id,$cash);check($counts['capture']===1,'Una verificación repetida es idempotente');
$invoice=$sales->invoice((int)$id,$cash);check($invoice['total']==='156.00' && $invoice['metodo_pago']==='tarjeta','La factura pagada conserva los importes en quetzales');
errorCode(fn()=>$changes->cancel((int)$id,(object)['motivo'=>'Anulación de prueba'],$cash),403,'El cajero no puede anular ni reembolsar');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['admin'];$loseRefund=true;
errorCode(fn()=>$changes->cancel((int)$id,(object)['motivo'=>'Anulación controlada Sandbox'],$admin),503,'Una falla de reembolso deja una anulación recuperable');
check(state($db,$id)==='anulando' && stock($product)===18,'No se devuelve stock antes de confirmar el reembolso');
$cancelled=$changes->cancel((int)$id,(object)['motivo'=>'Anulación controlada Sandbox'],$admin);
check($cancelled['estado']==='anulada' && $cancelled['pago']['estado']==='anulado','Se recupera la anulación tras confirmar el reembolso');
check($counts['refund']===1 && stock($product)===20,'El reembolso y la devolución de stock ocurren una sola vez');
check($sales->invoice((int)$id,$admin)['estado']==='anulada','El comprobante pagado conserva su anulación después del reembolso');
check(!$reportHasProduct(),'La venta reembolsada y anulada deja de sumar en reportes');
$changes->cancel((int)$id,(object)['motivo'=>'Anulación controlada Sandbox'],$admin);check($counts['refund']===1 && stock($product)===20,'Repetir anulación no repite reembolso ni stock');
// Una reserva sin orden externa se puede liberar sin contactar con PayPal.
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['cashier'];$request->referencia=reference();$unpaid=$sales->create($request,$cash);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['admin'];$beforeCount=count($requests);$changes->cancel((int)$unpaid['id'],(object)['motivo'=>'Cancelar reserva sin orden'],$admin);
check(count($requests)===$beforeCount && stock($product)===20,'Se anula una reserva sin orden ni cobro externo');
errorCode(fn()=>$sales->invoice((int)$unpaid['id'],$admin),409,'Anular una reserva sin cobro no crea una factura');
// El estado de reembolso pendiente se conserva para consultar el mismo reembolso después.
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['cashier'];$request->referencia=reference();$third=$sales->create($request,$cash);
$payments->order((int)$third['id'],$cash);$thirdOrder='UNITORDER2';$remote[$thirdOrder]['status']='APPROVED';$payments->capture((int)$third['id'],$cash);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['admin'];$pendingRefund=true;
errorCode(fn()=>$changes->cancel((int)$third['id'],(object)['motivo'=>'Reembolso pendiente de prueba'],$admin),503,'Un reembolso pendiente no se presenta como completado');
$q=$db->prepare('SELECT estado,reembolso_externo FROM pagos WHERE venta_id=?');$q->execute([$third['id']]);$p=$q->fetch();
check($p['estado']==='reembolso_pendiente' && $p['reembolso_externo']!==null && stock($product)===18,'Se guarda el identificador del reembolso antes de reintentar');
$refundsBefore=$counts['refund'];$remote[$thirdOrder]['refund']['status']='COMPLETED';$remote[$thirdOrder]['purchase_units'][0]['payments']['captures'][0]['status']='REFUNDED';
$changes->cancel((int)$third['id'],(object)['motivo'=>'Reembolso pendiente de prueba'],$admin);
check($counts['refund']===$refundsBefore && stock($product)===20,'Se consulta el reembolso guardado sin crear otro');
// El mínimo se comprueba dentro de Inventario antes de confirmar su transacción.
[$code,$data]=api('inventario/productos','POST',$tokens['admin'],['categoria_id'=>$category,'codigo'=>$tag.'_tiny','nombre'=>'Importe mínimo de prueba','precio'=>'0.01','stock'=>5,'stock_minimo'=>1]);$tiny=$data['producto']['id'];
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['cashier'];$small=(object)['referencia'=>reference(),'productos'=>[(object)['producto_id'=>$tiny,'cantidad'=>1]],'metodo_pago'=>'tarjeta'];
errorCode(fn()=>$sales->create($small,$cash),422,'Un importe demasiado pequeño se rechaza antes de reservar');check(stock($tiny)===5,'El mínimo rechazado no deja existencias apartadas');
errorCode(fn()=>PayPalClient::approval(['links'=>[['rel'=>'approve','href'=>'https://www.paypal.com/checkout']]]),503,'No se permite abrir el sitio de producción como Sandbox');
$postRequests=array_filter($requests,fn($row)=>$row[0]==='POST' && $row[1]!=='/v1/oauth2/token');
check(count(array_filter($postRequests,fn($row)=>count(array_filter($row[2],fn($header)=>str_starts_with($header,'PayPal-Request-Id: ')))===1))===count($postRequests),'Crear, capturar y reembolsar utilizan claves de idempotencia');
echo "PayPal local: $checks comprobaciones correctas; falta validación externa con credenciales propias.\n";

