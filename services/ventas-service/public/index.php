<?php
declare(strict_types=1);
// Carga las clases propias de Ventas y la biblioteca JWT ya usada por los otros servicios.
require_once __DIR__ . '/../vendor/autoload.php';
foreach (['Database','TokenService','AuthClient','Checkout','InventoryClient','Buyer','Sales','InvoicePdf','SalesReports','SalesHistory','SaleChanges','Customers','Payments'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}
require_once __DIR__ . '/../src/integrations/QrCodeClient.php';
require_once __DIR__ . '/../src/integrations/PayPalClient.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
// Finaliza cada petición con JSON y su código HTTP.
function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
function methods(array $allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed)); throw new HttpError(405, 'Método no permitido');
    }
}
try {
    // Conserva la comprobación de salud existente y añade la de conexión propia.
    if ($path === '/health' && $method === 'GET') { respond(200, ['service'=>'ventas-service', 'status'=>'ok', 'phase'=>1, 'instancia'=>gethostname()]); }
    if ($path === '/ready' && $method === 'GET') {
        Database::connect()->query('SELECT 1'); respond(200, ['service'=>'ventas-service','status'=>'ok','database'=>'ok']);
    }
    // El navegador solo conoce si Sandbox está configurado; nunca recibe las credenciales.
    if($path==='/pagos/config') {
        AuthClient::current(); methods(['GET']);
        respond(200,['paypal_disponible'=>PayPalClient::ready(),'tasa_demo'=>PayPalClient::rate(),'moneda'=>'USD']);
    }
    if(preg_match('#^/([0-9]+)/pago/(orden|confirmar)$#D',$path,$payment)) {
        $claims=AuthClient::current(); methods(['POST']);
        $service=new Payments(Database::connect()); $id=Checkout::id($payment[1]);
        if($payment[2]==='orden') respond(200,['pago'=>$service->order($id,$claims)]);
        respond(200,['venta'=>$service->capture($id,$claims)]);
    }
    // Solo el administrador obtiene estadísticas; nunca se comparten datos del comprador en los gráficos.
    if (in_array($path, ['/reportes/resumen','/reportes/exportar'], true)) {
        $claims = AuthClient::current(); TokenService::requireRole($claims, ['administrador']); methods(['GET']);
        respond(200, SalesReports::summary(Database::reports(), $_GET, $path === '/reportes/exportar'));
    }
    // Las modificaciones se autorizan en el servidor, incluso si se llama sin interfaz.
    if(preg_match('#^/([0-9]+)/(anular|eventos)$#D',$path,$change)) {
        $claims=AuthClient::current(); TokenService::requireRole($claims,['administrador']);
        $changes=new SaleChanges(Database::connect()); $id=Checkout::id($change[1]);
        if($change[2]==='eventos') { methods(['GET']); respond(200,['eventos'=>$changes->history($id,$claims)]); }
        methods(['POST']); respond(200,['venta'=>$changes->cancel($id,Checkout::body(),$claims)]);
    }
    // Comprueba la propiedad de la factura antes de leer o generar su imagen QR.
    if (preg_match('#^/([0-9]+)/factura/pdf$#D', $path, $pdfMatch)) {
        $claims=AuthClient::current(); methods(['GET']);
        $invoice=(new Sales(Database::connect()))->invoice(Checkout::id($pdfMatch[1]),$claims);
        $pdf=InvoicePdf::generate($invoice);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$invoice['numero'].'.pdf"');
        header('Content-Length: '.strlen($pdf)); echo $pdf; exit;
    }
    if (preg_match('#^/([0-9]+)/factura/qr$#D', $path, $qrMatch)) {
        $claims = AuthClient::current(); methods(['GET']);
        $invoice = (new Sales(Database::connect()))->invoice(Checkout::id($qrMatch[1]), $claims);
        $png = (new QrCodeClient())->image($invoice);
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        echo $png; exit;
    }
    // Consultar una factura no modifica la venta ni vuelve a descontar existencias.
    if (preg_match('#^/([0-9]+)/factura$#D', $path, $invoiceMatch)) {
        $claims = AuthClient::current(); methods(['GET']);
        respond(200, ['factura'=>(new Sales(Database::connect()))->invoice(Checkout::id($invoiceMatch[1]), $claims)]);
    }
    if ($path === '/' || preg_match('#^/([0-9]+)$#D', $path, $match)) {
        $claims = AuthClient::current();
        $sales = new Sales(Database::connect());
        if ($path === '/') {
            methods(['GET','POST']);
            if ($method === 'GET') respond(200, SalesHistory::listing(Database::connect(), $_GET, $claims));
            respond(201, ['venta'=>$sales->create(Checkout::body(), $claims)]);
        }
        methods(['GET','PATCH']);
        if($method==='PATCH') {
            TokenService::requireRole($claims,['administrador']);
            respond(200,['venta'=>(new SaleChanges(Database::connect()))->edit(Checkout::id($match[1]),Checkout::body(),$claims)]);
        }
        respond(200, ['venta'=>$sales->find(Checkout::id($match[1]), $claims)]);
    }
    respond(404, ['error'=>'Endpoint aún no implementado', 'service'=>'ventas-service']);
} catch (HttpError $e) {
    if ($e->status === 401) { header('WWW-Authenticate: Bearer'); }
    respond($e->status, ['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Sales request failed: ' . get_class($e));
    if (str_ends_with($path, '/factura/qr')) respond(503, ['error'=>'El QR no está disponible. Puedes reintentarlo desde la factura']);
    if (str_ends_with($path, '/factura/pdf')) respond(503, ['error'=>'No se pudo generar el PDF. Puedes reintentarlo sin repetir la venta']);
    if ($method === 'GET') respond(503, ['error'=>'No se pudieron consultar las ventas. Intenta de nuevo']);
    respond(503, ['error'=>'No se confirmó la venta. Reintenta la misma operación']);
}
