<?php
declare(strict_types=1);

// Consulta ventas confirmadas. Cada cajero solo puede leer las suyas.
final class SalesHistory
{
    public static function listing(PDO $db, array $input, object $claims): array
    {
        [$dates,$page] = SalesReports::filters($input);
        $seller = null;
        if (isset($input['vendedor_id']) && $input['vendedor_id'] !== '') {
            $seller = Checkout::id($input['vendedor_id']);
        }
        // El filtro nunca permite que un cajero consulte ventas ajenas.
        if ($claims->role !== 'administrador') {
            if ($seller !== null && (string)$seller !== $claims->sub) throw new HttpError(403,'Solo puedes consultar tus propias ventas');
            $seller = (int)$claims->sub;
        }
        $utc = new DateTimeZone('UTC');
        $values = [$dates['desde']->setTimezone($utc)->format('Y-m-d H:i:s'),
            $dates['hasta']->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
        $where = 'total IS NOT NULL AND creado_en >= ? AND creado_en < ?';
        if ($seller !== null) { $where .= ' AND usuario_id=?'; $values[] = $seller; }
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT COUNT(*) AS ventas,COALESCE(SUM(CASE WHEN estado=\'confirmada\' THEN total ELSE 0 END),0.00) AS total FROM ventas WHERE '.$where);
            $q->execute($values); $summary = $q->fetch(); $summary['ventas'] = (int)$summary['ventas'];
            $pages = max(1,(int)ceil($summary['ventas']/25)); $page = min($page,$pages); $offset = ($page-1)*25;
            $q = $db->prepare("SELECT id,usuario_id,comprador_nombre,total,estado,CASE WHEN metodo_pago='efectivo' THEN 1 ELSE EXISTS(SELECT 1 FROM pagos p WHERE p.venta_id=ventas.id AND p.captura_externa IS NOT NULL) END AS facturable,DATE_FORMAT(DATE_SUB(creado_en,INTERVAL 6 HOUR),'%Y-%m-%d %H:%i') AS fecha FROM ventas WHERE $where ORDER BY creado_en DESC,id DESC LIMIT 25 OFFSET $offset");
            $q->execute($values); $sales = $q->fetchAll();
            $db->commit();
            return ['periodo'=>['desde'=>$dates['desde']->format('Y-m-d'),'hasta'=>$dates['hasta']->format('Y-m-d'),'zona'=>'America/Guatemala'],
                'vendedor_id'=>$seller===null ? null : (string)$seller,'resumen'=>$summary,'ventas'=>$sales,
                'paginacion'=>['pagina'=>$page,'paginas'=>$pages,'total'=>$summary['ventas'],'limite'=>25]];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}

