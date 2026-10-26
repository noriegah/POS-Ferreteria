<?php
declare(strict_types=1);

// La firma autoriza una consulta limitada; nunca da acceso a los datos del comprador.
final class InvoiceValidation
{
    private static function signature(string $reference): string
    {
        $key=getenv('VENTAS_INTERNAL_KEY');
        if(!$key) throw new HttpError(503,'Validación de facturas sin configurar');
        return hash_hmac('sha256','invoice-validation:v1:'.$reference,$key);
    }
    public static function url(string $reference): string
    {
        $base=rtrim(getenv('PUBLIC_APP_URL') ?: 'http://localhost:8080','/');
        $parts=parse_url($base);
        if(!is_array($parts) || !in_array($parts['scheme'] ?? '',['http','https'],true) || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) throw new HttpError(503,'URL pública de facturas inválida');
        return $base.'/validar-factura.html?'.http_build_query(['referencia'=>$reference,'firma'=>self::signature($reference)],'', '&',PHP_QUERY_RFC3986);
    }
    public static function authorized(mixed $reference,mixed $signature): bool
    {
        return is_string($reference) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$reference)
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D',$signature)
            && hash_equals(self::signature($reference),$signature);
    }
    public static function lookup(PDO $db,string $reference): array
    {
        $q=$db->prepare("SELECT v.id,v.estado,v.total,v.moneda,v.creado_en FROM ventas v WHERE v.referencia=? AND v.total IS NOT NULL AND v.estado IN ('confirmada','anulando','anulada') AND (v.metodo_pago='efectivo' OR EXISTS (SELECT 1 FROM pagos p WHERE p.venta_id=v.id AND p.captura_externa IS NOT NULL)) LIMIT 1");
        $q->execute([$reference]);$sale=$q->fetch();
        if(!$sale) throw new HttpError(404,'No se encontró una factura emitida para esta referencia');
        $issuer=require __DIR__.'/../config/invoice.php';
        return ['valida'=>$sale['estado']==='confirmada','estado'=>$sale['estado'],
            'numero'=>'FAC-'.str_pad((string)$sale['id'],6,'0',STR_PAD_LEFT),
            'fecha'=>(new DateTimeImmutable($sale['creado_en'],new DateTimeZone('UTC')))->format('c'),
            'total'=>$sale['total'],'moneda'=>$sale['moneda'],'emisor'=>$issuer];
    }
}
