<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Guarda el código HTTP y el mensaje que recibirá el cliente cuando haya un error esperado.
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

// Reúne la creación y la revisión de los tokens JWT. Inventario solo utiliza la revisión.
final class TokenService
{
// Obtiene una variable JWT y avisa si falta en la configuración.
    private static function setting(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') { throw new RuntimeException('Missing JWT configuration'); }
        return $value;
    }

// Crea un token firmado con la clave privada; solo Autenticación dispone de esa clave.
    public static function issue(array $user): string
    {
        $now = time();
        $ttl = filter_var(self::setting('JWT_TTL'), FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 60 || $ttl > 3600) { throw new RuntimeException('Invalid JWT TTL'); }
        $key = file_get_contents(self::setting('JWT_PRIVATE_KEY_FILE'));
        if ($key === false) { throw new RuntimeException('Private key unavailable'); }
// iss: emisor; aud: destinatario; sub: usuario; role: rol. iat/nbf/exp son fechas en segundos; jti identifica el token.
        return JWT::encode([
            'iss' => self::setting('JWT_ISSUER'), 'aud' => self::setting('JWT_AUDIENCE'),
            'sub' => (string)$user['id'], 'role' => $user['rol'],
            'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ], $key, 'RS256');
    }

// Comprueba la firma con la clave pública y devuelve los datos del token si son válidos.
    public static function verify(string $token): object
    {
        $publicKey = file_get_contents(self::setting('JWT_PUBLIC_KEY_FILE'));
        if ($publicKey === false) { throw new RuntimeException('Public key unavailable'); }
        $issuer = self::setting('JWT_ISSUER'); $audience = self::setting('JWT_AUDIENCE');
        // Un fallo de configuración debe informarse como error del servidor, no como token incorrecto.
        $key = new Key($publicKey, 'RS256');
        try {
            $claims = JWT::decode($token, $key);
// Además de la firma, exige el emisor, la audiencia, el usuario, el rol y las fechas esperadas.
            if (($claims->iss ?? null) !== $issuer || ($claims->aud ?? null) !== $audience
                || !is_string($claims->sub ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $claims->sub)
                || !in_array($claims->role ?? null, ['administrador', 'cajero'], true)
                || !is_int($claims->iat ?? null) || !is_int($claims->nbf ?? null) || !is_int($claims->exp ?? null)
                || $claims->iat > time() || $claims->nbf < $claims->iat || $claims->exp <= $claims->nbf
                || $claims->exp - $claims->iat > 3600 || !is_string($claims->jti ?? null) || $claims->jti === '') {
                throw new UnexpectedValueException('Invalid claims');
            }
            return $claims;
        } catch (UnexpectedValueException | DomainException $error) {
            throw new HttpError(401, 'Token inválido o vencido');
        }
    }

// Lee Authorization: Bearer <token> de la petición HTTP.
    public static function fromRequest(): object
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $header, $match)
            || strlen($header) > 8192) { throw new HttpError(401, 'Se requiere un token Bearer válido'); }
        return self::verify($match[1]);
    }

// Devuelve 403 si el rol del usuario no está entre los permitidos para la operación.
    public static function requireRole(object $claims, array $roles): void
    {
        if (!in_array($claims->role, $roles, true)) { throw new HttpError(403, 'Permisos insuficientes'); }
    }
}
