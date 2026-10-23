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
$tag='qr_users_'.bin2hex(random_bytes(8));
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
    foreach ($name in @('qr-client','qr-http')) {
        docker compose cp "tests/integrations/$name.php" "ventas-service:/tmp/$name.php"
        if ($LASTEXITCODE -ne 0) { throw 'Cannot copy QR test' }
        if ($name -eq 'qr-client') { docker compose exec -T ventas-service php "/tmp/$name.php" }
        else { $tokens | docker compose exec -T ventas-service php "/tmp/$name.php" }
        if ($LASTEXITCODE -ne 0) { throw "QR test failed: $name" }
    }
} finally {
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
