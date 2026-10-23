<?php
declare(strict_types=1);

// CRUD pequeño con PDO. Las rutas y los permisos se manejan en index.php.
final class Products
{
// Recibe la conexión PDO para trabajar solo con la base de Inventario.
    public function __construct(private PDO $db) {}

// Lee el cuerpo JSON y rechaza formatos incorrectos o solicitudes demasiado grandes.
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

// Acepta únicamente un identificador entero mayor que cero.
    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
            throw new HttpError(422, 'Identificador inválido');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) { throw new HttpError(422, 'Identificador inválido'); }
        return $id;
    }

// Quita espacios de los extremos y comprueba que el texto no esté vacío ni exceda el límite en bytes.
    private static function text(mixed $value, int $max, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpError(422, "Campo inválido: $field");
        }
        return trim($value);
    }

// Acepta números no negativos con los decimales permitidos; los conserva como texto para enviarlos a MariaDB.
    private static function decimal(mixed $value, int $integers, int $decimals, string $field): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^(0|[1-9][0-9]{0,' . ($integers-1) . '})(\.[0-9]{1,' . $decimals . '})?$/D', (string)$value)) {
            throw new HttpError(422, "Campo inválido: $field (decimal no negativo)");
        }
        return (string)$value;
    }

// Lista las categorías activas ordenadas por nombre.
    public function categories(): array
    {
        return $this->db->query('SELECT id,nombre FROM categorias WHERE activo=1 ORDER BY nombre')->fetchAll();
    }

// Valida el nombre y crea una categoría mediante una consulta preparada.
    public function createCategory(object $data): array
    {
        $name = self::text($data->nombre ?? null, 100, 'nombre');
        $query = $this->db->prepare('INSERT INTO categorias(nombre) VALUES (?)');
        $query->execute([$name]);
        return ['id' => (string)$this->db->lastInsertId(), 'nombre' => $name];
    }

    // Valida números de la consulta: página, tamaño de página y categoría.
    private static function parameter(mixed $value, int $maximum): int
    {
        $number = self::id($value);
        if ($number > $maximum) { throw new HttpError(422, 'Parámetro fuera de rango'); }
        return $number;
    }

    // Busca por nombre/código y filtra por categoría o stock bajo; devuelve una página.
    public function all(array $filters = []): array
    {
        $page = self::parameter($filters['pagina'] ?? '1', 1000000);
        $limit = self::parameter($filters['limite'] ?? '10', 50);
        $where = 'p.activo=1'; $values = [];
        $search = $filters['buscar'] ?? '';
        if (!is_string($search) || strlen($search) > 150) { throw new HttpError(422, 'Búsqueda inválida'); }
        if (trim($search) !== '') {
            // Escapa los comodines para que % y _ se busquen como caracteres normales.
            $search = '%' . str_replace(['=','%','_'], ['==','=%','=_'], trim($search)) . '%';
            $where .= " AND (p.codigo LIKE ? ESCAPE '=' OR p.nombre LIKE ? ESCAPE '=')";
            $values[] = $search; $values[] = $search;
        }
        if (isset($filters['categoria_id']) && $filters['categoria_id'] !== '') {
            $where .= ' AND p.categoria_id=?';
            $values[] = self::parameter($filters['categoria_id'], PHP_INT_MAX);
        }
        $low = $filters['stock_bajo'] ?? '0';
        if (!in_array($low, ['0','1'], true)) { throw new HttpError(422, 'Filtro de stock inválido'); }
        if ($low === '1') { $where .= ' AND p.stock<5'; }
        $query = $this->db->prepare('SELECT COUNT(*) FROM productos p WHERE ' . $where);
        $query->execute($values); $total = (int)$query->fetchColumn();
        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages); $offset = ($page-1) * $limit;
        // LIMIT y OFFSET son enteros ya validados; los filtros se envían como parámetros.
        $query = $this->db->prepare('SELECT p.*,c.nombre AS categoria FROM productos p JOIN categorias c ON c.id=p.categoria_id WHERE ' . $where . " ORDER BY p.id DESC LIMIT $limit OFFSET $offset");
        $query->execute($values);
        return ['productos'=>$query->fetchAll(), 'paginacion'=>['pagina'=>$page,'limite'=>$limit,'total'=>$total,'paginas'=>$pages]];
    }

    // La guía solicita alertas con menos de 5 unidades, independientemente del stock mínimo.
    public function alerts(): array
    {
        return $this->db->query('SELECT id,codigo,nombre,stock FROM productos WHERE activo=1 AND stock<5 ORDER BY stock,id')->fetchAll();
    }

