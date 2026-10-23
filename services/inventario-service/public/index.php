<?php
declare(strict_types=1);
// Carga JWT, PDO y las operaciones de productos.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/TokenService.php';
require_once __DIR__ . '/../src/Products.php';
require_once __DIR__ . '/../src/ProductImages.php';
require_once __DIR__ . '/../src/AuthClient.php';
require_once __DIR__ . '/../src/Checkout.php';
require_once __DIR__ . '/../src/StockSales.php';
require_once __DIR__ . '/../src/StockReturns.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Nginx quita /api/inventario antes de enviar la ruta a este archivo.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
// Devuelve una respuesta JSON con su código HTTP y termina la petición.
function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
// Comprueba qué métodos HTTP se admiten en la ruta; devuelve 405 si no se permite el solicitado.
function methods(array $allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new HttpError(405, 'Método no permitido');
    }
}
try {
    // El JWT identifica al vendedor y la clave interna restringe el descuento al servicio Ventas.
    if (in_array($path, ['/ventas/descontar','/ventas/devolver'],true)) {
        methods(['POST']);
        $key = getenv('VENTAS_INTERNAL_KEY');
        if (!$key) { throw new HttpError(503, 'Comunicación de Ventas sin configurar'); }
        if (!hash_equals($key, $_SERVER['HTTP_X_VENTAS_KEY'] ?? '')) { throw new HttpError(403, 'Operación reservada al servicio de Ventas'); }
        $claims = AuthClient::current();
        if($path==='/ventas/devolver') {
            TokenService::requireRole($claims,['administrador']);
            respond(200,StockReturns::restore(Database::connect(),Products::body(),$claims->sub));
        }
        respond(200, StockSales::discount(Database::connect(), Products::body(), $claims->sub));
    }
// Confirma que PHP responde, sin comprobar la base de datos.
    if ($path === '/health' && $method === 'GET') {
        respond(200, ['service' => 'inventario-service', 'status' => 'ok', 'phase' => 1]);
    }
// Hace una consulta pequeña para verificar la conexión con MariaDB.
    if ($path === '/ready' && $method === 'GET') {
        try { Database::connect()->query('SELECT 1'); }
        catch (Throwable $e) { respond(503, ['service' => 'inventario-service', 'status' => 'unavailable', 'database' => 'unavailable']); }
        respond(200, ['service' => 'inventario-service', 'status' => 'ok', 'database' => 'ok']);
    }
// Muestra la identidad y los permisos que se obtienen del token válido.
    if ($path === '/session') {
        methods(['GET']); $claims = AuthClient::current();
        respond(200, ['user_id' => $claims->sub, 'role' => $claims->role, 'permissions' =>
            $claims->role === 'administrador' ? ['inventario:leer', 'inventario:escribir'] : ['inventario:leer']]);
    }
// Ambos roles pueden listar categorías; solo el administrador puede crearlas.
    if ($path === '/categorias') {
        $claims = AuthClient::current(); methods(['GET','POST']);
        if ($method === 'POST') { TokenService::requireRole($claims, ['administrador']); }
        $products = new Products(Database::connect());
        if ($method === 'GET') { respond(200, ['categorias' => $products->categories()]); }
        respond(201, ['categoria' => $products->createCategory(Products::body())]);
    }
    // Ambos roles consultan las alertas; solo el administrador carga imágenes.
    if ($path === '/alertas') {
        AuthClient::current(); methods(['GET']);
        respond(200, ['productos'=>(new Products(Database::connect()))->alerts(), 'umbral'=>5]);
    }
    if (preg_match('#^/productos/([0-9]+)/imagen$#D', $path, $imageMatch)) {
        $claims = AuthClient::current(); methods(['POST']);
        TokenService::requireRole($claims, ['administrador']);
        respond(200, ['producto'=>ProductImages::upload(Database::connect(), Products::id($imageMatch[1]))]);
    }
// Selecciona listar/crear o consultar/editar/desactivar según la ruta, el método y el rol.
    if ($path === '/productos' || preg_match('#^/productos/([0-9]+)$#D', $path, $matches)) {
        $claims = AuthClient::current();
        $id = $path === '/productos' ? null : Products::id($matches[1]);
        methods($id === null ? ['GET','POST'] : ['GET','PUT','DELETE']);
// Antes de modificar cualquier dato, comprueba que el usuario sea administrador.
        if ($method !== 'GET') { TokenService::requireRole($claims, ['administrador']); }
        $products = new Products(Database::connect());
        if ($method === 'GET') {
            respond(200, $id === null ? $products->all($_GET) : ['producto' => $products->find($id)]);
        }
        if ($method === 'DELETE') {
            $products->deactivate($id); respond(200, ['message' => 'Producto desactivado']);
        }
        respond($method === 'POST' ? 201 : 200, ['producto' => $products->save(Products::body(), $claims->sub, $id)]);
    }
    respond(404, ['error' => 'Endpoint aún no implementado', 'service' => 'inventario-service']);
} catch (HttpError $e) {
    if ($e->status === 401) { header('WWW-Authenticate: Bearer'); }
    respond($e->status, ['error' => $e->getMessage()]);
// Un valor único repetido devuelve 409; los demás errores de base de datos se informan sin detalles internos.
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1062) { respond(409, ['error' => 'El código del producto o el nombre de la categoría ya existe']); }
    error_log('Inventory database request failed: ' . get_class($e));
    respond(503, ['error' => 'Servicio temporalmente no disponible']);
} catch (Throwable $e) {
    error_log('Inventory request failed: ' . get_class($e));
    respond(503, ['error' => 'Servicio temporalmente no disponible']);
}
