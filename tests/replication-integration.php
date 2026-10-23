<?php
declare(strict_types=1);
// Registra una compra con un producto temporal y comprueba la copia en la segunda instancia.
require '/app/src/Database.php'; require '/app/src/TokenService.php';
$tokens=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR); $checks=0;
function check(bool $value,string $message): void {
    global $checks; if(!$value) throw new RuntimeException('FAIL: '.$message);
    $checks++; echo "PASS: $message\n";
}
function api(string $path,string $method,string $token,?array $data=null): array {
    $curl=curl_init('http://gateway/api/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json']]);
    if($data!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));
    $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if($raw===false) throw new RuntimeException('Request failed');
    return [$status,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];
}
$primary=Database::connect(); $replica=Database::reports();
check((int)$primary->query('SELECT @@server_id')->fetchColumn()===1,'Escrituras conectadas al servidor 1');
check((int)$primary->query('SELECT @@log_bin')->fetchColumn()===1,'Bitácora binaria activa en la primaria');
check((int)$replica->query('SELECT @@server_id')->fetchColumn()===2,'Reportes conectados al servidor 2');
check((int)$replica->query('SELECT @@read_only')->fetchColumn()===1,'Réplica configurada en solo lectura');
check($replica->query('SELECT DATABASE()')->fetchColumn()==='ventas_db','Cuenta de reportes utiliza ventas_db');
$status=$replica->query('SHOW SLAVE STATUS')->fetch();
check($status['Slave_IO_Running']==='Yes' && $status['Slave_SQL_Running']==='Yes','Hilos de recepción y aplicación activos');
$denied=false;
try {$replica->exec('UPDATE ventas SET total=total WHERE 1=0');}
catch(PDOException $e) {$denied=in_array((int)($e->errorInfo[1] ?? 0),[1142,1290],true);}
check($denied,'La cuenta de reportes no puede escribir');
$denied=false;
try {$replica->query('SELECT COUNT(*) FROM mysql.user');}
catch(PDOException $e) {$denied=(int)($e->errorInfo[1] ?? 0)===1142;}
check($denied,'La cuenta de reportes no consulta cuentas SQL');
$tag='repl_'.$tokens['tag'];
[$code,$data]=api('inventario/categorias','POST',$tokens['admin'],['nombre'=>$tag]);
check($code===201,'Categoría temporal creada por API'); $category=$data['categoria']['id'];
[$code,$data]=api('inventario/productos','POST',$tokens['admin'],['categoria_id'=>$category,'codigo'=>$tag,'nombre'=>'Martillo de prueba de replicación','precio'=>'56.00','stock'=>10,'stock_minimo'=>1]);
check($code===201,'Producto temporal creado por API'); $product=$data['producto']['id'];
$hex=bin2hex(random_bytes(16)); $ref=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20);
[$code,$data]=api('ventas/','POST',$tokens['cashier'],['referencia'=>$ref,'productos'=>[['producto_id'=>$product,'cantidad'=>2]]]);
check($code===201 && $data['venta']['total']==='112.00','Venta real registrada por REST en la primaria'); $id=$data['venta']['id'];
$found=null;
for($i=0;$i<40;$i++) {
    $q=$replica->prepare('SELECT total,comprador_nombre FROM ventas WHERE id=?'); $q->execute([$id]); $found=$q->fetch();
    if($found) break;
    usleep(250000);
}
check($found && $found['total']==='112.00' && $found['comprador_nombre']==='CF','Venta y comprador copiados automáticamente a la réplica');
$q=$replica->prepare('SELECT cantidad,subtotal,categoria_nombre FROM detalle_ventas WHERE venta_id=?'); $q->execute([$id]); $line=$q->fetch();
check($line && (int)$line['cantidad']===2 && $line['subtotal']==='112.00' && $line['categoria_nombre']===$tag,'Detalle e importe replicados en la misma transacción');
[$code,$data]=api('inventario/productos/'.$product,'GET',$tokens['admin']);
check($code===200 && (int)$data['producto']['stock']===8,'Inventario descontado una sola vez en la primaria');
$day=(new DateTimeImmutable('now',new DateTimeZone('America/Guatemala')))->format('Y-m-d');
[$code,$data]=api('reportes/resumen?desde='.$day.'&hasta='.$day,'GET',$tokens['admin']);
$items=array_filter($data['productos'] ?? [],static fn($row)=>(string)$row['producto_id']===(string)$product);
check($code===200 && count($items)===1,'Reporte por REST incluye la venta leída desde la réplica');
$oldHost=getenv('REPORTS_DB_HOST'); putenv('REPORTS_DB_HOST=127.0.0.1'); $failed=false;
try {Database::reports();}
catch(PDOException $e) {$failed=true;}
finally {putenv('REPORTS_DB_HOST='.$oldHost);}
check($failed,'No se oculta una réplica inaccesible consultando la primaria');
echo "Replicación: $checks comprobaciones correctas.\n";
