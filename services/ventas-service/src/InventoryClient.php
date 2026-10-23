<?php
declare(strict_types=1);

// Ventas pide el descuento por HTTP; no tiene permisos sobre inventario_db.
final class InventoryClient
{
    public static function discount(string $reference, array $items, bool $identified = false): array
    {
        $base = getenv('INVENTARIO_URL'); $key = getenv('VENTAS_INTERNAL_KEY');
        if (!$base || !$key) { throw new HttpError(503, 'Falta configurar la comunicación con Inventario'); }
        $curl = curl_init(rtrim($base, '/') . '/ventas/descontar');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['referencia'=>$reference, 'productos'=>$items, 'comprador_identificado'=>$identified], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json',
                'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'], 'X-Ventas-Key: ' . $key],
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>false,
        ]);
        $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw === false || $status === 0) { throw new HttpError(503, 'No se confirmó Inventario. Reintenta la misma venta'); }
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError(503, 'No se confirmó Inventario. Reintenta la misma venta'); }
        if ($status !== 200) {
            // Solo errores de negocio confirmados permiten cambiar la compra; otros requieren reintentar.
            if (in_array($status, [401,404,409,422], true)) {
                throw new HttpError($status, $data['error'] ?? 'Inventario rechazó la compra');
            }
            throw new HttpError(503, 'Inventario no está disponible. Reintenta la misma venta');
        }
        if (($data['referencia'] ?? null) !== $reference || ($data['moneda'] ?? null) !== 'GTQ'
            || !is_array($data['productos'] ?? null) || count($data['productos']) !== count($items)
            || !is_string($data['total'] ?? null) || !preg_match('/^[0-9]{1,10}\.[0-9]{2}$/D', $data['total'])) {
            throw new HttpError(503, 'Respuesta inesperada de Inventario. Reintenta la misma venta');
        }
        return $data;
    }
    // Devuelve la compra original: Inventario obtiene las cantidades de su propio registro.
    public static function restore(string $reference): void
    {
        $base=getenv('INVENTARIO_URL'); $key=getenv('VENTAS_INTERNAL_KEY');
        if(!$base || !$key) throw new HttpError(503,'Comunicación de Inventario sin configurar');
        $curl=curl_init(rtrim($base,'/').'/ventas/devolver');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['referencia'=>$reference],JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: '.$_SERVER['HTTP_AUTHORIZATION'],'X-Ventas-Key: '.$key],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false]);
        $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if($raw===false || $status!==200) throw new HttpError(503,'La anulación está pendiente de devolver existencias. Reintenta desde el historial');
        try { $reply=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch(JsonException $error) { throw new HttpError(503,'No se confirmó la devolución. Reintenta la anulación'); }
        if(($reply['referencia'] ?? null)!==$reference || ($reply['devuelta'] ?? null)!==true) throw new HttpError(503,'No se confirmó la devolución. Reintenta la anulación');
    }
}
