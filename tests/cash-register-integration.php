<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';require '/app/src/TokenService.php';require '/app/src/Database.php';
$tokens=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo "PASS: $message\n";}
function api(string $path,string $method,string $token,?array $data=null):array{
$c=curl_init('http://gateway/api/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json']]);if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));$raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($raw===false)throw new RuntimeException('HTTP failed');return [$code,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];}
function ref():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20);}
$admin=$tokens['admin'];$cashier=$tokens['cashier'];$other=$tokens['other'];$db=Database::connect();
[$code]=api('ventas/cajas','GET','');check($code===401,'Caja exige JWT');
[$code]=api('ventas/cajas','POST',$cashier,['fondo'=>'-1']);check($code===422,'No acepta fondo negativo');
[$code]=api('ventas/cajas','POST',$cashier,['fondo'=>'0.001']);check($code===422,'No acepta más de dos decimales');
[$code,$c]=api('inventario/categorias','POST',$admin,['nombre'=>'repl_'.$tokens['tag']]);check($code===201,'Categoría temporal');$category=$c['categoria']['id'];
[$code,$p]=api('inventario/productos','POST',$admin,['categoria_id'=>$category,'codigo'=>'cash_'.$tokens['tag'],'nombre'=>'Prueba de caja','precio'=>'56.00','stock'=>10,'stock_minimo'=>1]);check($code===201,'Producto temporal');$product=$p['producto']['id'];
$payload=['referencia'=>ref(),'productos'=>[['producto_id'=>$product,'cantidad'=>2]]];
[$code]=api('ventas/','POST',$cashier,$payload);check($code===409,'Efectivo exige turno abierto');
[$code,$p]=api('inventario/productos/'.$product,'GET',$admin);check((int)$p['producto']['stock']===10,'Rechazo sin modificar stock');
[$code,$c]=api('ventas/cajas','POST',$cashier,['fondo'=>'100.00']);check($code===201,'Cajero abre turno');$cash=$c['caja']['id'];
[$code]=api('ventas/cajas','POST',$cashier,['fondo'=>'50']);check($code===409,'Una sola caja abierta por cajero');
[$code]=api('ventas/cajas/'.$cash,'GET',$other);check($code===404,'Otro cajero no consulta el turno');
[$code]=api('ventas/cajas/'.$cash.'/cerrar','POST',$admin,['contado'=>'100']);check($code===404,'Administrador no cierra turno ajeno');
[$code,$s]=api('ventas/','POST',$cashier,$payload);check($code===201,'Venta en efectivo asociada al turno');$sale=$s['venta']['id'];
[$code,$s]=api('ventas/','POST',$cashier,$payload);check($code===201 && $s['venta']['id']===$sale,'Reintento no duplica venta');
[$code,$c]=api('ventas/cajas/'.$cash,'GET',$cashier);check($c['caja']['esperado']==='212.00' && count($c['caja']['movimientos'])===1,'Fondo más venta, un solo movimiento');
[$code]=api('ventas/'.$sale.'/anular','POST',$admin,['motivo'=>'Prueba sin caja abierta']);check($code===409,'Administrador necesita turno para devolver efectivo');
[$code,$s]=api('ventas/'.$sale,'GET',$cashier);check($s['venta']['estado']==='confirmada','Anulación rechazada mantiene venta confirmada');
[$code]=api('ventas/cajas/'.$cash.'/cerrar','POST',$cashier,['contado'=>'210']);check($code===422,'Diferencia exige observación');
[$code,$c]=api('ventas/cajas/'.$cash.'/cerrar','POST',$cashier,['contado'=>'210','observaciones'=>'Faltan dos quetzales de prueba']);check($code===200 && $c['caja']['diferencia']==='-2.00','Cierre guarda faltante correcto');
[$code]=api('ventas/cajas/'.$cash.'/cerrar','POST',$cashier,['contado'=>'210','observaciones'=>'Faltan dos quetzales de prueba']);check($code===200,'Reintento de cierre idéntico');
[$code]=api('ventas/cajas/'.$cash.'/cerrar','POST',$cashier,['contado'=>'212']);check($code===409,'Cierre inmutable');
[$code,$s]=api('ventas/','POST',$cashier,$payload);check($code===201 && $s['venta']['id']===$sale,'Reintento recupera venta aunque su turno esté cerrado');
[$code]=api('ventas/','POST',$cashier,['referencia'=>ref(),'productos'=>[['producto_id'=>$product,'cantidad'=>1]]]);check($code===409,'Turno cerrado no recibe ventas nuevas');
[$code,$c]=api('ventas/cajas','POST',$admin,['fondo'=>'200']);check($code===201,'Administrador abre turno para devolución');$adminCash=$c['caja']['id'];
[$code,$s]=api('ventas/'.$sale.'/anular','POST',$admin,['motivo'=>'Prueba devolución de turno anterior']);check($code===200 && $s['venta']['estado']==='anulada','Anula venta de turno cerrado');
[$code]=api('ventas/'.$sale.'/anular','POST',$admin,['motivo'=>'Prueba devolución de turno anterior']);check($code===200,'Anulación repetida segura');
[$code,$c]=api('ventas/cajas/'.$adminCash,'GET',$admin);check($c['caja']['esperado']==='88.00' && $c['caja']['devoluciones']==='112.00' && count($c['caja']['movimientos'])===1,'Devolución sale una vez de caja del administrador');
[$code,$c]=api('ventas/cajas/'.$cash,'GET',$admin);check($c['caja']['esperado_cierre']==='212.00' && $c['caja']['diferencia']==='-2.00' && $c['caja']['esperado']==='212.00','Cuadre histórico conserva sus cifras tras devolución posterior');
[$code,$p]=api('inventario/productos/'.$product,'GET',$admin);check((int)$p['producto']['stock']===10,'Anulación devuelve stock una vez');
[$code,$c]=api('ventas/cajas/'.$adminCash.'/cerrar','POST',$admin,['contado'=>'90','observaciones'=>'Sobrante de prueba']);check($code===200 && $c['caja']['diferencia']==='2.00','Cierre con sobrante correcto');
[$code,$c]=api('ventas/cajas','GET',$other);check($code===200 && count($c['turnos'])===0,'Cajero solo ve su historial');
[$code,$c]=api('ventas/cajas','GET',$admin);check(count(array_filter($c['turnos'],fn($x)=>(string)$x['id']===(string)$cash))===1,'Administrador ve turnos de otros cajeros');
[$code,$c]=api('ventas/cajas','POST',$cashier,['fondo'=>'0']);check($code===201,'Puede abrir otro turno después del cierre');$empty=$c['caja']['id'];
[$code,$c]=api('ventas/cajas/'.$empty.'/cerrar','POST',$cashier,['contado'=>'0']);check($code===200 && $c['caja']['diferencia']==='0.00','Turno sin ventas cuadra en cero');
// Simula una anulación interrumpida para comprobar el bloqueo del cierre.
[$code,$c]=api('ventas/cajas','POST',$admin,['fondo'=>'100']);$blocked=$c['caja']['id'];
$q=$db->prepare("UPDATE ventas SET estado='anulando',caja_devolucion_id=? WHERE id=?");$q->execute([$blocked,$sale]);
[$code]=api('ventas/cajas/'.$blocked.'/cerrar','POST',$admin,['contado'=>'100']);check($code===409,'No cierra con devolución pendiente');
$q=$db->prepare("UPDATE ventas SET estado='anulada',caja_devolucion_id=? WHERE id=?");$q->execute([$adminCash,$sale]);
[$code]=api('ventas/cajas/'.$blocked.'/cerrar','POST',$admin,['contado'=>'100']);check($code===200,'Cierra después de resolver la devolución');
$replica=Database::reports();$found=false;for($i=0;$i<30;$i++){$q=$replica->prepare('SELECT estado FROM cajas WHERE id=?');$q->execute([$blocked]);if($q->fetchColumn()==='cerrada'){$found=true;break;}usleep(200000);}check($found,'Turnos replicados en MariaDB secundaria');
echo "Cuadre de caja: $checks comprobaciones correctas.\n";