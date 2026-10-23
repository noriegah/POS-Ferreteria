<?php
declare(strict_types=1);

// Cliente REST exclusivo de Sandbox. Nunca recibe números de tarjeta ni usa la API de producción.
final class PayPalClient
{
    private ?string $token=null;
    // El transporte alternativo se usa solo en pruebas PHP; ninguna ruta HTTP lo acepta.
    public function __construct(private ?Closure $transport=null) {}

    public static function rate(): string
    {
        $rate=getenv('PAYPAL_USD_RATE') ?: '7.80';
        if(!preg_match('/^[1-9][0-9]{0,2}(\.[0-9]{1,4})?$/D',$rate)) throw new HttpError(503,'Revisa la tasa de demostración de PayPal');
        return $rate;
    }

    public static function ready(): bool
    {
        return (bool)getenv('PAYPAL_CLIENT_ID') && (bool)getenv('PAYPAL_CLIENT_SECRET');
    }

    public static function requireConfiguration(): void
    {
        if(!self::ready()) throw new HttpError(503,'Faltan las credenciales propias de PayPal Sandbox');
        self::rate(); self::returnUrl();
    }

    private static function returnUrl(): string
    {
        $url=getenv('PAYPAL_RETURN_URL') ?: 'http://localhost:8080/pago-retorno.html';
        $parts=parse_url($url);
        if(!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])
            || !(($parts['scheme'] ?? '')==='https' || (($parts['scheme'] ?? '')==='http' && in_array($parts['host'],['localhost','127.0.0.1'],true)))) {
            throw new HttpError(503,'Revisa la URL de retorno de PayPal');
        }
        return $url;
    }

    // Convierte con enteros y cuatro decimales de tasa; no consulta ni afirma una tasa bancaria real.
    public static function dollars(string $quetzales,string $rate): string
    {
        [$whole,$fraction]=array_pad(explode('.',$rate,2),2,'');
        $scaled=(int)$whole*10000+(int)str_pad($fraction,4,'0');
        $cents=intdiv(Checkout::cents($quetzales)*10000+intdiv($scaled,2),$scaled);
        if($cents<1 || $cents>999999999999) throw new HttpError(422,'El importe convertido no es válido para un pago de prueba');
        return Checkout::money($cents);
    }

    private function send(string $method,string $path,mixed $body,array $headers,?string $credentials=null): array
    {
        if($this->transport) return ($this->transport)($method,$path,$body,$headers,$credentials);
        $curl=curl_init('https://api-m.sandbox.paypal.com'.$path); $raw='';
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$raw): int {
                if(strlen($raw)+strlen($chunk)>262144) return 0; $raw.=$chunk; return strlen($chunk);
            }];
        if($body!==null) $options[CURLOPT_POSTFIELDS]=is_string($body) ? $body : json_encode($body,JSON_THROW_ON_ERROR);
        if($credentials!==null) { $options[CURLOPT_USERPWD]=$credentials; $options[CURLOPT_HTTPAUTH]=CURLAUTH_BASIC; }
        curl_setopt_array($curl,$options); $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if($ok===false || $status===0) throw new HttpError(503,'No se confirmó PayPal Sandbox. Reintenta la misma operación');
        try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch(JsonException $error) { throw new HttpError(503,'Respuesta inválida de PayPal Sandbox'); }
        if(!is_array($data)) throw new HttpError(503,'Respuesta inválida de PayPal Sandbox');
        return [$status,$data];
    }

    // Verifica credenciales sin crear órdenes ni mover dinero de prueba.
    public function authenticate(): void
    {
        self::requireConfiguration();
        if($this->token!==null) return;
        [$status,$data]=$this->send('POST','/v1/oauth2/token','grant_type=client_credentials',
            ['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],getenv('PAYPAL_CLIENT_ID').':'.getenv('PAYPAL_CLIENT_SECRET'));
        if($status!==200 || !is_string($data['access_token'] ?? null)) throw new HttpError(503,'No se pudo autenticar con PayPal Sandbox');
        $this->token=$data['access_token'];
    }

    private function request(string $method,string $path,?array $body=null,?string $reference=null): array
    {
        $this->authenticate();
        $headers=['Content-Type: application/json','Accept: application/json','Prefer: return=representation','Authorization: Bearer '.$this->token];
        if($reference!==null) $headers[]='PayPal-Request-Id: '.substr(hash('sha256',$reference),0,32);
        [$status,$data]=$this->send($method,$path,$body===[] ? new stdClass() : $body,$headers);
        if(!in_array($status,[200,201],true)) {
            if(($data['details'][0]['issue'] ?? '')==='PAYER_ACTION_REQUIRED' || ($data['details'][0]['issue'] ?? '')==='ORDER_NOT_APPROVED') throw new HttpError(409,'Primero autoriza el pago en PayPal Sandbox');
            throw new HttpError(503,'PayPal no confirmó la operación. Revisa la cuenta Sandbox y reintenta');
        }
        return $data;
    }

    private static function identifier(string $id): string
    {
        if(!preg_match('/^[A-Za-z0-9]{1,100}$/D',$id)) throw new HttpError(503,'Identificador inesperado de PayPal');
        return $id;
    }

    public function create(array $sale,array $payment): array
    {
        return $this->request('POST','/v2/checkout/orders',[
            'intent'=>'CAPTURE','purchase_units'=>[['custom_id'=>$sale['referencia'],'invoice_id'=>'FS-'.$sale['referencia'],
                'amount'=>['currency_code'=>'USD','value'=>$payment['importe_proveedor']]]],
            'payment_source'=>['paypal'=>['experience_context'=>['brand_name'=>'FerreSync','shipping_preference'=>'NO_SHIPPING',
                'user_action'=>'PAY_NOW','return_url'=>self::returnUrl(),'cancel_url'=>self::returnUrl()]]],
        ],'order:'.$sale['referencia']);
    }

    public function order(string $id): array {return $this->request('GET','/v2/checkout/orders/'.self::identifier($id));}
    public function capture(string $id,string $reference): array {return $this->request('POST','/v2/checkout/orders/'.self::identifier($id).'/capture',[],'capture:'.$reference);}
    public function refund(string $capture,string $reference): array {return $this->request('POST','/v2/payments/captures/'.self::identifier($capture).'/refund',[],'refund:'.$reference);}
    public function refundStatus(string $id): array {return $this->request('GET','/v2/payments/refunds/'.self::identifier($id));}

    public static function approval(array $order): string
    {
        foreach($order['links'] ?? [] as $link) {
            if(in_array($link['rel'] ?? '',['payer-action','approve'],true)) {
                $url=$link['href'] ?? ''; $parts=is_string($url) ? parse_url($url) : false;
                if(is_array($parts) && ($parts['scheme'] ?? '')==='https' && ($parts['host'] ?? '')==='www.sandbox.paypal.com'
                    && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])) return $url;
            }
        }
        throw new HttpError(503,'PayPal no entregó un enlace Sandbox válido');
    }

    // Un retorno del navegador no prueba el pago: se revisan orden, referencia, moneda e importe en el servidor.
    public static function validate(array $order,array $sale,array $payment): ?array
    {
        $units=$order['purchase_units'] ?? [];
        if(($order['id'] ?? null)!==$payment['orden_externa'] || count($units)!==1
            || ($units[0]['custom_id'] ?? null)!==$sale['referencia'] || ($units[0]['amount']['currency_code'] ?? null)!=='USD'
            || ($units[0]['amount']['value'] ?? null)!==$payment['importe_proveedor']) throw new HttpError(503,'El pago recibido no corresponde a esta venta');
        $captures=$units[0]['payments']['captures'] ?? [];
        if(!$captures) return null;
        if(count($captures)!==1 || !is_string($captures[0]['id'] ?? null)
            || ($captures[0]['amount']['currency_code'] ?? null)!=='USD'
            || ($captures[0]['amount']['value'] ?? null)!==$payment['importe_proveedor']) throw new HttpError(503,'El importe capturado no coincide con la venta');
        self::identifier($captures[0]['id']);
        return $captures[0];
    }
}
