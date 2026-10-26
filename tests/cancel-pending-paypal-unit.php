<?php
declare(strict_types=1);
require __DIR__.'/../services/ventas-service/src/TokenService.php';
require __DIR__.'/../services/ventas-service/src/integrations/PayPalClient.php';
require __DIR__.'/../services/ventas-service/src/Payments.php';
require __DIR__.'/../services/inventario-service/src/StockReturns.php';
final class CancelStatement extends PDOStatement {
 private mixed $result=false;
 public function __construct(private CancelDatabase $db,private string $sql){}
 public function execute(?array $args=null): bool {
  $s=$this->sql;$a=$args ?? [];
  if(str_starts_with($s,'SELECT * FROM ventas'))$this->result=$a[0]==7?$this->db->sale:false;
  elseif(str_starts_with($s,'SELECT * FROM pagos'))$this->result=$this->db->payment;
  elseif(str_starts_with($s,'SELECT 1 FROM venta_eventos'))$this->result=array_filter($this->db->events,fn($e)=>$e[2]==='solicitar_cobro')?[1]:false;
  elseif(str_starts_with($s,"UPDATE ventas SET estado='anulando'")){$this->db->sale['estado']='anulando';$this->db->sale['anulacion_motivo']=$a[0];$this->db->sale['anulacion_usuario_id']=$a[1];}
  elseif(str_starts_with($s,"UPDATE ventas SET estado='anulada'"))$this->db->sale['estado']='anulada';
  elseif(str_starts_with($s,"UPDATE ventas SET estado='confirmada'"))$this->db->sale['estado']='confirmada';
  elseif(str_starts_with($s,"UPDATE pagos SET estado='anulado'"))$this->db->payment['estado']='anulado';
  elseif(str_starts_with($s,"UPDATE pagos SET estado='aprobado'")){$this->db->payment['estado']='aprobado';$this->db->payment['captura_externa']=$a[0];}
  elseif(str_starts_with($s,'INSERT INTO venta_eventos'))$this->db->events[]=$a;
  elseif(str_starts_with($s,'SELECT respuesta,usuario_id FROM descuentos_venta'))$this->result=['respuesta'=>'{"productos":[]}','usuario_id'=>'2'];
  elseif(str_starts_with($s,'SELECT respuesta FROM devoluciones_venta'))$this->result=['{"referencia":"'.$a[0].'","devuelta":true}'];
  else throw new RuntimeException('Consulta inesperada en prueba: '.$s);
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed{return $this->result;}
 public function fetchColumn(int $column=0): mixed{return $this->result===false?false:array_values($this->result)[$column];}
}
final class CancelDatabase extends PDO {
 public array $sale,$payment,$events=[];private ?array $snapshot=null;
 public function __construct(){
  $this->sale=['id'=>7,'usuario_id'=>'2','referencia'=>'00000000-0000-4000-8000-000000000007','total'=>'90.00','estado'=>'pendiente_pago','metodo_pago'=>'tarjeta','anulacion_motivo'=>null];
  $this->payment=['id'=>1,'proveedor'=>'paypal_sandbox','estado'=>'pendiente','orden_externa'=>'ORDER1','captura_externa'=>null,'importe_proveedor'=>'11.54'];
 }
 public function prepare(string $query,array $options=[]): PDOStatement|false{return new CancelStatement($this,$query);}
 public function beginTransaction(): bool{if($this->snapshot!==null)throw new RuntimeException('Transacción anidada');$this->snapshot=[$this->sale,$this->payment,$this->events];return true;}
 public function inTransaction(): bool{return $this->snapshot!==null;}
 public function commit(): bool{$this->snapshot=null;return true;}
 public function rollBack(): bool{[$this->sale,$this->payment,$this->events]=$this->snapshot;$this->snapshot=null;return true;}
}
final class Sales {public function __construct(private CancelDatabase $db){} public function find(int $id,object $claims): array{return $this->db->sale+['pago'=>$this->db->payment];}}
final class InventoryClient {
 public static bool $lose=false;public static array $returned=[];public static int $units=0,$calls=0;
 public static function restore(string $reference,bool $pending=false): void{
  if(!$pending)throw new RuntimeException('Ruta de reserva no solicitada');self::$calls++;
  if(!isset(self::$returned[$reference])){self::$units+=2;self::$returned[$reference]=true;}
  if(self::$lose){self::$lose=false;throw new HttpError(503,'Respuesta de inventario perdida');}
 }
 public static function reset(): void{self::$returned=[];self::$units=0;self::$calls=0;self::$lose=false;}
}
function check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);}
function denied(int $status,callable $call): void{try{$call();}catch(HttpError $e){check($e->status===$status,'Código HTTP inesperado: '.$e->status);return;}throw new RuntimeException('Operación que debía rechazarse');}
putenv('PAYPAL_CLIENT_ID=unit-id');putenv('PAYPAL_CLIENT_SECRET=unit-secret');putenv('PAYPAL_RETURN_URL=https://example.invalid/pago-retorno.html');
$owner=(object)['sub'=>'2','role'=>'cajero'];$other=(object)['sub'=>'3','role'=>'cajero'];$admin=(object)['sub'=>'1','role'=>'administrador'];
function fixture(string $state='CREATED',bool $captured=false,bool $offline=false): array{
 InventoryClient::reset();$db=new CancelDatabase();
 $remote=['id'=>'ORDER1','status'=>$state,'purchase_units'=>[['custom_id'=>$db->sale['referencia'],'amount'=>['currency_code'=>'USD','value'=>'11.54']]]];
 if($captured)$remote['purchase_units'][0]['payments']['captures']=[['id'=>'CAPTURE1','status'=>'COMPLETED','amount'=>['currency_code'=>'USD','value'=>'11.54']]];
 $transport=static function($method,$path,$body,$headers,$credentials) use ($remote,$offline){
  if($offline)throw new HttpError(503,'Proveedor sin conexión');
  if($path==='/v1/oauth2/token')return [200,['access_token'=>'test']];
  if($method!=='GET')throw new RuntimeException('La cancelación intentó cobrar o reembolsar');
  return [200,$remote];
 };
 return [$db,new Payments($db,new PayPalClient($transport))];
}
foreach(['CREATED','APPROVED','VOIDED','SAVED','PAYER_ACTION_REQUIRED'] as $state){
 [$db,$service]=fixture($state);$r=$service->cancelPending(7,$owner);check($r['estado']==='anulada' && InventoryClient::$units===2,'No canceló reserva '.$state);$count=count($db->events);$service->cancelPending(7,$owner);check(InventoryClient::$units===2 && count($db->events)===$count,'La repetición duplicó efectos');
}
[$db,$service]=fixture();denied(404,fn()=>$service->cancelPending(7,$other));check(InventoryClient::$units===0 && $db->sale['estado']==='pendiente_pago','Otro cajero cambió la venta');
[$db,$service]=fixture();$service->cancelPending(7,$admin);check(InventoryClient::$units===2,'Administrador rechazado');
[$db,$service]=fixture('COMPLETED',true);denied(409,fn()=>$service->cancelPending(7,$owner));check(InventoryClient::$units===0 && $db->payment['estado']==='pendiente','Canceló cobro externo ya completado');
[$db,$service]=fixture();$db->sale['estado']='confirmada';$db->payment['estado']='aprobado';denied(409,fn()=>$service->cancelPending(7,$owner));
[$db,$service]=fixture('CREATED',false,true);denied(503,fn()=>$service->cancelPending(7,$owner));check(InventoryClient::$units===0 && $db->sale['estado']==='pendiente_pago','Liberó existencias sin verificar PayPal');
$db->payment['orden_externa']=null;$service->cancelPending(7,$owner);check(InventoryClient::$units===2,'Sin orden debe cancelar incluso si PayPal está caído');
[$db,$service]=fixture();InventoryClient::$lose=true;denied(503,fn()=>$service->cancelPending(7,$owner));check($db->sale['estado']==='anulando' && InventoryClient::$units===2,'No conservó cancelación recuperable');$service->cancelPending(7,$owner);check($db->sale['estado']==='anulada' && InventoryClient::$units===2,'Reintento devolvió dos veces');denied(409,fn()=>$service->capture(7,$owner));
[$db,$service]=fixture('APPROVED');$db->events[]=[7,'2','solicitar_cobro','test','{}','{}'];denied(409,fn()=>$service->cancelPending(7,$owner));check(InventoryClient::$units===0,'Canceló una captura incierta');
[$db,$service]=fixture('UNEXPECTED');denied(503,fn()=>$service->cancelPending(7,$owner));
[$db,$service]=fixture();denied(403,fn()=>StockReturns::restore($db,(object)['referencia'=>$db->sale['referencia']],'3',true));check(StockReturns::restore($db,(object)['referencia'=>$db->sale['referencia']],'2',true)['devuelta']===true,'El propietario no pudo recuperar su devolución');
// Una captura perdida conserva su intención y se recupera sin cobrar dos veces.
InventoryClient::reset();$db=new CancelDatabase();$remote=['id'=>'ORDER1','status'=>'APPROVED','purchase_units'=>[['custom_id'=>$db->sale['referencia'],'amount'=>['currency_code'=>'USD','value'=>'11.54']]]];$captures=0;
$transport=static function($method,$path) use (&$remote,&$captures){if($path==='/v1/oauth2/token')return [200,['access_token'=>'test']];if(str_ends_with($path,'/capture')){$captures++;$remote['status']='COMPLETED';$remote['purchase_units'][0]['payments']['captures']=[['id'=>'CAPTURE1','status'=>'COMPLETED','amount'=>['currency_code'=>'USD','value'=>'11.54']]];throw new HttpError(503,'Respuesta perdida');}return [200,$remote];};
$service=new Payments($db,new PayPalClient($transport));denied(503,fn()=>$service->capture(7,$owner));check(count($db->events)===1 && $db->sale['estado']==='pendiente_pago','Se perdió la intención durable');denied(409,fn()=>$service->cancelPending(7,$owner));$paid=$service->capture(7,$owner);check($paid['estado']==='confirmada' && $captures===1,'No recuperó la captura única');
echo "PASS: reservas sin cobro, propietario/admin, pago confirmado, proveedor caído, captura incierta, devolución idempotente, reintentos y recuperación de cobro perdido.\n";
