<?php
declare(strict_types=1);

// Ventas pregunta por REST a Autenticación; nunca lee la base de otro servicio.
final class AuthClient
{
    public static function current(): object
    {
        // Primero rechaza firmas incorrectas y tokens vencidos mediante la clave pública.
        $claims = TokenService::fromRequest();
        $base = getenv('AUTH_URL');
        if (!$base) { throw new HttpError(503, 'Falta configurar el servicio de autenticación'); }
        $curl = curl_init(rtrim($base, '/') . '/verify');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            // La verificación usa la cabecera Bearer y no necesita un cuerpo JSON.
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'], 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response === false || $status === 0) { throw new HttpError(503, 'Autenticación no está disponible; intenta de nuevo'); }
        if ($status === 401) { throw new HttpError(401, 'Token inválido o usuario no disponible'); }
        if ($status !== 200) { throw new HttpError(503, 'No se pudo verificar la sesión'); }
        try { $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError(503, 'Respuesta de autenticación inválida'); }
        if (($data['valid'] ?? null) !== true || (string)($data['user']['id'] ?? '') !== $claims->sub
            || !in_array($data['user']['rol'] ?? null, ['administrador','cajero'], true)) {
            throw new HttpError(503, 'Respuesta de autenticación inválida');
        }
        // Aplica el rol actual confirmado por Autenticación a esta petición.
        $claims->role = $data['user']['rol'];
        return $claims;
    }
}
