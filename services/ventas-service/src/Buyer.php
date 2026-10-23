<?php
declare(strict_types=1);
// Comprueba formato y campos completos; no consulta el registro de NIT de SAT.
final class Buyer
{
    public static function normalize(mixed $data): array
    {
        if ($data === null) return ['nombre'=>'CF','nit'=>null,'direccion'=>null];
        if (!is_object($data)) throw new HttpError(422, 'Datos del comprador inválidos');
        $fields=[];
        foreach (['nombre'=>150,'nit'=>20,'direccion'=>255] as $field=>$limit) {
            $value=$data->$field ?? '';
            if (!is_string($value) || mb_strlen(trim($value))>$limit || preg_match('/[\x00-\x1F\x7F]/u',$value)) throw new HttpError(422, 'Campo del comprador inválido: '.$field);
            $fields[$field]=trim($value);
        }
        if (in_array(strtoupper($fields['nombre']),['','CF','C/F'],true) && $fields['nit']==='' && $fields['direccion']==='') return ['nombre'=>'CF','nit'=>null,'direccion'=>null];
        if ($fields['nombre']==='' || in_array(strtoupper($fields['nombre']),['CF','C/F'],true) || $fields['direccion']==='') throw new HttpError(422, 'Ingresa NIT, nombre y dirección completos del comprador');
        $nit=strtoupper($fields['nit']);
        if (!preg_match('/^[0-9]{1,12}-?[0-9K]$/D',$nit)) throw new HttpError(422, 'NIT inválido. Usa números y el dígito final o K');
        $fields['nit']=str_replace('-','',$nit);
        return $fields;
    }
    // Compara centavos enteros, incluyendo Q 2,500.00 exactos en el caso obligatorio.
    public static function requireForTotal(array $buyer, string $total): void
    {
        if (Checkout::cents($total)>=250000 && $buyer['nit']===null) throw new HttpError(422, 'Desde Q 2,500.00 se requiere NIT, nombre y dirección del comprador');
    }
}
