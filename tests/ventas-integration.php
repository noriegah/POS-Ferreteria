<?php
declare(strict_types=1);
// Usa cuentas y productos temporales. Nunca modifica las existencias de productos del usuario.
require '/app/src/Database.php';
$tokens = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$db = Database::connect(); $tag = 'sale_' . bin2hex(random_bytes(8));
$category = null; $ids = []; $refs = []; $checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo "PASS: $message\n";
}
function api(string $path, string $method, ?string $token, mixed $data = null, bool $internal = false): array {
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($data !== null) $headers[] = 'Content-Type: application/json';
    if ($internal) $headers[] = 'X-Ventas-Key: ' . getenv('VENTAS_INTERNAL_KEY');
    $curl = curl_init('http://gateway/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]);
    if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($raw === false) throw new RuntimeException('HTTP request failed');
    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
}
function reference(): string {
    global $refs;
    $hex = bin2hex(random_bytes(16));
    $ref = substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
    $refs[] = $ref; return $ref;
}
// Descarga binaria separada de las solicitudes JSON.
function pdfRequest(string $id, ?string $token, string $method='GET'): array {
    $curl=curl_init('http://gateway/api/ventas/'.$id.'/factura/pdf');
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>$token ? ['Authorization: Bearer '.$token,'Accept: application/pdf'] : []]);
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); $type=curl_getinfo($curl,CURLINFO_CONTENT_TYPE); curl_close($curl);
    return [$status,$body,$type];
}
function stock(int $id): string {
    global $db;
    $q = $db->prepare('SELECT stock FROM productos WHERE id=?'); $q->execute([$id]); return $q->fetchColumn();
}
try {
    [$status,$body] = api('inventario/categorias','POST',$tokens['admin'],['nombre'=>$tag]);
    check($status === 201, 'Temporary category'); $category = (int)$body['categoria']['id'];
    foreach (['A'=>'12.50', 'B'=>'0.10'] as $suffix=>$price) {
        [$status,$body] = api('inventario/productos','POST',$tokens['admin'],[
            'categoria_id'=>$category, 'codigo'=>$tag.$suffix, 'nombre'=>$tag.$suffix,
            'precio'=>$price, 'stock'=>10, 'stock_minimo'=>2,
        ]);
        check($status === 201, 'Temporary product ' . $suffix); $ids[] = (int)$body['producto']['id'];
    }
    [$a,$b] = $ids;
    $request = ['referencia'=>reference(), 'productos'=>[['producto_id'=>$a,'cantidad'=>2],['producto_id'=>$b,'cantidad'=>3]], 'total'=>'0.01','precio'=>'0.01'];
    [$status] = api('ventas/','POST',null,$request); check($status === 401, 'Sale requires JWT');
    [$status] = api('inventario/ventas/descontar','POST',$tokens['cashier'],$request);
    check($status === 403 && stock($a) === '10.000', 'Cashier cannot bypass Sales to discount stock');
    [$status,$body] = api('ventas/','POST',$tokens['cashier'],$request); $sale = $body['venta'] ?? [];
    check($status === 201 && ($sale['total'] ?? '') === '25.30' && $sale['moneda'] === 'GTQ', 'Cashier sale uses server prices and exact total');
    // La categoría se guarda al vender, aunque después cambie su nombre en Inventario.
    check($sale['productos'][0]['categoria_nombre'] === $tag, 'Sale stores category snapshot');
    $q=$db->prepare('UPDATE categorias SET nombre=? WHERE id=?'); $q->execute([$tag.'_edit',$category]);
    [$snapshotStatus,$snapshot]=api('ventas/'.$sale['id'],'GET',$tokens['admin']);
    $q->execute([$tag,$category]);
    check($snapshotStatus===200 && $snapshot['venta']['productos'][0]['categoria_nombre']===$tag, 'Renaming inventory category preserves historical sale');
    check(stock($a) === '8.000' && stock($b) === '7.000', 'Both product stocks discounted');
    check(count($sale['productos']) === 2 && is_int($sale['productos'][0]['cantidad']), 'Details preserve integer quantities');
    [$status,$again] = api('ventas/','POST',$tokens['cashier'],$request);
    check($status === 201 && $again['venta']['id'] === $sale['id'] && stock($a) === '8.000', 'Retry returns same sale without another discount');
    [$status] = api('ventas/','POST',$tokens['admin'],$request); check($status === 409, 'Another user cannot reuse reference');
    $changed = $request; $changed['productos'][0]['cantidad'] = 1;
    [$status] = api('ventas/','POST',$tokens['cashier'],$changed); check($status === 409, 'Reference cannot change quantities');
    [$status,$found] = api('ventas/'.$sale['id'],'GET',$tokens['cashier']); check($status === 200 && $found['venta']['total'] === '25.30', 'Cashier reads own sale');
    [$status] = api('ventas/'.$sale['id'],'GET',$tokens['admin']); check($status === 200, 'Administrator reads sale');
    // IVA incluido: Q 25.30 = Q 22.59 de base + Q 2.71 de impuesto.
    $invoicePath = 'ventas/'.$sale['id'].'/factura';
    [$status] = api($invoicePath,'GET',null); check($status === 401, 'Invoice requires JWT');
    [$status,$invoiceBody] = api($invoicePath,'GET',$tokens['cashier']); $invoice = $invoiceBody['factura'] ?? [];
    check($status === 200 && $invoice['subtotal'] === '22.59' && $invoice['iva'] === '2.71' && $invoice['total'] === '25.30', 'Invoice separates included VAT without changing total');
    check($invoice['iva_porcentaje'] === 12 && $invoice['precios_incluyen_iva'] === true && $invoice['moneda'] === 'GTQ', 'Invoice declares currency and VAT convention');
    check($invoice['numero'] === 'FAC-'.str_pad((string)$sale['id'],6,'0',STR_PAD_LEFT) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$invoice['fecha']) === 1, 'Invoice number and explicit UTC date');
    check($invoice['productos'] === $sale['productos'] && $invoice['venta_id'] === (string)$sale['id'], 'Invoice keeps saved detail with integer units');
    [$status,$again] = api($invoicePath,'GET',$tokens['cashier']);
    check($status === 200 && $again['factura'] === $invoice && stock($a) === '8.000' && stock($b) === '7.000', 'Repeated invoice is stable and does not discount stock');
    [$status] = api($invoicePath,'GET',$tokens['admin']); check($status === 200, 'Administrator can read invoice');
    [$status] = api($invoicePath,'GET',$tokens['other']); check($status === 404, 'Other cashier cannot read invoice');
    [$status] = api($invoicePath,'POST',$tokens['cashier']); check($status === 405, 'Invoice only accepts GET');
    [$status] = api('ventas/999999999/factura','GET',$tokens['cashier']); check($status === 404, 'Missing invoice rejected');
    [$status] = api('ventas/0/factura','GET',$tokens['cashier']); check($status === 422, 'Zero invoice id rejected');
    // La primera línea sí tiene stock; la segunda falla. Ninguna debe quedar descontada.
    $bad = ['referencia'=>reference(), 'productos'=>[['producto_id'=>$a,'cantidad'=>1],['producto_id'=>$b,'cantidad'=>99]]];
    [$status] = api('ventas/','POST',$tokens['cashier'],$bad);
    check($status === 409 && stock($a) === '8.000' && stock($b) === '7.000', 'Insufficient stock rolls back entire cart');
    foreach ([0,-1,1.5,'1.5',true,1000000] as $quantity) {
        $bad = ['referencia'=>reference(), 'productos'=>[['producto_id'=>$a,'cantidad'=>$quantity]]];
        [$status] = api('ventas/','POST',$tokens['cashier'],$bad); check($status === 422, 'Invalid quantity rejected: ' . json_encode($quantity));
    }
    foreach ([[],[['producto_id'=>$a,'cantidad'=>1],['producto_id'=>$a,'cantidad'=>2]]] as $lines) {
        [$status] = api('ventas/','POST',$tokens['cashier'],['referencia'=>reference(),'productos'=>$lines]);
        check($status === 422, 'Empty or repeated product rejected');
    }
    // Simula pérdida de respuesta después del descuento: Ventas debe recuperar los mismos precios.
    $recover = ['referencia'=>reference(), 'productos'=>[['producto_id'=>$b,'cantidad'=>1]]];
    [$status] = api('inventario/ventas/descontar','POST',$tokens['cashier'],$recover,true);
    check($status === 200 && stock($b) === '6.000', 'Simulated confirmed inventory before sales recovery');
    $q = $db->prepare('UPDATE productos SET precio=99.99 WHERE id=?'); $q->execute([$b]);
    [$status,$recovered] = api('ventas/','POST',$tokens['cashier'],$recover);
    check($status === 201 && $recovered['venta']['total'] === '0.10' && stock($b) === '6.000', 'Recovery uses original price and no repeated discount');
    [$status,$historical] = api($invoicePath,'GET',$tokens['cashier']);
    check($status === 200 && $historical['factura'] === $invoice, 'Invoice preserves historical price after inventory price changes');
    // Dos solicitudes en paralelo con la misma referencia deben producir una sola venta.
    $parallel = ['referencia'=>reference(), 'productos'=>[['producto_id'=>$a,'cantidad'=>1]]];
    $multi = curl_multi_init(); $handles = [];
    for ($i=0;$i<2;$i++) {
        $h = curl_init('http://gateway/api/ventas/');
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($parallel),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$tokens['cashier']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        curl_multi_add_handle($multi,$h); $handles[]=$h;
    }
    do { curl_multi_exec($multi,$running); if ($running) curl_multi_select($multi,1); } while ($running);
    $results=[];
    foreach($handles as $h) { check(curl_getinfo($h,CURLINFO_HTTP_CODE)===201,'Parallel request succeeded'); $results[]=json_decode(curl_multi_getcontent($h),true); curl_multi_remove_handle($multi,$h); curl_close($h); }
    curl_multi_close($multi);
    check($results[0]['venta']['id']===$results[1]['venta']['id'] && stock($a)==='7.000','Concurrent duplicate creates one sale and one discount');
    $q=$db->prepare('SELECT cantidad,stock_anterior,stock_nuevo FROM movimientos_stock WHERE producto_id=? AND motivo LIKE ? ORDER BY id'); $q->execute([$a,'Venta %']); $moves=$q->fetchAll();
    check(count($moves)===2 && $moves[0]['cantidad']==='-2.000' && $moves[1]['stock_nuevo']==='7.000','Sale stock movements match real deductions');
    // Casos exactos y límites de redondeo: la base y el IVA siempre suman el total guardado.
    // Comprador: límite exacto, validaciones y reintentos sin repetir el descuento.
    [$status,$bp] = api('inventario/productos','POST',$tokens['admin'],['categoria_id'=>$category,'codigo'=>$tag.'_buyer','nombre'=>'Producto comprador','precio'=>'2499.99','stock'=>10,'stock_minimo'=>0]);
    check($status === 201,'Create buyer boundary fixture'); $buyerProduct=(int)$bp['producto']['id'];
    $buy=['referencia'=>reference(),'productos'=>[['producto_id'=>$buyerProduct,'cantidad'=>1]]];
    [$status,$cf] = api('ventas/','POST',$tokens['cashier'],$buy); check($status === 201 && $cf['venta']['comprador_nombre']==='CF' && $cf['venta']['comprador_nit']===null,'CF allowed at Q 2499.99');
    $q=$db->prepare('UPDATE productos SET precio=? WHERE id=?'); $q->execute(['2500.00',$buyerProduct]);
    $buy['referencia']=reference(); $buy['total']='1.00';
    [$status] = api('ventas/','POST',$tokens['cashier'],$buy); check($status === 422 && stock($buyerProduct)==='9.000','CF rejected at Q 2500.00 using server price with no stock deduction');
    $buy['comprador']=['nombre'=>'Cliente de prueba','nit'=>'1234567-8'];
    [$status] = api('ventas/','POST',$tokens['cashier'],$buy); check($status === 422 && stock($buyerProduct)==='9.000','Missing address rejected before stock deduction');
    $buy['comprador']['direccion']='Zona de prueba, Guatemala';
    [$status,$identified] = api('ventas/','POST',$tokens['cashier'],$buy); check($status === 201 && $identified['venta']['comprador_nit']==='12345678' && stock($buyerProduct)==='8.000','Identified buyer accepted at Q 2500.00');
    [$status,$repeatBuyer] = api('ventas/','POST',$tokens['cashier'],$buy); check($status === 201 && $repeatBuyer['venta']['id']===$identified['venta']['id'] && stock($buyerProduct)==='8.000','Buyer retry preserves same sale');
    $changedBuyer=$buy; $changedBuyer['comprador']['nombre']='Otro comprador';
    [$status] = api('ventas/','POST',$tokens['cashier'],$changedBuyer); check($status === 409 && stock($buyerProduct)==='8.000','Reference cannot be reused with changed buyer');
    [$status] = pdfRequest((string)$identified['venta']['id'],null); check($status===401,'PDF requires JWT');
    [$status] = pdfRequest((string)$identified['venta']['id'],$tokens['other']); check($status===404,'Other cashier cannot download PDF');
    [$status,$pdf,$mime] = pdfRequest((string)$identified['venta']['id'],$tokens['cashier']);
    check($status===200 && $mime==='application/pdf' && str_starts_with($pdf,'%PDF-'),'Owner downloads a real PDF');
    [$status] = pdfRequest((string)$identified['venta']['id'],$tokens['admin']); check($status===200,'Administrator downloads PDF');
    [$status] = pdfRequest((string)$cf['venta']['id'],$tokens['cashier']); check($status===200,'CF invoice downloads as PDF');
    [$status] = pdfRequest((string)$identified['venta']['id'],$tokens['cashier'],'POST'); check($status===405,'PDF only accepts GET');
    check(stock($buyerProduct)==='8.000','Downloading PDFs does not discount stock');
    $q->execute(['2500.01',$buyerProduct]);
    [$status] = api('ventas/','POST',$tokens['cashier'],['referencia'=>reference(),'productos'=>[['producto_id'=>$buyerProduct,'cantidad'=>1]]]); check($status === 422 && stock($buyerProduct)==='8.000','CF rejected above Q 2500.00');
    foreach(['nit'=>'CF','nombre'=>'','direccion'=>[]] as $field=>$bad) {
        $invalid=$buy; $invalid['referencia']=reference(); $invalid['comprador'][$field]=$bad;
        [$status] = api('ventas/','POST',$tokens['cashier'],$invalid); check($status===422,'Invalid buyer field rejected: '.$field);
    }
    foreach ([['112.00','100.00','12.00'],['0.00','0.00','0.00'],['0.01','0.01','0.00'],['0.05','0.04','0.01'],['0.10','0.09','0.01'],['9999999999.99','8928571428.56','1071428571.43']] as [$price,$base,$tax]) {
        $q=$db->prepare('UPDATE productos SET precio=? WHERE id=?'); $q->execute([$price,$b]);
        $data=['referencia'=>reference(),'productos'=>[['producto_id'=>$b,'cantidad'=>1]]];
        if ((float)$price>=2500) $data['comprador']=['nombre'=>'Cliente de prueba','nit'=>'1234567-8','direccion'=>'Zona de prueba, Guatemala'];
        [$status,$case] = api('ventas/','POST',$tokens['cashier'],$data);
        check($status === 201, 'Create VAT rounding case '.$price);
        [$status,$case] = api('ventas/'.$case['venta']['id'].'/factura','GET',$tokens['cashier']);
        check($status === 200 && $case['factura']['subtotal'] === $base && $case['factura']['iva'] === $tax && $case['factura']['total'] === $price, 'Exact VAT rounding for '.$price);
    }
    foreach (['auth','inventario','ventas','reportes'] as $service) {
        [$status]=api($service.'/health','GET',null); check($status===200,'Health unchanged: '.$service);
    }
    [$status]=api('ventas/ready','GET',null); check($status===200,'Sales database ready');
    [$status]=api('ventas/','GET',$tokens['cashier']); check($status===200,'Cashier can list own sales');
    [$status]=api('ventas/999999999','GET',$tokens['cashier']); check($status===404,'Missing sale rejected');
    echo "Sales checks passed: $checks\n";
} finally {
    // Borra únicamente los descuentos y productos que creó esta ejecución.
    $q=$db->prepare('DELETE FROM descuentos_venta WHERE referencia=?'); foreach($refs as $ref) $q->execute([$ref]);
    if ($category !== null) {
        $q=$db->prepare('DELETE m FROM movimientos_stock m JOIN productos p ON p.id=m.producto_id WHERE p.categoria_id=?'); $q->execute([$category]);
        $q=$db->prepare('DELETE FROM productos WHERE categoria_id=?'); $q->execute([$category]);
        $q=$db->prepare('DELETE FROM categorias WHERE id=? AND nombre=?'); $q->execute([$category,$tag]);
    }
    echo "Temporary sale products removed.\n";
}
