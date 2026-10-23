<?php
declare(strict_types=1);
// Datos sintéticos del año 2032: comprueba sumas y límites de mes sin tocar ventas reales.
require '/app/src/Database.php';
$tokens = json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$db = Database::connect(); $ids = []; $checks = 0;
function check(bool $value,string $message): void {
    global $checks; if (!$value) throw new RuntimeException('FAIL: '.$message);
    $checks++; echo "PASS: $message\n";
}
function api(string $query,?string $token,string $method='GET',string $service='reportes/resumen'): array {
    $curl = curl_init('http://gateway/api/'.$service.'?'.$query);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>$token ? ['Authorization: Bearer '.$token] : []]);
    $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if ($raw===false) throw new RuntimeException('Request failed');
    return [$status,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];
}
function sale(string $date,?string $total,int $quantity,?string $category,int $product=900000001): void {
    global $db,$ids,$tokens;
    $hex=bin2hex(random_bytes(16)); $ref=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20);
    $q=$db->prepare('INSERT INTO ventas(referencia,usuario_id,solicitud_hash,total,creado_en) VALUES (?,?,?,?,?)');
    $q->execute([$ref,$tokens['ids'][0],hash('sha256',$ref),$total,$date]); $id=$db->lastInsertId(); $ids[]=$id;
    if($total!==null) {
        $q=$db->prepare('INSERT INTO detalle_ventas(venta_id,producto_id,codigo,nombre,cantidad,precio,subtotal,categoria_nombre) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$id,$product,'TEST-'.$product,'Producto temporal '.$product,$quantity,'1.00',$total,$category]);
    }
}
// La descarga binaria se comprueba por separado de las respuestas JSON.
function pdf(string $query, ?string $token, string $method='GET'): array {
    $curl=curl_init('http://gateway/api/reportes/pdf?'.$query);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$token ? ['Authorization: Bearer '.$token] : []]);
    $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); $type=curl_getinfo($curl,CURLINFO_CONTENT_TYPE); curl_close($curl);
    return [$status,$type,$raw];
}
try {
    // Evita mezclar datos si alguien ya usó estas fechas en este entorno de desarrollo.
    $q=$db->query("SELECT COUNT(*) FROM ventas WHERE creado_en >= '2032-01-01' AND creado_en < '2033-01-01'");
    if((int)$q->fetchColumn()!==0) throw new RuntimeException('El año 2032 debe estar vacío para esta prueba');
    sale('2032-02-01 05:59:59','100.00',2,'Herramientas de prueba');
    sale('2032-02-01 06:00:00','75.00',3,'Pinturas de prueba',900000002);
    sale('2032-03-01 06:00:00','25.00',1,null);
    sale('2032-02-15 12:00:00',null,0,null);
    for($i=0;$i<26;$i++) sale('2032-01-15 12:00:00','1.00',1,'Herramientas de prueba');
    require '/app/src/TokenService.php';
    $replica=Database::reports(); $ready=false;
    for($attempt=0;$attempt<40;$attempt++) {
        $q=$replica->prepare('SELECT COUNT(*) FROM ventas WHERE usuario_id=?'); $q->execute([$tokens['ids'][0]]);
        if((int)$q->fetchColumn()===30) {$ready=true;break;}
        usleep(250000);
    }
    check($ready,'Datos temporales sincronizados con la réplica');
    $year='desde=2032-01-01&hasta=2032-12-31';
    [$status]=api($year,null); check($status===401,'Sin token no hay acceso');
    [$status]=api($year,'invalid'); check($status===401,'Token incorrecto rechazado');
    [$status]=api($year,$tokens['cashier']); check($status===403,'Cajero no consulta Reportes');
    [$status]=api($year,$tokens['cashier'],'GET','ventas/reportes/resumen'); check($status===403,'Ventas también protege su endpoint');
    [$status]=api($year,$tokens['admin'],'POST'); check($status===405,'Solo GET permitido');
    [$status,$data]=api($year,$tokens['admin']); check($status===200,'Administrador consulta el año completo');
    check($data['resumen']['ventas']===29 && $data['resumen']['total']==='226.00','Total exacto sin ventas pendientes');
    check($data['resumen']['unidades']===32 && $data['resumen']['promedio']==='7.79','Unidades y promedio correctos');
    check(count($data['meses'])===12 && $data['meses'][0]['total']==='126.00' && $data['meses'][1]['total']==='75.00','Agrupa por mes en hora de Guatemala');
    check($data['meses'][3]['ventas']===0 && $data['meses'][3]['total']==='0.00','Meses sin ventas incluyen cero');
    check($data['categorias'][0]['nombre']==='Herramientas de prueba' && $data['categorias'][0]['unidades']===28,'Ranking de categorías por unidades');
    check($data['categorias'][2]['nombre']==='Sin categoría registrada','Ventas antiguas conservan categoría desconocida');
    check($data['productos'][0]['unidades']===29 && $data['productos'][0]['total']==='151.00','Agrupa productos sin multiplicar los importes');
    check(count($data['ventas'])===25 && $data['paginacion']['paginas']===2,'Listado paginado en 25 ventas');
    [$status,$next]=api($year.'&pagina=2',$tokens['admin']); check($status===200 && count($next['ventas'])===4,'Segunda página completa sin repetir ventas');
    check(count(array_intersect(array_column($data['ventas'],'id'),array_column($next['ventas'],'id')))===0,'Las páginas no duplican registros');
    [$status,$day]=api('desde=2032-01-31&hasta=2032-01-31',$tokens['admin']);
    check($status===200 && $day['resumen']['total']==='100.00','Último segundo de enero incluido en el día local');
    [$status,$day]=api('desde=2032-02-01&hasta=2032-02-01',$tokens['admin']);
    check($status===200 && $day['resumen']['total']==='75.00','Primer segundo de febrero no se mezcla con enero');
    [$status,$empty]=api('desde=2032-04-01&hasta=2032-04-30',$tokens['admin']);
    check($status===200 && $empty['resumen']['ventas']===0 && $empty['categorias']===[] && $empty['ventas']===[],'Período vacío válido');
    foreach(['desde=2032-02-30','desde=2032-04-02&hasta=2032-04-01','desde=2031-01-01&hasta=2032-12-31','desde[]=2032-01-01','pagina=0','pagina[]=1',"desde=2032-01-01%27"] as $bad) {
        [$status]=api($bad,$tokens['admin']); check($status===422,'Filtro incorrecto rechazado: '.$bad);
    }
    [$status,$leap]=api('desde=2032-02-29&hasta=2032-02-29',$tokens['admin']); check($status===200,'Fecha válida de año bisiesto');
    // Cambiar la categoría actual no puede cambiar las categorías ya guardadas.
    check($db->query('SELECT COUNT(*) FROM ventas WHERE total IS NULL AND usuario_id='.(int)$tokens['ids'][0])->fetchColumn()==1,'El reporte no confirma ventas pendientes');
    [$status,$daily]=api($year.'&agrupacion=dia',$tokens['admin']);
    check($status===200 && count($daily['serie'])===366,'Serie diaria incluye los 366 días del año bisiesto');
    $byDate=array_column($daily['serie'],null,'periodo');
    check($byDate['2032-01-31']['total']==='100.00' && $byDate['2032-02-01']['total']==='75.00','Serie diaria usa fechas de Guatemala');
    check($byDate['2032-02-29']['total']==='0.00','Día sin ventas se completa con cero');
    [$status,$weekly]=api($year.'&agrupacion=semana',$tokens['admin']);
    $byWeek=array_column($weekly['serie'],null,'periodo');
    check($status===200 && $byWeek['2032-01-26']['total']==='175.00','Semana de lunes a domingo suma enero y febrero correctamente');
    check($weekly['serie'][0]['desde']==='2032-01-01' && $weekly['serie'][0]['periodo']==='2031-12-29','Primera semana conserva su lunes del año anterior y recorta el filtro');
    [$status,$partial]=api('desde=2032-01-31&hasta=2032-02-01&agrupacion=semana',$tokens['admin']);
    check($status===200 && count($partial['serie'])===1 && $partial['serie'][0]['total']==='175.00' && $partial['serie'][0]['hasta']==='2032-02-01','Semana parcial respeta exactamente los días seleccionados');
    foreach(['dia','semana','mes'] as $group) {
        [$status,$grouped]=api($year.'&agrupacion='.$group,$tokens['admin']);
        $cents=array_sum(array_map(static fn($r)=>(int)round((float)$r['total']*100),$grouped['serie']));
        check($cents===22600 && array_sum(array_column($grouped['serie'],'ventas'))===29,'Serie y resumen coinciden para '.$group);
    }
    foreach(['agrupacion=otro','agrupacion[]=mes'] as $bad) {
        [$status]=api($year.'&'.$bad,$tokens['admin']); check($status===422,'Agrupación inválida rechazada');
    }
    [$status,$list]=api($year,$tokens['admin'],'GET','reportes/ventas');
    check($status===200 && count($list['ventas'])===25 && $list['resumen']['total']==='226.00','Ruta solicitada de ventas entrega listado y totales');
    [$status,$top]=api($year,$tokens['admin'],'GET','reportes/top-productos');
    check($status===200 && $top['productos']===$data['productos'],'Ruta solicitada de top productos mantiene el ranking');
    foreach(['reportes/ventas','reportes/top-productos'] as $route) {
        [$status]=api($year,$tokens['cashier'],'GET',$route); check($status===403,'Ruta protegida para administrador: '.$route);
    }
    [$status,$all]=api($year.'&pagina=2',$tokens['admin'],'GET','ventas/reportes/exportar');
    check($status===200 && count($all['ventas'])===29,'Exportación incluye todas las páginas de ventas');
    [$status]=api($year,$tokens['cashier'],'GET','ventas/reportes/exportar'); check($status===403,'Exportación de Ventas también protegida');
    [$status]=pdf($year,null); check($status===401,'PDF requiere sesión');
    [$status]=pdf($year,$tokens['cashier']); check($status===403,'Cajero no descarga el PDF');
    [$status]=pdf($year,$tokens['admin'],'POST'); check($status===405,'PDF solo admite GET');
    [$status]=pdf('desde=2032-02-30',$tokens['admin']); check($status===422,'PDF rechaza filtros inválidos');
    foreach(['mes','semana'] as $group) {
        [$status,$type,$binary]=pdf($year.'&agrupacion='.$group,$tokens['admin']);
        check($status===200 && str_starts_with((string)$type,'application/pdf') && str_starts_with((string)$binary,'%PDF-'),'PDF real con agrupación '.$group);
        file_put_contents('/tmp/reporte-'.$group.'.pdf',$binary);
    }
    [$status,$type,$binary]=pdf('desde=2032-01-14&hasta=2032-02-02&agrupacion=dia',$tokens['admin']);
    check($status===200 && str_starts_with((string)$binary,'%PDF-'),'PDF diario válido');
    file_put_contents('/tmp/reporte-dia.pdf',$binary);
    [$status,$type,$binary]=pdf('desde=2032-04-01&hasta=2032-04-30',$tokens['admin']);
    check($status===200 && str_starts_with((string)$binary,'%PDF-'),'PDF válido para período sin ventas');
    file_put_contents('/tmp/reporte-vacio.pdf',$binary);
    $q=$db->prepare('SELECT COUNT(*) FROM ventas WHERE usuario_id=?'); $q->execute([$tokens['ids'][0]]);
    check((int)$q->fetchColumn()===30,'Reportes no crea ni elimina ventas al consultar o exportar');
    echo "Reportes: $checks comprobaciones correctas.\n";
} finally {
    $db->beginTransaction();
    foreach($ids as $id) {
        $q=$db->prepare('DELETE FROM detalle_ventas WHERE venta_id=?'); $q->execute([$id]);
        $q=$db->prepare('DELETE FROM ventas WHERE id=? AND usuario_id=?'); $q->execute([$id,$tokens['ids'][0]]);
    }
    $db->commit(); echo "Datos temporales de reportes retirados.\n";
}
