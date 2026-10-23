<?php
declare(strict_types=1);
// Usa fechas y cuentas temporales; nunca modifica ventas reales ni inventario.
require '/app/src/Database.php';
$tokens=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$db=Database::connect(); $ids=[]; $checks=0;
function check(bool $value,string $label): void {
    global $checks; if(!$value) throw new RuntimeException('FAIL: '.$label);
    $checks++; echo "PASS: $label\n";
}
function request(string $query,?string $token,string $method='GET'): array {
    $curl=curl_init('http://gateway/api/ventas/?'.$query);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>$token ? ['Authorization: Bearer '.$token] : []]);
    $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if($raw===false) throw new RuntimeException('HTTP request failed');
    return [$status,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];
}
function sale(string $seller,string $date,?string $total): void {
    global $db,$ids;
    $ref=bin2hex(random_bytes(16));
    $q=$db->prepare('INSERT INTO ventas(referencia,usuario_id,solicitud_hash,total,creado_en) VALUES (?,?,?,?,?)');
    $q->execute([$ref,$seller,hash('sha256',$ref),$total,$date]); $ids[]=$db->lastInsertId();
}
try {
    $q=$db->query("SELECT COUNT(*) FROM ventas WHERE creado_en >= '2032-01-01' AND creado_en < '2033-01-01'");
    if((int)$q->fetchColumn()!==0) throw new RuntimeException('El año 2032 debe estar vacío para la prueba');
    [$admin,$cashier,$other]=$tokens['ids'];
    sale($cashier,'2032-02-01 05:59:59','100.00');
    sale($other,'2032-02-01 06:00:00','75.00');
    sale($admin,'2032-03-01 06:00:00','0.00');
    sale($cashier,'2032-01-15 12:00:00',null);
    for($i=0;$i<26;$i++) sale($cashier,'2032-01-15 12:00:00','1.00');
    $year='desde=2032-01-01&hasta=2032-12-31';
    [$status]=request($year,null); check($status===401,'Historial requiere sesión');
    [$status]=request($year,'invalid'); check($status===401,'JWT incorrecto rechazado');
    [$status,$all]=request($year,$tokens['admin']);
    check($status===200 && $all['resumen']['ventas']===29 && $all['resumen']['total']==='201.00','Administrador ve ventas confirmadas de todos');
    check(count($all['ventas'])===25 && $all['paginacion']['paginas']===2,'Historial paginado en 25 filas');
    [$status,$next]=request($year.'&pagina=2',$tokens['admin']);
    check($status===200 && count($next['ventas'])===4,'Segunda página incluye el resto');
    check(count(array_intersect(array_column($all['ventas'],'id'),array_column($next['ventas'],'id')))===0,'Páginas sin ventas repetidas');
    [$status,$own]=request($year,$tokens['cashier']);
    check($status===200 && $own['resumen']['ventas']===27 && $own['resumen']['total']==='126.00','Cajero solo ve sus ventas y su total');
    check(count(array_filter($own['ventas'],static fn($row)=>(string)$row['usuario_id']!==(string)$cashier))===0,'No se filtran filas ajenas al cajero');
    [$status,$second]=request($year,$tokens['other']);
    check($status===200 && $second['resumen']['ventas']===1 && $second['resumen']['total']==='75.00','Otro cajero recibe únicamente su propia venta');
    [$status]=request($year.'&vendedor_id='.$other,$tokens['cashier']); check($status===403,'Cajero no filtra por otro vendedor');
    [$status]=request($year.'&vendedor_id='.$admin,$tokens['cashier']); check($status===403,'Cajero no consulta ventas del administrador');
    [$status,$ownFilter]=request($year.'&vendedor_id='.$cashier,$tokens['cashier']);
    check($status===200 && $ownFilter['resumen']===$own['resumen'],'Cajero puede filtrar su propio id');
    [$status,$filtered]=request($year.'&vendedor_id='.$other,$tokens['admin']);
    check($status===200 && $filtered['resumen']['ventas']===1 && (string)$filtered['ventas'][0]['usuario_id']===(string)$other,'Administrador filtra por vendedor');
    [$status,$zero]=request($year.'&vendedor_id='.$admin,$tokens['admin']);
    check($status===200 && $zero['resumen']['ventas']===1 && $zero['resumen']['total']==='0.00','Ventas de total cero y vendedor administrador se incluyen');
    [$status,$day]=request('desde=2032-01-31&hasta=2032-01-31',$tokens['admin']);
    check($status===200 && $day['resumen']['total']==='100.00','Fecha final incluye último segundo en Guatemala');
    [$status,$day]=request('desde=2032-02-01&hasta=2032-02-01',$tokens['admin']);
    check($status===200 && $day['resumen']['total']==='75.00','Fecha inicial respeta medianoche local');
    [$status,$empty]=request('desde=2032-04-01&hasta=2032-04-30',$tokens['admin']);
    check($status===200 && $empty['ventas']===[] && $empty['resumen']['total']==='0.00','Período vacío válido');
    [$status,$last]=request($year.'&pagina=999999',$tokens['admin']);
    check($status===200 && $last['paginacion']['pagina']===2,'Página fuera del total se ajusta a la última');
    foreach(['desde=2032-02-30','desde[]=2032-01-01','desde=2032-04-02&hasta=2032-04-01','desde=2031-01-01&hasta=2032-12-31','pagina=0','pagina[]=1','vendedor_id=0','vendedor_id[]=1','vendedor_id=1%20OR%201=1'] as $bad) {
        [$status]=request($bad,$tokens['admin']); check($status===422,'Filtro inválido rechazado: '.$bad);
    }
    [$status]=request($year,$tokens['admin'],'DELETE'); check($status===405,'No se permite borrar ventas desde el historial');
    $q=$db->prepare('SELECT COUNT(*) FROM ventas WHERE usuario_id IN (?,?,?)'); $q->execute($tokens['ids']);
    check((int)$q->fetchColumn()===30,'Consultar no crea, borra ni confirma ventas pendientes');
    echo "Historial: $checks comprobaciones correctas.\n";
} finally {
    $db->beginTransaction();
    foreach($ids as $id) {$q=$db->prepare('DELETE FROM ventas WHERE id=? AND usuario_id IN (?,?,?)'); $q->execute([$id,...$tokens['ids']]);}
    $db->commit(); echo "Ventas temporales del historial retiradas.\n";
}
