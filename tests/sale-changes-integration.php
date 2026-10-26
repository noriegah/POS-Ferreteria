<?php
declare(strict_types=1);
// Registra una compra con un producto temporal y comprueba la copia en la segunda instancia.
require '/app/src/CashRegister.php';
require '/app/vendor/autoload.php'; require '/app/src/Database.php'; require '/app/src/TokenService.php';
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

// El efectivo exige ahora un turno abierto para cada cuenta temporal.
foreach(['admin','cashier','other'] as $key) {
    [$cashCode]=api('ventas/cajas','POST',$tokens[$key],['fondo'=>'500.00']);
    if($cashCode!==201) throw new RuntimeException('No se pudo abrir el turno temporal');
}
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

// Verifica permisos directamente en HTTP, sin depender de los botones.
[$code]=api('ventas/'.$id,'PATCH',$tokens['cashier'],['comprador'=>null,'motivo'=>'Corrección de prueba']);
check($code===403,'El cajero no puede editar una venta');
[$code]=api('ventas/'.$id.'/anular','POST',$tokens['cashier'],['motivo'=>'Anulación de prueba']);
check($code===403,'El cajero no puede anular su propia factura');
[$code]=api('ventas/'.$id.'/anular','POST','',['motivo'=>'Anulación de prueba']);
check($code===401,'La anulación exige autenticación');
[$code]=api('ventas/'.$id,'PATCH',$tokens['admin'],['productos'=>[],'motivo'=>'Cambiar productos']);
check($code===422,'La corrección rechaza cambiar productos o cantidades');
$buyer=['nombre'=>'Comprador de prueba','nit'=>(string)random_int(100000000000,999999999999),'direccion'=>'Ciudad'];
[$code,$edited]=api('ventas/'.$id,'PATCH',$tokens['admin'],['comprador'=>$buyer,'motivo'=>'Corregir datos del comprador']);
check($code===200 && $edited['venta']['comprador_nombre']===$buyer['nombre'],'Administrador corrige al comprador');
$q=$primary->prepare('SELECT c.nit FROM clientes c JOIN ventas v ON v.cliente_id=c.id WHERE v.id=?'); $q->execute([$id]);
check($q->fetchColumn()===$buyer['nit'],'La venta identifica al cliente y conserva su copia histórica');
$q=$primary->prepare('SELECT importe,estado FROM pagos WHERE venta_id=?'); $q->execute([$id]); $payment=$q->fetch();
check($payment && $payment['importe']==='112.00' && $payment['estado']==='aprobado','El cobro en efectivo queda relacionado con la venta');
[$code,$events]=api('ventas/'.$id.'/eventos','GET',$tokens['admin']);
check($code===200 && count($events['eventos'])===1,'La edición queda registrada con su autor y motivo');
// Interrumpe solo el cliente REST de este proceso: la intención debe persistir.
foreach(['AuthClient','Checkout','Buyer','InventoryClient','Sales','SaleChanges','Customers'] as $class) require_once '/app/src/'.$class.'.php';
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['admin']; $claims=TokenService::verify($tokens['admin']);
$oldUrl=getenv('INVENTARIO_URL'); putenv('INVENTARIO_URL=http://127.0.0.1:1'); $pending=false;
try { (new SaleChanges($primary))->cancel((int)$id,(object)['motivo'=>'Anulación por prueba controlada'],$claims); }
catch(HttpError $e) { $pending=$e->status===503; }
finally {putenv('INVENTARIO_URL='.$oldUrl);}
$q=$primary->prepare('SELECT estado FROM ventas WHERE id=?'); $q->execute([$id]);
check($pending && $q->fetchColumn()==='anulando','Un fallo de REST deja una anulación recuperable');
[$code,$data]=api('ventas/'.$id.'/anular','POST',$tokens['admin'],['motivo'=>'Anulación por prueba controlada']);
check($code===200 && $data['venta']['estado']==='anulada','Reintentar termina la anulación');
$q=$primary->prepare('SELECT estado FROM pagos WHERE venta_id=?'); $q->execute([$id]);
check($q->fetchColumn()==='anulado','El cobro en efectivo también queda anulado');
[$code,$data]=api('inventario/productos/'.$product,'GET',$tokens['admin']);
check($code===200 && (int)$data['producto']['stock']===10,'La anulación devuelve las dos unidades');
[$code,$data]=api('ventas/'.$id.'/anular','POST',$tokens['admin'],['motivo'=>'Anulación por prueba controlada']);
check($code===200 && $data['venta']['estado']==='anulada','Repetir la anulación responde sin duplicarla');
[$code,$data]=api('inventario/productos/'.$product,'GET',$tokens['admin']);
check($code===200 && (int)$data['producto']['stock']===10,'Un segundo intento no duplica el stock');
[$code]=api('ventas/'.$id,'PATCH',$tokens['admin'],['comprador'=>null,'motivo'=>'No debe permitirse']);
check($code===409,'Una venta anulada ya no puede editarse');
[$code,$data]=api('ventas/'.$id.'/factura','GET',$tokens['admin']);
check($code===200 && $data['factura']['estado']==='anulada','El comprobante conserva su número y muestra el estado');
for($i=0;$i<40;$i++) {
    $q=$replica->prepare('SELECT estado FROM ventas WHERE id=?'); $q->execute([$id]);
    if($q->fetchColumn()==='anulada') break;
    usleep(250000);
}
[$code,$data]=api('reportes/resumen?desde='.$day.'&hasta='.$day,'GET',$tokens['admin']);
$items=array_filter($data['productos'] ?? [],static fn($row)=>(string)$row['producto_id']===(string)$product);
check($code===200 && count($items)===0,'La venta anulada no suma en los reportes de la réplica');
[$code,$data]=api('ventas/'.$id.'/eventos','GET',$tokens['admin']);
check($code===200 && count($data['eventos'])===3,'Existe una sola solicitud y una sola anulación final');
// El usuario no puede modificar su propio acceso ni conservar permisos antiguos.
$cashClaims=TokenService::verify($tokens['cashier']); $otherClaims=TokenService::verify($tokens['other']);
[$code]=api('auth/users/'.$cashClaims->sub,'PATCH',$tokens['cashier'],['rol'=>'administrador','activo'=>1]);
check($code===403,'Un cajero no puede concederse privilegios');
[$code]=api('auth/users/'.$claims->sub,'PATCH',$tokens['admin'],['rol'=>'cajero','activo'=>0]);
check($code===409,'El administrador no puede bloquear su propio acceso');
[$code]=api('auth/users/'.$otherClaims->sub,'PATCH',$tokens['admin'],['rol'=>'administrador','activo'=>1]);
check($code===200,'El administrador puede cambiar el rol de otra cuenta');
[$code]=api('auth/users','GET',$tokens['other']);
check($code===200,'El rol actual se aplica incluso con un JWT emitido antes del cambio');
[$code]=api('auth/users/'.$otherClaims->sub,'PATCH',$tokens['admin'],['rol'=>'cajero','activo'=>1]);
check($code===200,'El administrador puede retirar el rol administrativo');
[$code]=api('auth/users','GET',$tokens['other']);
check($code===403,'El JWT anterior no conserva los permisos retirados');
[$code]=api('auth/users/'.$otherClaims->sub,'PATCH',$tokens['admin'],['rol'=>'cajero','activo'=>0]);
check($code===200,'El administrador puede desactivar otra cuenta');
[$code]=api('ventas/','GET',$tokens['other']);
check($code===401,'Una cuenta desactivada ya no puede usar su JWT');
echo "Cambios de ventas: $checks comprobaciones correctas.\n";

