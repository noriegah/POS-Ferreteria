<?php
// Prueba el CRUD por HTTP con tokens temporales y limpia únicamente sus propios registros.
declare(strict_types=1);
require '/app/src/Database.php';
$tokens = json_decode(trim((string)fgets(STDIN)), true, 16, JSON_THROW_ON_ERROR);
$admin = $tokens['admin']; $cashier = $tokens['cashier'];
$tag = 'crud_' . bin2hex(random_bytes(8));
$db = Database::connect();
$uploaded = [];
// Muestra PASS si la comprobación se cumple; si falla, detiene la prueba con un error.
function check(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    echo "PASS: $name\n";
}
// Envía una petición a Inventario con el método, el token y los datos que se quieran probar.
function api(string $path, string $method, ?string $token, mixed $body = null, bool $raw = false): array {
    $headers = "Content-Type: application/json\r\n";
    if ($token !== null) { $headers .= "Authorization: Bearer $token\r\n"; }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers,
        'content' => $body === null ? '' : ($raw ? $body : json_encode($body, JSON_THROW_ON_ERROR)),
        'ignore_errors' => true, 'timeout' => 15]]);
    $response = file_get_contents('http://gateway/api/inventario/' . $path, false, $context);
    if ($response === false) { throw new RuntimeException('HTTP request failed'); }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int)$match[1], json_decode($response, true, 32, JSON_THROW_ON_ERROR)];
}
// Envía un archivo temporal multipart para comprobar la misma ruta que usa el navegador.
function uploadImage(int $id, ?string $token, string $bytes): array {
    $file=tempnam(sys_get_temp_dir(),'posimage');
    try {
        file_put_contents($file,$bytes);
        $curl=curl_init("http://gateway/api/inventario/productos/$id/imagen");
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>$token===null ? [] : ['Authorization: Bearer '.$token],
            CURLOPT_POSTFIELDS=>['imagen'=>new CURLFile($file,'image/png','upload.php')]]);
        $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if($body===false) throw new RuntimeException('Image HTTP request failed');
        return [$status,json_decode($body,true,32,JSON_THROW_ON_ERROR)];
    } finally { if(is_file($file)) unlink($file); }
}

