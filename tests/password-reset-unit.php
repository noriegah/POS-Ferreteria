<?php
declare(strict_types=1);
// Prueba de lógica transaccional en memoria; no sustituye la prueba REST con MariaDB.
final class HttpError extends RuntimeException {public function __construct(public int $status,string $message){parent::__construct($message);}}
final class TokenService {public static object $claims; public static function fromRequest(): object{return self::$claims;}}
final class MemoryStatement {
    private array $result=[];
    public function __construct(private MemoryDatabase $db,private string $sql){}
    public function execute(array $args): void {
        if(str_contains($this->sql,'WHERE id IN')) {
            $this->result=array_values(array_filter($this->db->users,fn($u)=>in_array((string)$u['id'],array_map('strval',$args),true)));
        } elseif(str_starts_with($this->sql,'UPDATE usuarios SET password_hash')) {
            $this->db->users[$args[1]]['password_hash']=$args[0];$this->db->users[$args[1]]['session_version']++;
        } elseif(str_starts_with($this->sql,'INSERT INTO usuario_password_eventos')) {
            if($this->db->failAudit) throw new RuntimeException('Simulated audit failure');
            $this->db->events[]=$args;
        } elseif(str_contains($this->sql,'WHERE id=? AND activo=1')) {
            $u=$this->db->users[$args[0]] ?? null;$this->result=$u && $u['activo'] ? [$u] : [];
        } else throw new RuntimeException('Unexpected query');
    }
    public function fetchAll(): array{return $this->result;}
    public function fetch(): array|false{return $this->result[0] ?? false;}
}
final class MemoryDatabase {
    public array $users=[],$events=[];public bool $failAudit=false;private ?array $backup=null;
    public function prepare(string $sql): MemoryStatement{return new MemoryStatement($this,$sql);}
    public function beginTransaction(): void {$this->backup=[$this->users,$this->events];}
    public function inTransaction(): bool{return $this->backup!==null;}
    public function commit(): void {$this->backup=null;}
    public function rollBack(): void {[$this->users,$this->events]=$this->backup;$this->backup=null;}
}
final class Database {public static MemoryDatabase $db;public static function connect(): MemoryDatabase{return self::$db;}}
require __DIR__.'/../services/auth-service/src/Users.php';
function check(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);}
function denied(int $status,callable $call): void {try{$call();}catch(HttpError $e){check($e->status===$status,'HTTP inesperado');return;}throw new RuntimeException('Operación aceptada incorrectamente');}
$db=Database::$db=new MemoryDatabase();
$adminSecret='Admin-test-123456';$old='Previous-test-123';$next='Replacement-test-456';
foreach(['1'=>'administrador','2'=>'cajero'] as $id=>$role) $db->users[$id]=['id'=>(string)$id,'rol'=>$role,'activo'=>1,'session_version'=>0,'password_hash'=>password_hash($id==1?$adminSecret:$old,PASSWORD_BCRYPT,['cost'=>4])];
$actor=['id'=>'1','rol'=>'administrador','_session_version'=>0];
$data=(object)['password'=>$next,'confirmacion'=>$next,'admin_password'=>$adminSecret];
denied(403,fn()=>Users::resetPassword('2',$data,['id'=>'2','rol'=>'cajero']));
foreach([(object)['password'=>'short','confirmacion'=>'short','admin_password'=>$adminSecret],(object)['password'=>$next,'confirmacion'=>'different','admin_password'=>$adminSecret],(object)['password'=>str_repeat('é',37),'confirmacion'=>str_repeat('é',37),'admin_password'=>$adminSecret],(object)['password'=>"123456789012\0",'confirmacion'=>"123456789012\0",'admin_password'=>$adminSecret]] as $bad) denied(422,fn()=>Users::resetPassword('2',$bad,$actor));
$wrong=clone $data;$wrong->admin_password='Wrong-admin-secret';denied(403,fn()=>Users::resetPassword('2',$wrong,$actor));
denied(404,fn()=>Users::resetPassword('99',$data,$actor));
$db->users['1']['activo']=0;denied(403,fn()=>Users::resetPassword('2',$data,$actor));$db->users['1']['activo']=1;
$stale=$actor;$stale['_session_version']=4;denied(403,fn()=>Users::resetPassword('2',$data,$stale));
$before=$db->users['2'];$db->failAudit=true;
try{Users::resetPassword('2',$data,$actor);throw new LogicException('Missing rollback');}catch(RuntimeException $e){check($e->getMessage()==='Simulated audit failure','Unexpected rollback error');}
check($db->users['2']===$before && !$db->events,'Rollback must restore hash and session version');$db->failAudit=false;
Users::resetPassword('2',$data,$actor);
check(password_verify($next,$db->users['2']['password_hash']),'New password rejected');
check(!password_verify($old,$db->users['2']['password_hash']),'Old password accepted');
check($db->users['2']['session_version']===1,'Session version not incremented');
check($db->events===[['2','1']],'Audit must contain only account and administrator');
TokenService::$claims=(object)['sub'=>'2','sv'=>0];denied(401,fn()=>Users::current());
TokenService::$claims=(object)['sub'=>'2','sv'=>1];check(Users::current()['id']==='2','New session rejected');
denied(422,fn()=>Users::resetPassword('2',$data,$actor));
Users::resetPassword('1',$data,$actor);TokenService::$claims=(object)['sub'=>'1','sv'=>0];denied(401,fn()=>Users::current());
echo "PASS: permisos, validación, confirmación administrativa, rollback, hash, auditoría y revocación de sesiones.\n";
