<?php
declare(strict_types=1);

// Conecta este servicio con su propia base de datos usando PDO.
final class Database
{
// Lee las credenciales del contenedor y devuelve una conexión lista para consultas.
    public static function connect(): PDO
    {
        return self::connection('DB');
    }

    // Solo los reportes usan la réplica y una cuenta sin permisos de escritura.
    public static function reports(): PDO
    {
        $db = self::connection('REPORTS_DB');
        $status = $db->query('SHOW SLAVE STATUS')->fetch();
        if (!$status || $status['Slave_IO_Running']!=='Yes' || $status['Slave_SQL_Running']!=='Yes'
            || $status['Seconds_Behind_Master']===null || (int)$status['Seconds_Behind_Master']>30) {
            throw new HttpError(503,'La réplica de reportes no está sincronizada. Intenta de nuevo');
        }
        return $db;
    }

    private static function connection(string $prefix): PDO
    {
        $values = [];
// Comprueba que ninguna variable de conexión esté vacía.
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
            $setting = $name==='DB_NAME' ? 'DB_NAME' : $prefix.substr($name,2);
            $value = getenv($setting);
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
