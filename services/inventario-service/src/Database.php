<?php
declare(strict_types=1);

// Conecta este servicio con su propia base de datos usando PDO.
final class Database
{
// Lee las credenciales del contenedor y devuelve una conexión lista para consultas.
    public static function connect(): PDO
    {
        $values = [];
// Comprueba que ninguna variable de conexión esté vacía.
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                throw new RuntimeException('Missing database configuration');
            }
            $values[$name] = $value;
        }
// Usa UTF-8; los errores SQL lanzan excepciones y las consultas preparadas son reales.
        return new PDO(
            sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', $values['DB_HOST'], $values['DB_NAME']),
            $values['DB_USER'],
            $values['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }
}
