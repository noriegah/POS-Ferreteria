<?php
declare(strict_types=1);
// Carga la biblioteca JWT, la conexión PDO y las funciones de autenticación.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/TokenService.php';
require_once __DIR__ . '/../src/Users.php';
// Todas las respuestas son JSON y no deben guardarse en caché.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$service = 'auth-service';
// Nginx ya quitó /api/auth; aquí las rutas llegan como /login o /me.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
// Envía el código HTTP, convierte los datos a JSON y termina la petición.
function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
try {
// Comprueba que el servicio responde, sin consultar MariaDB.
    if ($path === '/health' && $method === 'GET') {
        respond(200, ['service' => $service, 'status' => 'ok', 'phase' => 1]);
    }
// Comprueba que el servicio también puede conectarse a su base de datos.
    if ($path === '/ready' && $method === 'GET') {
        try { Database::connect()->query('SELECT 1'); }
        catch (Throwable $e) { respond(503, ['service' => $service, 'status' => 'unavailable', 'database' => 'unavailable']); }
        respond(200, ['service' => $service, 'status' => 'ok', 'database' => 'ok']);
    }
// Valida el JSON, busca al usuario y entrega un JWT si la contraseña y el estado son correctos.
    if ($service === 'auth-service' && $path === '/login') {
        if ($method !== 'POST') { header('Allow: POST'); throw new HttpError(405, 'Método no permitido'); }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new HttpError(415, 'Se requiere application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if ($raw === false || strlen($raw) > 8192) { throw new HttpError(413, 'Solicitud demasiado grande'); }
        try { $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError(400, 'JSON inválido'); }
        if (!is_object($body) || !is_string($body->email ?? null) || !is_string($body->password ?? null)
            || strlen($body->email) > 254 || !filter_var(trim($body->email), FILTER_VALIDATE_EMAIL)
            || $body->password === '' || strlen($body->password) > 72) { throw new HttpError(422, 'Email o contraseña inválidos'); }
        $db = Database::connect();
        $query = $db->prepare('SELECT id,nombre,email,password_hash,rol,activo,session_version FROM usuarios WHERE email = ? LIMIT 1');
        $query->execute([strtolower(trim($body->email))]); $user = $query->fetch();
        // También verifica un hash cuando el correo no existe, para reducir diferencias de tiempo en la respuesta.
        $hash = $user ? $user['password_hash'] : '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($body->password, $hash);
        if (!$user || !$valid || !(bool)$user['activo']) { throw new HttpError(401, 'Credenciales incorrectas'); }
        $token = TokenService::issue($user);
        unset($user['password_hash'], $user['activo'], $user['session_version']); $user['id'] = (string)$user['id'];
        respond(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => (int)getenv('JWT_TTL'), 'user' => $user]);
    }
    // Renueva únicamente un JWT todavía válido de una cuenta activa.
    if($path==='/refresh') {
        if($method!=='POST') {header('Allow: POST');throw new HttpError(405,'Método no permitido');}
        $account=Users::current();
        $account['session_version']=$account['_session_version'];
        respond(200,['access_token'=>TokenService::issue($account),'token_type'=>'Bearer','expires_in'=>(int)getenv('JWT_TTL')]);
    }
    // /verify recibe el mismo Bearer que envían el navegador o los otros servicios.
    if ($path === '/verify') {
        if ($method !== 'POST') { header('Allow: POST'); throw new HttpError(405, 'Método no permitido'); }
        respond(200, ['valid'=>true, 'user'=>Users::current()]);
    }
    // El perfil consulta los datos actuales del usuario autenticado.
    if ($path === '/me') {
        if ($method !== 'GET') { header('Allow: GET'); throw new HttpError(405, 'Método no permitido'); }
        respond(200, ['user'=>Users::current()]);
    }
    // Solo un administrador vigente puede restablecer contraseñas.
    if(preg_match('#^/users/([1-9][0-9]{0,19})/password$#D',$path,$account)) {
        if($method!=='POST') {header('Allow: POST'); throw new HttpError(405,'Método no permitido');}
        $actor=Users::administrator();
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json') throw new HttpError(415,'Se requiere application/json');
        $raw=file_get_contents('php://input',false,null,0,8193);
        if($raw===false || strlen($raw)>8192) throw new HttpError(413,'Solicitud demasiado grande');
        try {$data=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}
        catch(JsonException $error) {throw new HttpError(400,'JSON inválido');}
        if(!is_object($data)) throw new HttpError(422,'Se requiere un objeto JSON');
        Users::resetPassword($account[1],$data,$actor);
        respond(200,['mensaje'=>'Contraseña restablecida. Las sesiones anteriores de la cuenta quedaron invalidadas.']);
    }
    // El rol vigente se verifica antes de aceptar un cambio de acceso.
    if(preg_match('#^/users/([1-9][0-9]{0,19})$#D',$path,$account)) {
        if($method!=='PATCH') { header('Allow: PATCH'); throw new HttpError(405,'Método no permitido'); }
        $actor=Users::administrator();
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json') throw new HttpError(415,'Se requiere application/json');
        $raw=file_get_contents('php://input',false,null,0,8193);
        if($raw===false || strlen($raw)>8192) throw new HttpError(413,'Solicitud demasiado grande');
        try { $data=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
        catch(JsonException $error) { throw new HttpError(400,'JSON inválido'); }
        if(!is_object($data)) throw new HttpError(422,'Se requiere un objeto JSON');
        respond(200,['user'=>Users::updateAccess($account[1],$data,$actor)]);
    }
    // Únicamente un administrador puede listar o registrar cuentas.
    if ($path === '/users') {
        if ($method !== 'GET') { header('Allow: GET'); throw new HttpError(405, 'Método no permitido'); }
        Users::administrator();
        respond(200, ['usuarios'=>Users::all()]);
    }
    if ($path === '/register') {
        if ($method !== 'POST') { header('Allow: POST'); throw new HttpError(405, 'Método no permitido'); }
        Users::administrator();
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new HttpError(415, 'Se requiere application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if ($raw === false || strlen($raw) > 8192) { throw new HttpError(413, 'Solicitud demasiado grande'); }
        try { $data = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError(400, 'JSON inválido'); }
        if (!is_object($data)) { throw new HttpError(422, 'Se requiere un objeto JSON'); }
        respond(201, ['user'=>Users::register($data)]);
    }
    respond(404, ['error' => 'Endpoint aún no implementado', 'service' => $service]);
// Los errores esperados devuelven su código; los fallos internos no exponen contraseñas ni SQL.
} catch (HttpError $e) {
    if ($e->status === 401) { header('WWW-Authenticate: Bearer'); }
    respond($e->status, ['error' => $e->getMessage()]);
} catch (PDOException $e) {
    // El correo es único: no permite registrar dos cuentas con el mismo correo.
    if ((int)($e->errorInfo[1] ?? 0) === 1062) { respond(409, ['error'=>'El correo ya está registrado']); }
    error_log('Auth database request failed: ' . get_class($e));
    respond(503, ['error'=>'Servicio temporalmente no disponible']);
} catch (Throwable $e) {
    error_log('Request failed: ' . get_class($e));
    respond(503, ['error' => 'Servicio temporalmente no disponible']);
}
