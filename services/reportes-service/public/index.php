<?php
declare(strict_types=1);
// Reportes consulta Ventas por REST y genera sus documentos sin leer bases ajenas.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/ReportClient.php';
require __DIR__.'/../src/ReportPdf.php';
require __DIR__.'/../src/integrations/WeatherClient.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
function respond(int $status, array $data): never {
    http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
if ($path === '/health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, ['service'=>'reportes-service','status'=>'ok','phase'=>1]);
}
if (!in_array($path,['/resumen','/ventas','/top-productos','/pdf','/clima'],true)) respond(404,['error'=>'Ruta no encontrada']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET'); respond(405,['error'=>'Método no permitido']);
}
try {
    if($path==='/clima') {
        ReportClient::authorizeWeather($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        respond(200,['clima'=>WeatherClient::current()]);
    }
    // Ventas verifica el JWT y el rol actual antes de entregar reportes.
    $data = ReportClient::fetch($_GET, $_SERVER['HTTP_AUTHORIZATION'] ?? '', $path === '/pdf');
    if ($path === '/pdf') {
        $pdf = ReportPdf::generate($data);
        $filename = 'reporte-ventas-'.$data['periodo']['desde'].'-'.$data['periodo']['hasta'].'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($pdf)); echo $pdf; exit;
    }
    if ($path === '/ventas') {
        respond(200,array_intersect_key($data,array_flip(['periodo','resumen','agrupacion','serie','ventas','paginacion'])));
    }
    if ($path === '/top-productos') respond(200,['periodo'=>$data['periodo'],'productos'=>$data['productos']]);
    respond(200,$data);
} catch (ReportError $error) {
    if ($error->status === 401) header('WWW-Authenticate: Bearer');
    respond($error->status,['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Report request failed: '.get_class($error));
    respond(503,['error'=>'No se pudo obtener el reporte. Puedes reintentarlo']);
}