// Busca un producto activo; FOR UPDATE lo bloquea durante una edición para evitar cambios simultáneos.
    public function find(int $id, bool $lock = false): array
    {
        $query = $this->db->prepare('SELECT * FROM productos WHERE id=? AND activo=1' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]); $product = $query->fetch();
        if (!$product) { throw new HttpError(404, 'Producto no encontrado'); }
        return $product;
    }

// Crea si no hay id; si lo hay, edita. Primero valida los campos y después guarda todo en una transacción.
    public function save(object $data, string $userId, ?int $id = null): array
    {
        $category = self::id($data->categoria_id ?? null);
        // Un código vacío genera uno interno al crear; al editar conserva el que ya tenía.
        $rawCode = $data->codigo ?? '';
        if (!is_string($rawCode)) { throw new HttpError(422, 'Código inválido'); }
        $code = trim($rawCode) === '' ? null : self::text($rawCode, 64, 'codigo');
        $name = self::text($data->nombre ?? null, 150, 'nombre');
        $description = $data->descripcion ?? null;
        if ($description !== null && (!is_string($description) || strlen($description) > 4000)) {
            throw new HttpError(422, 'Descripción inválida (máximo 4000 bytes)');
        }
        $price = self::decimal($data->precio ?? null, 10, 2, 'precio');
        // Las existencias se manejan por unidades completas, sin redondear entradas fraccionadas.
        $stock = self::decimal($data->stock ?? null, 11, 3, 'stock');
        $minimum = self::decimal($data->stock_minimo ?? null, 11, 3, 'stock_minimo');
        if (floor((float)$stock) !== (float)$stock || floor((float)$minimum) !== (float)$minimum) {
            throw new HttpError(422, 'Las existencias y el stock mínimo deben ser números enteros');
        }
// Si falla el producto o su movimiento de stock, se deshacen ambos cambios.
        $this->db->beginTransaction();
        try {
            $existing = $id === null ? null : $this->find($id, true);
            if ($code !== null && preg_match('/^PR-[0-9]+$/iD', $code) && $code !== ($existing['codigo'] ?? null)) {
                throw new HttpError(422, 'El prefijo PR- con números está reservado a los códigos automáticos');
            }
            $previous = $existing['stock'] ?? '0.000';
            $automatic = $id === null && $code === null;
            // El código provisional nunca se publica: se reemplaza antes de confirmar la transacción.
            $code ??= $existing['codigo'] ?? ('AUTO-' . bin2hex(random_bytes(16)));
            $query = $this->db->prepare('SELECT id FROM categorias WHERE id=? AND activo=1');
            $query->execute([$category]);
            if (!$query->fetch()) { throw new HttpError(422, 'La categoría no existe o está inactiva'); }
// Los signos ? del SQL reciben estos valores por separado, sin concatenar datos del usuario a la consulta.
            $values = [$category,$code,$name,$description,$price,$stock,$minimum];
            if ($id === null) {
                $query = $this->db->prepare('INSERT INTO productos(categoria_id,codigo,nombre,descripcion,precio,stock,stock_minimo) VALUES (?,?,?,?,?,?,?)');
                $query->execute($values); $id = (int)$this->db->lastInsertId();
                if ($automatic) {
                    $code = 'PR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                    $query = $this->db->prepare('UPDATE productos SET codigo=? WHERE id=?');
                    $query->execute([$code, $id]);
                }
                $reason = 'Stock inicial';
            } else {
                $query = $this->db->prepare('UPDATE productos SET categoria_id=?,codigo=?,nombre=?,descripcion=?,precio=?,stock=?,stock_minimo=? WHERE id=?');
                $query->execute([...$values,$id]); $reason = 'Edición de producto';
            }
            // MariaDB calcula la diferencia decimal; producto y movimiento se guardan juntos.
            $query = $this->db->prepare('INSERT INTO movimientos_stock(producto_id,usuario_id,cantidad,stock_anterior,stock_nuevo,motivo) SELECT ?,?,CAST(? AS DECIMAL(14,3))-CAST(? AS DECIMAL(14,3)),?,?,? WHERE CAST(? AS DECIMAL(14,3))<>CAST(? AS DECIMAL(14,3))');
            $query->execute([$id,$userId,$stock,$previous,$previous,$stock,$reason,$stock,$previous]);
            $product = $this->find($id);
// Confirma los cambios solo después de guardar correctamente el producto y su movimiento.
            $this->db->commit();
            return $product;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

// Marca activo=0; no elimina el producto ni su historial de movimientos.
    public function deactivate(int $id): void
    {
        $query = $this->db->prepare('UPDATE productos SET activo=0 WHERE id=? AND activo=1');
        $query->execute([$id]);
        if ($query->rowCount() === 0) { throw new HttpError(404, 'Producto no encontrado'); }
    }
}
