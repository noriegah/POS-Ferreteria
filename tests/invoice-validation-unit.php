<?php
declare(strict_types=1);
require __DIR__.'/../services/ventas-service/src/TokenService.php';
require __DIR__.'/../services/ventas-service/src/InvoiceValidation.php';
final class InvoiceStatement extends PDOStatement {
 public function __construct(private mixed $sale){}
 public function execute(?array $params=null): bool{return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed{return $this->sale;}
}
final class InvoiceDatabase extends PDO {
 public mixed $sale;public string $sql='';
 public function __construct(){}
 public function prepare(string $query,array $options=[]): PDOStatement|false{$this->sql=$query;return new InvoiceStatement($this->sale);}
}
function check(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);}
$oldKey=getenv('VENTAS_INTERNAL_KEY');$oldUrl=getenv('PUBLIC_APP_URL');
try{
 putenv('VENTAS_INTERNAL_KEY=unit-test-key-only');putenv('PUBLIC_APP_URL=https://tienda.example');
 $reference='00000000-0000-4000-8000-000000000001';$url=InvoiceValidation::url($reference);parse_str(parse_url($url,PHP_URL_QUERY),$query);
 check(str_starts_with($url,'https://tienda.example/validar-factura.html?'),'URL incorrecta');
 check(InvoiceValidation::authorized($query['referencia'],$query['firma']),'Firma legítima rechazada');
 check(!InvoiceValidation::authorized('00000000-0000-4000-8000-000000000002',$query['firma']),'Aceptó referencia alterada');
 check(!InvoiceValidation::authorized($reference,str_repeat('0',64)),'Aceptó firma alterada');
 foreach([null,[],12,'invalid'] as $bad)check(!InvoiceValidation::authorized($bad,$query['firma']),'Aceptó referencia malformada');
 $db=new InvoiceDatabase();$db->sale=['id'=>634,'estado'=>'confirmada','total'=>'519.00','moneda'=>'GTQ','creado_en'=>'2026-10-26 17:30:00','comprador_nit'=>'NO DEBE SALIR','password_hash'=>'NO DEBE SALIR'];
 foreach(['confirmada'=>true,'anulada'=>false,'anulando'=>false] as $state=>$valid){$db->sale['estado']=$state;$data=InvoiceValidation::lookup($db,$reference);check($data['valida']===$valid && $data['estado']===$state,'Estado incorrecto');check(!isset($data['comprador_nit'],$data['password_hash']),'Exposición de datos');check($data['numero']==='FAC-000634' && $data['emisor']['nit']==='2345674','Datos incorrectos');}
 check(str_contains($db->sql,"'confirmada','anulando','anulada'") && str_contains($db->sql,'captura_externa IS NOT NULL'),'No excluye ventas sin factura emitida');
 $db->sale=false;try{InvoiceValidation::lookup($db,$reference);throw new LogicException('Aceptó factura inexistente');}catch(HttpError $error){check($error->status===404,'Código incorrecto');}
 putenv('PUBLIC_APP_URL=javascript:alert(1)');try{InvoiceValidation::url($reference);throw new LogicException('URL insegura aceptada');}catch(HttpError $error){check($error->status===503,'Configuración inválida');}
 echo "PASS: enlaces firmados, alteración rechazada, estados vigente/anulada/en proceso, privacidad, inexistentes y URL segura.\n";
}finally{if($oldKey===false)putenv('VENTAS_INTERNAL_KEY');else putenv('VENTAS_INTERNAL_KEY='.$oldKey);if($oldUrl===false)putenv('PUBLIC_APP_URL');else putenv('PUBLIC_APP_URL='.$oldUrl);}
