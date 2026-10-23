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
foreach (['admin'=>'administrador','cashier'=>'cajero'] as $key=>$role) {
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
    docker compose cp tests/inventario-integration.php inventario-service:/tmp/inventario-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy integration test' }
    $tokens | docker compose exec -T inventario-service php /tmp/inventario-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Inventory integration test failed' }
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
