<?php
declare(strict_types=1);

// Operaciones sencillas de usuarios; solo Autenticación consulta auth_db.
final class Users
{
    // Solo cambia rol y estado; las contraseñas siguen su flujo independiente.
    public static function updateAccess(string $id, object $data, array $actor): array
    {
        if(!in_array($data->rol ?? null,['administrador','cajero'],true) || !in_array($data->activo ?? null,[0,1],true)) throw new HttpError(422,'Selecciona un rol y estado válidos');
        if((string)$actor['id']===$id) throw new HttpError(409,'Tu propio rol y estado deben ser modificados por otro administrador');
        $db=Database::connect(); $db->beginTransaction();
        try {
            // Orden fijo de bloqueo para cambios simultáneos entre administradores.
            $q=$db->prepare('SELECT id,rol,activo FROM usuarios WHERE id IN (?,?) ORDER BY id FOR UPDATE');
            $q->execute([$id,$actor['id']]); $rows=array_column($q->fetchAll(),null,'id');
            $current=$rows[$actor['id']] ?? null; $old=$rows[$id] ?? null;
            if(!$current || $current['rol']!=='administrador' || (int)$current['activo']!==1) throw new HttpError(403,'Permisos insuficientes');
            if(!$old) throw new HttpError(404,'Usuario no encontrado');
            if($old['rol']!==$data->rol || (int)$old['activo']!==$data->activo) {
                $q=$db->prepare('UPDATE usuarios SET rol=?,activo=? WHERE id=?'); $q->execute([$data->rol,$data->activo,$id]);
                $q=$db->prepare('INSERT INTO usuario_eventos(usuario_id,administrador_id,rol_anterior,rol_nuevo,activo_anterior,activo_nuevo) VALUES (?,?,?,?,?,?)');
                $q->execute([$id,$actor['id'],$old['rol'],$data->rol,$old['activo'],$data->activo]);
            }
            $q=$db->prepare('SELECT id,nombre,email,rol,activo,creado_en FROM usuarios WHERE id=?'); $q->execute([$id]); $user=$q->fetch();
            $db->commit(); return $user;
        } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    // Restablece la clave sin conocer la anterior del usuario. Confirma la del administrador.
    public static function resetPassword(string $id, object $data, array $actor): void
    {
        if(($actor['rol'] ?? null)!=='administrador') throw new HttpError(403,'Permisos insuficientes');
        if(!is_string($data->password ?? null) || strlen($data->password)<12 || strlen($data->password)>72
            || str_contains($data->password,"\0") || !is_string($data->confirmacion ?? null)
            || $data->password!==$data->confirmacion) throw new HttpError(422,'Las contraseñas deben coincidir y tener entre 12 y 72 bytes');
        if(!is_string($data->admin_password ?? null) || $data->admin_password==='' || strlen($data->admin_password)>72
            || str_contains($data->admin_password,"\0")) throw new HttpError(422,'Ingresa tu contraseña de administrador');
        $db=Database::connect(); $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id,rol,activo,password_hash,session_version FROM usuarios WHERE id IN (?,?) ORDER BY id FOR UPDATE');
            $q->execute([$id,$actor['id']]); $rows=array_column($q->fetchAll(),null,'id');
            $admin=$rows[$actor['id']] ?? null; $target=$rows[$id] ?? null;
            if(!$admin || $admin['rol']!=='administrador' || (int)$admin['activo']!==1
                || (int)$admin['session_version']!==(int)($actor['_session_version'] ?? -1)) throw new HttpError(403,'La sesión del administrador ya no es válida');
            if(!password_verify($data->admin_password,$admin['password_hash'])) throw new HttpError(403,'Tu contraseña de administrador es incorrecta');
            if(!$target) throw new HttpError(404,'Usuario no encontrado');
            if(password_verify($data->password,$target['password_hash'])) throw new HttpError(422,'La nueva contraseña debe ser diferente de la anterior');
            $hash=password_hash($data->password,PASSWORD_BCRYPT,['cost'=>12]);
            $q=$db->prepare('UPDATE usuarios SET password_hash=?,session_version=session_version+1 WHERE id=?');
            $q->execute([$hash,$id]);
            $q=$db->prepare('INSERT INTO usuario_password_eventos(usuario_id,administrador_id) VALUES (?,?)');
            $q->execute([$id,$actor['id']]); $db->commit();
        } catch(Throwable $error) {if($db->inTransaction()) $db->rollBack(); throw $error;}
    }

    // Comprueba el JWT y vuelve a leer el rol y el estado actuales en MariaDB.
    public static function current(): array
    {
        $claims = TokenService::fromRequest();
        $query = Database::connect()->prepare('SELECT id,nombre,email,rol,activo,creado_en,session_version FROM usuarios WHERE id=? AND activo=1');
        $query->execute([$claims->sub]);
        $user = $query->fetch();
        if (!$user || (int)$user['session_version'] !== ($claims->sv ?? 0)) { throw new HttpError(401, 'Usuario no disponible o contraseña restablecida'); }
        $user['_session_version']=(int)$user['session_version']; unset($user['session_version']);
        $user['id'] = (string)$user['id'];
        return $user;
    }

    // El permiso depende del rol actual, no de un rol antiguo dentro del token.
    public static function administrator(): array
    {
        $user = self::current();
        if ($user['rol'] !== 'administrador') { throw new HttpError(403, 'Permisos insuficientes'); }
        return $user;
    }

    // Selecciona datos públicos: nunca envía hashes de contraseñas al navegador.
    public static function all(): array
    {
        return Database::connect()->query('SELECT id,nombre,email,rol,activo,creado_en FROM usuarios ORDER BY id DESC')->fetchAll();
    }

    // Registra un usuario con nombre, email, contraseña y rol indicados por un administrador.
    public static function register(object $data): array
    {
        if (!is_string($data->nombre ?? null) || trim($data->nombre) === '' || strlen(trim($data->nombre)) > 150
            || !is_string($data->email ?? null) || strlen($data->email) > 254 || !filter_var(trim($data->email), FILTER_VALIDATE_EMAIL)
            || !is_string($data->password ?? null) || strlen($data->password) < 12 || strlen($data->password) > 72
            || !in_array($data->rol ?? null, ['administrador','cajero'], true)) {
            throw new HttpError(422, 'Revisa nombre, correo, contraseña (12-72 bytes) y rol');
        }
        $name = trim($data->nombre); $email = strtolower(trim($data->email));
        $db = Database::connect();
        $query = $db->prepare('INSERT INTO usuarios(nombre,email,password_hash,rol) VALUES (?,?,?,?)');
        $query->execute([$name,$email,password_hash($data->password, PASSWORD_BCRYPT, ['cost'=>12]),$data->rol]);
        return ['id'=>(string)$db->lastInsertId(),'nombre'=>$name,'email'=>$email,'rol'=>$data->rol,'activo'=>1];
    }
}
