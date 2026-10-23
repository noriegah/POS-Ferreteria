<?php
declare(strict_types=1);

// Ventas calcula los reportes con su propia base. Reportes los solicita por HTTP.
final class SalesReports
{
    public static function filters(array $input): array
    {
        $zone = new DateTimeZone('America/Guatemala');
        $year = (new DateTimeImmutable('now', $zone))->format('Y');
        $dates = [];
        foreach (['desde'=>$year.'-01-01', 'hasta'=>$year.'-12-31'] as $key=>$default) {
            $value = $input[$key] ?? $default;
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new HttpError(422, 'Las fechas deben tener formato AAAA-MM-DD');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            if (!$date || $date->format('Y-m-d') !== $value || (int)$date->format('Y') < 2000 || (int)$date->format('Y') > 2100) {
                throw new HttpError(422, 'Fecha inválida; utiliza años entre 2000 y 2100');
            }
            $dates[$key] = $date;
        }
        if ($dates['hasta'] < $dates['desde'] || (int)$dates['desde']->diff($dates['hasta'])->days > 365) {
            throw new HttpError(422, 'Selecciona un período de hasta 366 días, con inicio anterior al fin');
        }
        $page = $input['pagina'] ?? '1';
        if (!is_scalar($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string)$page)) {
            throw new HttpError(422, 'Página inválida');
        }
        $group = $input['agrupacion'] ?? 'mes';
        if (!is_string($group) || !in_array($group, ['dia','semana','mes'], true)) {
            throw new HttpError(422, 'Agrupación inválida: utiliza dia, semana o mes');
        }
        return [$dates, (int)$page, $group];
    }

    public static function summary(PDO $db, array $input, bool $export = false): array
    {
        [$dates, $page, $group] = self::filters($input);
        // El usuario filtra días de Guatemala; MariaDB conserva las fechas en UTC.
        $utc = new DateTimeZone('UTC');
        $values = [$dates['desde']->setTimezone($utc)->format('Y-m-d H:i:s'),
            $dates['hasta']->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
        $where = 'v.total IS NOT NULL AND v.estado=\'confirmada\' AND v.creado_en >= ? AND v.creado_en < ?';
        $read = static function(string $sql) use ($db, $values): array {
            $q = $db->prepare($sql); $q->execute($values); return $q->fetchAll();
        };
        // Una sola vista de los datos mantiene coherentes tarjetas, gráficos y listado.
        $db->beginTransaction();
        try {
            $summary = $read("SELECT COUNT(*) AS ventas, COALESCE(SUM(v.total),0.00) AS total, COALESCE(ROUND(AVG(v.total),2),0.00) AS promedio FROM ventas v WHERE $where")[0];
            $summary['ventas'] = (int)$summary['ventas'];
            // Evita generar un archivo enorme: solicita un rango menor, sin truncar ventas.
            if ($export && $summary['ventas'] > 5000) {
                throw new HttpError(422, 'Para exportar, selecciona un período con hasta 5,000 ventas');
            }
            $summary['unidades'] = (int)$read("SELECT COALESCE(SUM(d.cantidad),0) AS unidades FROM detalle_ventas d JOIN ventas v ON v.id=d.venta_id WHERE $where")[0]['unidades'];
            $monthly = $read("SELECT DATE_FORMAT(DATE_SUB(v.creado_en, INTERVAL 6 HOUR),'%Y-%m') AS mes, COUNT(*) AS ventas, SUM(v.total) AS total FROM ventas v WHERE $where GROUP BY mes ORDER BY mes");
            $byMonth = array_column($monthly, null, 'mes'); $months = [];
            for ($month = $dates['desde']->modify('first day of this month'); $month <= $dates['hasta']; $month = $month->modify('+1 month')) {
                $key = $month->format('Y-m');
                $row = $byMonth[$key] ?? ['mes'=>$key, 'ventas'=>0, 'total'=>'0.00'];
                $row['ventas'] = (int)$row['ventas']; $months[] = $row;
            }
            $series = self::series($dates, $group, $read, $where, $byMonth);
            $categories = $read("SELECT COALESCE(d.categoria_nombre,'Sin categoría registrada') AS nombre, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS total FROM detalle_ventas d JOIN ventas v ON v.id=d.venta_id WHERE $where GROUP BY COALESCE(d.categoria_nombre,'Sin categoría registrada') ORDER BY unidades DESC,total DESC,nombre LIMIT 8");
            // Agrupa por el identificador; muestra el último nombre y código guardados en el período.
            $products = $read("SELECT a.producto_id,d.nombre,d.codigo,a.unidades,a.total FROM (SELECT d.producto_id, MAX(d.id) AS ultimo, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS total FROM detalle_ventas d JOIN ventas v ON v.id=d.venta_id WHERE $where GROUP BY d.producto_id) a JOIN detalle_ventas d ON d.id=a.ultimo ORDER BY a.unidades DESC,a.total DESC,a.producto_id LIMIT 8");
            $pages = max(1, (int)ceil($summary['ventas']/25)); $page = min($page, $pages); $offset = ($page-1)*25;
            $limit = $export ? '' : " LIMIT 25 OFFSET $offset";
            $sales = $read("SELECT v.id,v.comprador_nombre,v.total,DATE_FORMAT(DATE_SUB(v.creado_en, INTERVAL 6 HOUR),'%Y-%m-%d %H:%i') AS fecha FROM ventas v WHERE $where ORDER BY v.creado_en DESC,v.id DESC".$limit);
            foreach ($categories as &$row) $row['unidades'] = (int)$row['unidades'];
            unset($row);
            foreach ($products as &$row) $row['unidades'] = (int)$row['unidades'];
            unset($row);
            $db->commit();
            return ['periodo'=>['desde'=>$dates['desde']->format('Y-m-d'),'hasta'=>$dates['hasta']->format('Y-m-d'),'zona'=>'America/Guatemala'],
                'resumen'=>$summary,'meses'=>$months,'agrupacion'=>$group,'serie'=>$series,'categorias'=>$categories,'productos'=>$products,'ventas'=>$sales,
                'paginacion'=>['pagina'=>$page,'paginas'=>$pages,'total'=>$summary['ventas'],'limite'=>25]];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    // Completa con cero los días, semanas o meses que no tienen ventas.
    private static function series(array $dates, string $group, Closure $read, string $where, array $byMonth): array
    {
        $local = 'DATE_SUB(v.creado_en, INTERVAL 6 HOUR)';
        $cursor = $dates['desde']; $step = '+1 day'; $format = 'Y-m-d';
        if ($group === 'mes') {
            $cursor = $cursor->modify('first day of this month'); $step = '+1 month'; $format = 'Y-m';
            $map = $byMonth;
        } else {
            $expression = "DATE($local)";
            if ($group === 'semana') {
                // Las semanas comienzan el lunes, también cuando cambian de año.
                $expression = "DATE_SUB(DATE($local), INTERVAL WEEKDAY($local) DAY)";
                $cursor = $cursor->modify('-'.((int)$cursor->format('N')-1).' days'); $step = '+1 week';
            }
            $rows = $read("SELECT DATE_FORMAT($expression,'%Y-%m-%d') AS periodo,COUNT(*) AS ventas,SUM(v.total) AS total FROM ventas v WHERE $where GROUP BY periodo ORDER BY periodo");
            $map = array_column($rows, null, 'periodo');
        }
        $result = [];
        for (; $cursor <= $dates['hasta']; $cursor = $cursor->modify($step)) {
            $key = $cursor->format($format);
            $end = $cursor->modify($step)->modify('-1 day');
            $start = max($cursor, $dates['desde']); $end = min($end, $dates['hasta']);
            $label = $start->format('d/m/Y');
            if ($group === 'mes') $label = $cursor->format('m/Y');
            if ($group === 'semana') $label .= ' - '.$end->format('d/m/Y');
            $result[] = ['periodo'=>$key,'etiqueta'=>$label,'desde'=>$start->format('Y-m-d'),'hasta'=>$end->format('Y-m-d'),
                'ventas'=>(int)($map[$key]['ventas'] ?? 0),'total'=>$map[$key]['total'] ?? '0.00'];
        }
        return $result;
    }
}

