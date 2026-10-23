<?php
// Prueba login y JWT por HTTP con usuarios temporales; los elimina al finalizar.
declare(strict_types=1);
require '/app/vendor/autoload.php';
require '/app/src/Database.php';
require '/app/src/TokenService.php';
use Firebase\JWT\JWT;

// Muestra PASS si la comprobación se cumple; si falla, detiene la prueba con un error.
function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . "\n";
}
// Envía una petición al gateway y devuelve su código HTTP y el JSON recibido.
function request(string $path, string $method = 'GET', ?string $token = null, ?string $body = null, string $type = 'application/json'): array {
    $headers = "Content-Type: $type\r\n";
    if ($token !== null) { $headers .= 'Authorization: Bearer ' . $token . "\r\n"; }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $body ?? '',
        'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = file_get_contents('http://gateway/api/' . $path, false, $context);
    if ($raw === false) { throw new RuntimeException('HTTP request failed'); }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    check(!str_contains($raw, 'password_hash') && !str_contains($raw, 'PRIVATE KEY'), 'No sensitive response fields');
    return [(int)$match[1], $data];
}
// Realiza el login de prueba sin mostrar la contraseña ni el token en pantalla.
function login(string $email, string $password): array {
    return request('auth/login', 'POST', null, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR));
}
$db = Database::connect(); $ids = []; $tag = 'jwt_' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(20));
try {
    foreach (['administrador','cajero','inactivo'] as $role) {
        $q = $db->prepare('INSERT INTO usuarios(nombre,email,password_hash,rol,activo) VALUES (?,?,?,?,?)');
        $q->execute([$tag, "$tag-$role@example.invalid", password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $role === 'inactivo' ? 'cajero' : $role, $role === 'inactivo' ? 0 : 1]);
        $ids[$role] = (string)$db->lastInsertId();
    }
    [$status,$admin] = login("$tag-administrador@example.invalid", $password);
    check($status === 200 && $admin['token_type'] === 'Bearer' && $admin['expires_in'] === (int)getenv('JWT_TTL'), 'Administrator login');
    $token = $admin['access_token']; $claims = TokenService::verify($token);
    check($claims->sub === $ids['administrador'] && $claims->role === 'administrador', 'Signed identity');
    [$status,$me] = request('auth/me','GET',$token);
    check($status === 200 && $me['user']['id'] === $ids['administrador'], 'Authenticated profile through gateway');
    [$status,$session] = request('inventario/session','GET',$token);
    check($status === 200 && in_array('inventario:escribir',$session['permissions'],true), 'Inventory accepts public-key signature and administrator role');
    [$status,$cashier] = login("$tag-cajero@example.invalid", $password);
    check($status === 200, 'Cashier login');
    [$status,$session] = request('inventario/session','GET',$cashier['access_token']);
    check($status === 200 && $session['permissions'] === ['inventario:leer'], 'Cashier read permissions');
    try { TokenService::requireRole(TokenService::verify($cashier['access_token']), ['administrador']); throw new RuntimeException('Role accepted'); }
    catch (HttpError $e) { check($e->status === 403, 'Role guard rejects cashier administration'); }
    [$status,$wrong] = login("$tag-administrador@example.invalid", 'wrong-password'); check($status === 401, 'Wrong password');
    [$status,$absent] = login("$tag-absent@example.invalid", $password); check($status === 401 && $absent === $wrong, 'Unknown user has same error');
    [$status,$inactive] = login("$tag-inactivo@example.invalid", $password); check($status === 401 && $inactive === $wrong, 'Inactive user cannot login');
    foreach (['auth/me','inventario/session'] as $path) {
        [$status] = request($path); check($status === 401, 'Missing token ' . $path);
        [$status] = request($path,'GET','invalid'); check($status === 401, 'Malformed token ' . $path);
    }
    $parts = explode('.', $token); $sig = $parts[2]; $sig[0] = $sig[0] === 'A' ? 'B' : 'A'; $parts[2] = $sig;
    // Prepara tokens alterados para confirmar que ambos servicios los rechacen.
$badTokens = ['tampered' => implode('.', $parts)];
    $privateKey = file_get_contents(getenv('JWT_PRIVATE_KEY_FILE'));
    $base = (array)$claims;
    foreach (['expired','future','issuer','audience','missing-exp','invalid-role','invalid-subject','missing-jti','excessive-lifetime'] as $case) {
        $payload = $base;
        switch ($case) {
            case 'expired': $payload['iat'] = time()-120; $payload['nbf'] = time()-120; $payload['exp'] = time()-60; break;
            case 'future': $payload['iat'] = time()+120; $payload['nbf'] = time()+120; $payload['exp'] = time()+900; break;
            case 'issuer': $payload['iss'] = 'other'; break;
            case 'audience': $payload['aud'] = 'other'; break;
            case 'missing-exp': unset($payload['exp']); break;
            case 'invalid-role': $payload['role'] = 'root'; break;
            case 'invalid-subject': $payload['sub'] = '0'; break;
            case 'missing-jti': unset($payload['jti']); break;
            case 'excessive-lifetime': $payload['exp'] = $payload['iat'] + 7200; break;
        }
        $badTokens[$case] = JWT::encode($payload, $privateKey, 'RS256');
    }
    $badTokens['wrong-algorithm'] = JWT::encode($base, str_repeat('x',64), 'HS256');
    foreach ($badTokens as $label => $badToken) {
        foreach (['auth/me','inventario/session'] as $path) {
            [$status] = request($path,'GET',$badToken); check($status === 401, "$label rejected by $path");
        }
    }
    [$status] = request('auth/login','GET'); check($status === 405, 'Login method validation');
    [$status] = request('auth/login','POST',null,'{'); check($status === 400, 'Malformed JSON');
    [$status] = request('auth/login','POST',null,'{}','text/plain'); check($status === 415, 'Content type validation');
    [$status] = request('auth/login','POST',null,'{"email":[],"password":"x"}'); check($status === 422, 'Input types validated');
    [$status] = request('auth/login','POST',null,str_repeat('x',8193)); check($status === 413, 'Request size limited');
    // Prueba el registro y listado usando únicamente las cuentas temporales de esta ejecución.
    [$status] = request('auth/register','POST',null,'{}'); check($status === 401, 'Register requires authentication');
    [$status] = request('auth/register','POST',$cashier['access_token'],'{}'); check($status === 403, 'Cashier cannot register users');
    [$status] = request('auth/users','GET',$cashier['access_token']); check($status === 403, 'Cashier cannot list users');
    [$status] = request('auth/users'); check($status === 401, 'User list requires authentication');
    [$status,$verified] = request('auth/verify','POST',$token); check($status === 200 && $verified['valid'] === true && $verified['user']['id'] === $ids['administrador'], 'Verify returns current identity');
    [$status] = request('auth/verify','POST'); check($status === 401, 'Verify requires bearer token');
    [$status] = request('auth/verify','GET',$token); check($status === 405, 'Verify requires POST');
    foreach ($badTokens as $label=>$badToken) {
        [$status] = request('auth/verify','POST',$badToken); check($status === 401, "Verify rejects $label");
    }
    foreach (['cajero','administrador'] as $role) {
        $new=['nombre'=>$tag,'email'=>"$tag-new-$role@example.invalid",'password'=>$password,'rol'=>$role];
        [$status,$created] = request('auth/register','POST',$token,json_encode($new,JSON_THROW_ON_ERROR));
        check($status === 201 && $created['user']['rol'] === $role, "Register $role");
        $ids['new-'.$role] = $created['user']['id'];
        $q=$db->prepare('SELECT password_hash FROM usuarios WHERE id=?'); $q->execute([$ids['new-'.$role]]);
        $hash=$q->fetchColumn(); check(password_verify($password,$hash) && password_get_info($hash)['algoName'] === 'bcrypt', 'Registration stores bcrypt');
        [$status] = login($new['email'],$password); check($status === 200, 'Registered account can login');
        [$status] = request('auth/register','POST',$token,json_encode($new,JSON_THROW_ON_ERROR)); check($status === 409, 'Duplicate registration rejected');
    }
    [$status,$users] = request('auth/users','GET',$token); check($status === 200 && in_array($ids['new-cajero'],array_column($users['usuarios'],'id')), 'Admin lists newly registered users');
    $new['email']="$tag-invalid@example.invalid";
    foreach (['rol'=>'root','password'=>'short','nombre'=>[],'email'=>'invalid'] as $field=>$value) {
        [$status] = request('auth/register','POST',$token,json_encode(array_replace($new,[$field=>$value]),JSON_THROW_ON_ERROR)); check($status === 422, "Registration validates $field");
    }
    [$status] = request('auth/register','POST',$token,'{'); check($status === 400, 'Registration rejects malformed JSON');
    [$status] = request('auth/register','POST',$token,'{}','text/plain'); check($status === 415, 'Registration validates content type');
    [$status] = request('auth/register','POST',$token,str_repeat('x',8193)); check($status === 413, 'Registration limits body size');
    [$status] = request('auth/users','POST',$token); check($status === 405, 'Users requires GET');
    // Inventario debe usar el rol actual aunque el JWT aún contenga el rol anterior.
    $q=$db->prepare("UPDATE usuarios SET rol='cajero' WHERE id=?"); $q->execute([$ids['administrador']]);
    [$status] = request('auth/users','GET',$token); check($status === 403, 'Old admin token cannot list users after downgrade');
    [$status] = request('auth/register','POST',$token,'{}'); check($status === 403, 'Old admin token cannot register after downgrade');
    [$status,$session] = request('inventario/session','GET',$token); check($status === 200 && $session['role'] === 'cajero', 'Inventory applies current role through REST');
    [$status] = request('inventario/categorias','POST',$token,'{}'); check($status === 403, 'Inventory blocks writes with downgraded role');
    $q = $db->prepare('UPDATE usuarios SET activo=0 WHERE id=?'); $q->execute([$ids['administrador']]);
    [$status] = request('auth/me','GET',$token); check($status === 401, 'Profile checks current active status');
    [$status] = request('auth/verify','POST',$token); check($status === 401, 'Verify rejects inactive user');
    [$status] = request('inventario/session','GET',$token); check($status === 401, 'Inventory rejects inactive user through REST');

    foreach (['auth/health','inventario/health','ventas/health','reportes/health','auth/ready','inventario/ready'] as $path) {
        [$status] = request($path); check($status === 200, 'Infrastructure regression ' . $path);
    }
} finally {
    $q = $db->prepare('DELETE FROM usuarios WHERE id=? AND nombre=?');
    foreach ($ids as $id) { $q->execute([$id,$tag]); }
    echo "Temporary test accounts removed.\n";
}
