<?php
declare(strict_types=1);

// Valida los datos compartidos por la caja y el descuento de Inventario.
final class Checkout
{
    public static function validate(object $data): array
    {
        $reference = $data->referencia ?? null;
        if (!is_string($reference) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $reference)) {
            throw new HttpError(422, 'Referencia de venta inválida');
        }
        $items = $data->productos ?? null;
        if (!is_array($items) || count($items) < 1 || count($items) > 50) {
            throw new HttpError(422, 'Agrega entre 1 y 50 productos');
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_object($item)) { throw new HttpError(422, 'Producto inválido'); }
            $id = self::id($item->producto_id ?? null);
            $quantity = self::id($item->cantidad ?? null);
            if ($quantity > 999999 || isset($normalized[$id])) {
                throw new HttpError(422, 'Cantidad fuera de rango o producto repetido');
            }
            $normalized[$id] = ['producto_id'=>$id, 'cantidad'=>$quantity];
        }
        // Orden fijo: la misma compra tiene el mismo hash y bloquea productos en el mismo orden.
        ksort($normalized, SORT_NUMERIC);
        return [$reference, array_values($normalized)];
    }

    public static function hash(string $userId, array $items): string
    {
        return hash('sha256', json_encode([$userId, $items], JSON_THROW_ON_ERROR));
    }

    // Identificadores y unidades son enteros positivos; no se redondean fracciones.
    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
            throw new HttpError(422, 'Se requiere un número entero mayor que cero');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) { throw new HttpError(422, 'Número fuera de rango'); }
        return $number;
    }

    // Limita el tamaño del carrito y acepta únicamente un objeto JSON.
    public static function body(): object
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new HttpError(415, 'Se requiere application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw === false || strlen($raw) > 16384) { throw new HttpError(413, 'Solicitud demasiado grande'); }
        try { $data = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError(400, 'JSON inválido'); }
        if (!is_object($data)) { throw new HttpError(422, 'Se requiere un objeto JSON'); }
        return $data;
    }

    // Calcula dinero con centavos enteros, sin acumular errores de punto flotante.
    public static function cents(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
