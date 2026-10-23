<?php
declare(strict_types=1);
// La venta de prueba se crea y elimina exclusivamente en ventas_db.
require '/app/src/Database.php';
require '/app/src/integrations/QrCodeClient.php';
$tokens = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$db = Database::connect(); $id = null; $cached = null;
$reference = bin2hex(random_bytes(16));
$reference = substr($reference,0,8).'-'.substr($reference,8,4).'-4'.substr($reference,13,3).'-8'.substr($reference,17,3).'-'.substr($reference,20,12);
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function request(string $path, ?string $token, string $method = 'GET'): array {
    $curl = curl_init(str_starts_with($path,'/') ? 'http://gateway'.$path : 'http://gateway/api/'.$path);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$token ? ['Authorization: Bearer '.$token] : [],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
    $data = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl); return [$status, $data, $type];
}
try {
    $q = $db->prepare('INSERT INTO ventas(referencia,usuario_id,solicitud_hash,total) VALUES (?,?,?,?)');
    $q->execute([$reference,$tokens['ids'][1],hash('sha256',$reference),'112.00']); $id = (int)$db->lastInsertId();
    $q = $db->prepare('INSERT INTO detalle_ventas(venta_id,producto_id,codigo,nombre,cantidad,precio,subtotal) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$id,1,'QR-DEMO','Producto de demostración',2,'56.00','112.00']);
    $path = 'ventas/'.$id.'/factura/qr';
    $invoice = ['numero'=>'FAC-'.str_pad((string)$id,6,'0',STR_PAD_LEFT), 'referencia'=>$reference];
    $cached = '/app/storage/facturas-qr/' . hash('sha256',QrCodeClient::content($invoice)) . '.png';
    [$status] = request($path,null); check($status === 401,'QR requires JWT');
    [$status] = request($path,$tokens['other']); check($status === 404,'Other cashier cannot read QR');
    [$status] = request('ventas/999999999/factura/qr',$tokens['admin']); check($status === 404,'Missing invoice QR rejected');
    [$status] = request('ventas/0/factura/qr',$tokens['admin']); check($status === 422,'Zero invoice id rejected');
    [$status] = request($path,$tokens['cashier'],'POST'); check($status === 405,'QR route only accepts GET');
    [$status,$before] = request('ventas/'.$id.'/factura',$tokens['cashier']); check($status === 200,'Invoice available before QR');
    [$status,$png,$type] = request($path,$tokens['cashier']);
    check($status === 200 && $type === 'image/png' && is_string($png) && getimagesizefromstring($png)[0] === 240,'Owner receives QR PNG');
    [$status,$again] = request($path,$tokens['admin']); check($status === 200 && $again === $png,'Administrator receives the same cached QR');
    check(is_file($cached) && file_get_contents($cached) === $png,'QR survives in private persistent folder');
    [$status,$public,$publicType] = request('/uploads/facturas-qr/'.basename($cached),null);
    check($publicType !== 'image/png','QR cache is not exposed as a public image folder');
    [$status,$after] = request('ventas/'.$id.'/factura',$tokens['cashier']); check($status === 200 && $before === $after,'QR does not alter invoice or totals');
    foreach (['auth','inventario','ventas','reportes'] as $service) {
        [$status] = request($service.'/health',null); check($status === 200,'Health unchanged: '.$service);
    }
} finally {
    if ($cached !== null && is_file($cached)) unlink($cached);
    if ($id !== null) {
        $q=$db->prepare('DELETE FROM detalle_ventas WHERE venta_id=?'); $q->execute([$id]);
        $q=$db->prepare('DELETE FROM ventas WHERE id=? AND referencia=?'); $q->execute([$id,$reference]);
    }
    echo "Temporary QR sale and PNG removed.\n";
}