try {
    [$status] = api('productos','GET',null); check($status === 401, 'Products require JWT');
    [$status] = api('categorias','POST',$cashier,['nombre'=>$tag]); check($status === 403, 'Cashier cannot create categories');
    [$status,$body] = api('categorias','POST',$admin,['nombre'=>$tag]);
    check($status === 201, 'Create category'); $category = $body['categoria']['id'];
    [$status] = api('categorias','POST',$admin,['nombre'=>$tag]); check($status === 409, 'Duplicate category rejected');
    [$status,$body] = api('categorias','GET',$cashier); check($status === 200 && in_array($category,array_column($body['categorias'],'id')), 'Cashier lists categories');
    $product = ['categoria_id'=>$category,'codigo'=>$tag,'nombre'=>'Martillo de prueba','descripcion'=>'Temporal','precio'=>'25.50','stock'=>'10.000','stock_minimo'=>'2.000'];
    [$status] = api('productos','POST',$cashier,$product); check($status === 403, 'Cashier cannot create products');
    [$status,$body] = api('productos','POST',$admin,$product); check($status === 201, 'Create product'); $id = $body['producto']['id'];
    check($body['producto']['precio'] === '25.50' && $body['producto']['stock'] === '10.000', 'Exact price and initial stock');
    [$status,$body] = api('productos','GET',$cashier); check($status === 200 && in_array($id,array_column($body['productos'],'id')), 'List products');
    [$status,$body] = api("productos/$id",'GET',$cashier); check($status === 200 && $body['producto']['codigo'] === $tag, 'Read single product');
    [$status] = api('productos','POST',$admin,$product); check($status === 409, 'Duplicate code rejected');
    foreach (['precio'=> '-1','stock'=> '-2','stock_minimo'=> '1.0001','categoria_id'=>'999999999999999999','nombre'=>[]] as $field=>$bad) {
        [$status] = api('productos','POST',$admin,array_replace($product,[$field=>$bad])); check($status === 422, "Invalid $field rejected");
    }
    foreach (['stock'=>'1.5', 'stock_minimo'=>'0.5'] as $field=>$bad) {
        [$status] = api('productos','POST',$admin,array_replace($product,[$field=>$bad]));
        check($status === 422, 'Fractional units rejected: ' . $field);
    }
    [$status] = api('productos','POST',$admin,'{',true); check($status === 400, 'Malformed JSON rejected');
    [$status] = api('productos','POST',$admin,[]); check($status === 422, 'Array body rejected');
    [$status] = api("productos/$id",'PUT',$cashier,$product); check($status === 403, 'Cashier cannot edit');
    $product['nombre'] = 'Martillo editado'; $product['precio'] = '30.00'; $product['stock'] = '8.000';
    [$status,$body] = api("productos/$id",'PUT',$admin,$product);
    check($status === 200 && $body['producto']['nombre'] === 'Martillo editado' && $body['producto']['stock'] === '8.000', 'Edit product');
    $q = $db->prepare('SELECT cantidad,stock_anterior,stock_nuevo FROM movimientos_stock WHERE producto_id=? ORDER BY id'); $q->execute([$id]); $moves = $q->fetchAll();
    check(count($moves) === 2 && $moves[0]['cantidad'] === '10.000' && $moves[1]['cantidad'] === '-2.000' && $moves[1]['stock_nuevo'] === '8.000', 'Initial and edited stock movements are consistent');
    [$status] = api("productos/$id",'PUT',$admin,$product); check($status === 200, 'Unchanged edit accepted');
    $q->execute([$id]); check(count($q->fetchAll()) === 2, 'Unchanged stock creates no extra movement');
    $second = array_replace($product,['codigo'=>$tag.'_second']);
    [$status,$body] = api('productos','POST',$admin,$second); check($status === 201, 'Second product created'); $secondId = $body['producto']['id'];
    $conflict = array_replace($product,['codigo'=>$second['codigo'],'stock'=>'1']);
    [$status] = api("productos/$id",'PUT',$admin,$conflict); check($status === 409, 'Conflicting edit rejected');
    [$status,$body] = api("productos/$id",'GET',$admin); check($body['producto']['stock'] === '8.000' && $body['producto']['codigo'] === $tag, 'Failed edit rolled back');
    // Filtra solo la categoría temporal para no depender de los datos del proyecto.
    [$status,$body] = api("productos?categoria_id=$category&limite=1&pagina=1",'GET',$cashier);
    check($status === 200 && count($body['productos']) === 1 && $body['paginacion']['total'] === 2 && $body['paginacion']['paginas'] === 2, 'Category filter and pagination totals');
    $pageId = $body['productos'][0]['id'];
    [$status,$body] = api("productos?categoria_id=$category&limite=1&pagina=2",'GET',$admin);
    check($status === 200 && count($body['productos']) === 1 && $body['productos'][0]['id'] !== $pageId, 'Second page has a different product');
    [$status,$body] = api('productos?categoria_id='.$category.'&buscar='.rawurlencode($tag.'_second'),'GET',$admin);
    check($status === 200 && count($body['productos']) === 1 && $body['productos'][0]['id'] == $secondId, 'Search by product code');
    [$status,$body] = api("productos?categoria_id=$category&buscar=Martillo",'GET',$cashier);
    check($status === 200 && $body['paginacion']['total'] === 2, 'Search by product name');
    [$status,$body] = api("productos?categoria_id=$category&buscar=%25",'GET',$admin);
    check($status === 200 && $body['productos'] === [], 'Search treats percent sign literally');
    foreach (['pagina=0','limite=0','limite=51','categoria_id=-1','buscar[]=x','stock_bajo=maybe'] as $badQuery) {
        [$status] = api('productos?'.$badQuery,'GET',$admin); check($status === 422, 'Invalid list parameter '.$badQuery);
    }
    [$status,$body] = api("productos?categoria_id=$category&pagina=999999",'GET',$admin);
    check($status === 200 && $body['paginacion']['pagina'] === 1, 'Out of range page uses last available page');
    $second['stock'] = '4.000';
    [$status] = api("productos/$secondId",'PUT',$admin,$second); check($status === 200, 'Prepare low stock product');
    $boundary = array_replace($product,['codigo'=>$tag.'_five','stock'=>'5.000']);
    [$status,$body] = api('productos','POST',$admin,$boundary); check($status === 201, 'Create five-unit boundary product'); $boundaryId=$body['producto']['id'];
    [$status,$body] = api('alertas','GET',$cashier); $alertIds=array_column($body['productos'],'id');
    check($status === 200 && $body['umbral'] === 5 && in_array($secondId,$alertIds) && !in_array($boundaryId,$alertIds) && !in_array($id,$alertIds), 'Alerts use stock strictly below five');
    [$status,$body] = api("productos?categoria_id=$category&stock_bajo=1",'GET',$cashier);
    check($status === 200 && $body['paginacion']['total'] === 1 && $body['productos'][0]['id'] == $secondId, 'Low stock filter');
    [$status] = api('alertas','GET',null); check($status === 401, 'Alerts require JWT');
    [$status] = api('alertas','POST',$admin); check($status === 405, 'Alerts require GET');

    // Archivo PNG mínimo de prueba; no contiene datos del usuario.
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    [$status] = uploadImage($id,$cashier,$png); check($status === 403, 'Cashier cannot upload product image');
    [$status] = uploadImage($id,null,$png); check($status === 401, 'Image upload requires JWT');
    [$status] = api("productos/$id/imagen",'POST',$admin); check($status === 422, 'Image file is required');
    [$status] = uploadImage($id,$admin,'this is not a picture'); check($status === 422, 'False image content rejected');
    [$status] = uploadImage($id,$admin,str_repeat('a',2*1024*1024+1)); check($status === 413, 'Image larger than two MB rejected');
    [$status,$body] = uploadImage($id,$admin,$png); check($status === 200, 'Upload valid PNG');
    $imageUrl=$body['producto']['imagen_url']; $uploaded[]=$imageUrl;
    check((bool)preg_match('#^/uploads/productos/[a-f0-9]{32}\.png$#D',$imageUrl), 'Server creates safe random filename');
    $photo=file_get_contents('http://gateway'.$imageUrl);
    check($photo === $png, 'Nginx serves image from persistent shared folder');
    [$status,$body] = api("productos/$id",'GET',$cashier); check($body['producto']['imagen_url'] === $imageUrl, 'Product retains image URL');
    [$status,$body] = api("productos/$id",'PUT',$admin,$product); check($status === 200 && $body['producto']['imagen_url'] === $imageUrl, 'Editing product preserves existing photo');
    [$status] = uploadImage(999999999999999999,$admin,$png); check($status === 404, 'Image upload rejects missing product');
    [$status] = api("productos/$id/imagen",'GET',$admin); check($status === 405, 'Image upload requires POST');

    [$status] = api("productos/$id",'DELETE',$cashier); check($status === 403, 'Cashier cannot deactivate');
    [$status] = api("productos/$id",'DELETE',$admin); check($status === 200, 'Deactivate product');
    [$status] = api("productos/$id",'GET',$admin); check($status === 404, 'Inactive product hidden from reads');
    [$status,$body] = api('productos','GET',$admin); check(!in_array($id,array_column($body['productos'],'id')), 'Inactive product excluded from list');
    $q = $db->prepare('SELECT activo FROM productos WHERE id=?'); $q->execute([$id]); check((int)$q->fetchColumn() === 0, 'Deactivation preserves database row');
    [$status] = api("productos/$id",'PUT',$admin,$product); check($status === 404, 'Inactive product cannot be edited');
    [$status] = api("productos/$id",'DELETE',$admin); check($status === 404, 'Repeated deactivation returns not found');
    [$status] = api('productos/999999999999999999','GET',$admin); check($status === 404, 'Missing product returns not found');
    [$status] = api('productos/0','GET',$admin); check($status === 422, 'Invalid id rejected');
    [$status] = api('productos','PATCH',$admin); check($status === 405, 'Unsupported method rejected');
    // Código interno basado en el id, y códigos de barras conservados como texto.
    $automatic = array_replace($product,['codigo'=>'']);
    [$status,$auto] = api('productos','POST',$admin,$automatic);
    check($status === 201 && $auto['producto']['codigo'] === 'PR-'.str_pad((string)$auto['producto']['id'],6,'0',STR_PAD_LEFT), 'Empty code generates internal code from id');
    unset($automatic['codigo']);
    [$status,$auto2] = api('productos','POST',$admin,$automatic);
    check($status === 201 && $auto2['producto']['codigo'] !== $auto['producto']['codigo'], 'Omitted code generates another unique code');
    [$status,$kept] = api('productos/'.$auto['producto']['id'],'PUT',$admin,array_replace($product,['codigo'=>'']));
    check($status === 200 && $kept['producto']['codigo'] === $auto['producto']['codigo'], 'Blank edit preserves generated code');
    $barcode = '001'.random_int(100000000,999999999);
    [$status,$bar] = api('productos','POST',$admin,array_replace($product,['codigo'=>$barcode]));
    check($status === 201 && $bar['producto']['codigo'] === $barcode, 'Barcode preserves leading zeros');
    [$status] = api('productos','POST',$admin,array_replace($product,['codigo'=>$barcode]));
    check($status === 409, 'Duplicate barcode rejected');
    [$status] = api('productos','POST',$admin,array_replace($product,['codigo'=>'PR-999999999']));
    check($status === 422, 'Automatic prefix is reserved on creation');
    [$status] = api('productos/'.$bar['producto']['id'],'PUT',$admin,array_replace($product,['codigo'=>'PR-999999999']));
    check($status === 422, 'Automatic prefix is reserved on manual edit');
    [$status] = api('health','GET',null); check($status === 200, 'Inventory health');
    [$status] = api('ready','GET',null); check($status === 200, 'Inventory database ready');
} finally {
    // Borra solo los registros asociados a la categoría de nombre único creada por esta prueba.
    $q = $db->prepare('SELECT id FROM categorias WHERE nombre=?'); $q->execute([$tag]); $categoryId = $q->fetchColumn();
    if ($categoryId !== false) {
        // Agrupa estas consultas en una transacción para poder confirmarlas o revertirlas juntas.
$db->beginTransaction();
        try {
            $q = $db->prepare('DELETE m FROM movimientos_stock m JOIN productos p ON p.id=m.producto_id WHERE p.categoria_id=?'); $q->execute([$categoryId]);
            $q = $db->prepare('DELETE FROM productos WHERE categoria_id=?'); $q->execute([$categoryId]);
            $q = $db->prepare('DELETE FROM categorias WHERE id=? AND nombre=?'); $q->execute([$categoryId,$tag]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
    // Quita únicamente las imágenes de nombre aleatorio recibidas por esta prueba.
    foreach($uploaded as $url) {
        if(preg_match('#^/uploads/productos/([a-f0-9]{32}\.png)$#D',$url,$match)) {
            $file='/app/uploads/'.$match[1]; if(is_file($file)) unlink($file);
        }
    }
    echo "Temporary inventory records removed.\n";
}
