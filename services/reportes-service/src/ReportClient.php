<?php
declare(strict_types=1);

// Conserva el código HTTP para devolver errores claros sin mostrar detalles internos.
final class ReportError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

final class ReportClient
{
    // El clima solo requiere una sesión activa; no consulta estadísticas de Ventas.
    public static function authorizeWeather(string $header): void
    {
        if(!preg_match('/^Bearer [A-Za-z0-9_.-]+$/iD',$header) || strlen($header)>8192) throw new ReportError(401,'Se requiere un token Bearer válido');
        $base=getenv('AUTH_URL');
        if(!$base) throw new ReportError(503,'Falta configurar Autenticación');
        $curl=curl_init(rtrim($base,'/').'/verify');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: '.$header],
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
        $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($raw===false) throw new ReportError(503,'Autenticación no está disponible');
        if($status===401 || $status===403) throw new ReportError($status,'Sesión no válida para consultar el clima');
        if($status!==200) throw new ReportError(503,'No se pudo verificar la sesión');
        try {$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        catch(JsonException $error) {throw new ReportError(503,'Respuesta de autenticación inválida');}
        if(($data['valid'] ?? null)!==true || !in_array($data['user']['rol'] ?? null,['administrador','cajero'],true)) throw new ReportError(403,'Permisos insuficientes');
    }

    public static function fetch(array $filters, string $header, bool $full = false): array
    {
        if (!preg_match('/^Bearer [A-Za-z0-9_.-]+$/iD', $header) || strlen($header)>8192) {
            throw new ReportError(401,'Se requiere un token Bearer válido');
        }
        $base = getenv('VENTAS_URL');
        if (!$base) throw new ReportError(503,'Falta configurar la comunicación con Ventas');
        $query = http_build_query(array_intersect_key($filters,array_flip(['desde','hasta','pagina','agrupacion'])));
        $route = $full ? '/reportes/exportar' : '/reportes/resumen';
        $curl = curl_init(rtrim($base,'/').$route.'?'.$query);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: '.$header],
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false]);
        $raw = curl_exec($curl); $status = curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw===false || !in_array($status,[200,401,403,422],true)) {
            throw new ReportError(503,'No se pudo consultar Ventas. Intenta de nuevo');
        }
        $data = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if ($status!==200) throw new ReportError($status,$data['error'] ?? 'No se pudo consultar el reporte');
        if (!is_array($data) || !isset($data['periodo'],$data['resumen'],$data['serie'],$data['ventas'])) {
            throw new ReportError(503,'La respuesta del reporte no es válida');
        }
        return $data;
    }
}
