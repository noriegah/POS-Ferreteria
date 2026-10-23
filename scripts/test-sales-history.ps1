$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
$tokens = $null
try {
    # La verificación REST exige usuarios existentes. Estas cuentas se borran en finally.
    $issuer = @'
<?php
require '/app/vendor/autoload.php';
require '/app/src/TokenService.php';
require '/app/src/Database.php';
$tag='crud_users_'.bin2hex(random_bytes(8));
$db=Database::connect(); $result=['tag'=>$tag,'ids'=>[]];
$db->beginTransaction();
try {
foreach (['admin'=>'administrador','cashier'=>'cajero','other'=>'cajero'] as $key=>$role) {
    $q=$db->prepare('INSERT INTO usuarios(nombre,email,password_hash,rol) VALUES (?,?,?,?)');
    $q->execute([$tag,$tag.'_'.$key.'@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_BCRYPT),$role]);
    $id=(string)$db->lastInsertId(); $result['ids'][]=$id;
    $result[$key]=TokenService::issue(['id'=>$id,'rol'=>$role]);
}
$db->commit();
echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
'@
    $tokens = $issuer | docker compose exec -T auth-service php
    if ($LASTEXITCODE -ne 0) { throw 'Cannot generate temporary test tokens' }
    docker compose cp tests/sales-history-integration.php ventas-service:/tmp/sales-history-integration.php
    if($LASTEXITCODE -ne 0){throw 'Cannot copy reports test'}
    $tokens | docker compose exec -T ventas-service php /tmp/sales-history-integration.php
    if($LASTEXITCODE -ne 0){throw 'Sales history test failed'}
    # Simula una URL caída solo en este proceso de prueba; no detiene ningún contenedor.
    $outage = @'
require '/app/vendor/autoload.php';
require '/app/src/TokenService.php';
require '/app/src/AuthClient.php';
$data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$data['admin'];
putenv('AUTH_URL=http://127.0.0.1:1');
try { AuthClient::current(); throw new RuntimeException('Unavailable auth was accepted'); }
catch(HttpError $e) { if($e->status!==503) throw $e; echo "PASS: Auth unavailable returns 503 without allowing access.\n"; }
'@
    $tokens | docker compose exec -T inventario-service php -r $outage
    if ($LASTEXITCODE -ne 0) { throw 'Auth outage test failed' }
} finally {
    # Borra exclusivamente las cuentas con el id y nombre aleatorio que creó esta ejecución.
    if ($tokens) {
        # Ventas limpia su propia base antes de eliminar las cuentas temporales.
        $salesCleanup = @'
require '/app/src/Database.php';
$data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$db=Database::connect(); $db->beginTransaction();
require '/app/src/integrations/QrCodeClient.php';
foreach($data['ids'] as $id) {
    $q=$db->prepare('SELECT DISTINCT cliente_id FROM ventas WHERE usuario_id=? AND cliente_id IS NOT NULL'); $q->execute([$id]); $clientIds=$q->fetchAll(PDO::FETCH_COLUMN);
    $q=$db->prepare('SELECT id,referencia FROM ventas WHERE usuario_id=?'); $q->execute([$id]);
    foreach($q->fetchAll() as $sale) {
        $content=QrCodeClient::content(['numero'=>'FAC-'.str_pad((string)$sale['id'],6,'0',STR_PAD_LEFT),'referencia'=>$sale['referencia']]);
        $file='/app/storage/facturas-qr/'.hash('sha256',$content).'.png'; if(is_file($file)) unlink($file);
    }
    $q=$db->prepare('DELETE d FROM detalle_ventas d JOIN ventas v ON v.id=d.venta_id WHERE v.usuario_id=?'); $q->execute([$id]);
    $q=$db->prepare('DELETE FROM ventas WHERE usuario_id=?'); $q->execute([$id]);
    foreach($clientIds as $clientId) { $q=$db->prepare('DELETE FROM clientes WHERE id=? AND NOT EXISTS (SELECT 1 FROM ventas WHERE cliente_id=?)'); $q->execute([$clientId,$clientId]); }
}
$db->commit(); echo "Temporary sales removed.\n";
'@
        $tokens | docker compose exec -T ventas-service php -r $salesCleanup
        if ($LASTEXITCODE -ne 0) { Write-Error 'No se pudieron limpiar las ventas temporales' }
        $cleanup = @'
require '/app/src/Database.php';
$data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$q=Database::connect()->prepare('DELETE FROM usuarios WHERE id=? AND nombre=?');
foreach($data['ids'] as $id) { $q->execute([$id,$data['tag']]); }
echo "Temporary CRUD users removed.\n";
'@
        $tokens | docker compose exec -T auth-service php -r $cleanup
        if ($LASTEXITCODE -ne 0) { Write-Error 'No se pudieron limpiar las cuentas temporales' }
    }
    $tokens = $null
    Pop-Location
}
