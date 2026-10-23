<?php
// Comprueba conexión, permisos SQL y restricciones. Los registros se revierten con rollback.
declare(strict_types=1);
require '/app/src/Database.php';
// Muestra PASS si la comprobación se cumple; si falla, detiene la prueba con un error.
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
// Comprueba que MariaDB rechace consultas que violan permisos o restricciones.
function denied(PDO $db, string $sql, array $codes): void {
    try { $db->exec($sql); } catch (PDOException $e) { check(in_array((int)$e->errorInfo[1], $codes, true), 'Expected SQL rejection'); return; }
    throw new RuntimeException('SQL unexpectedly accepted');
}
$db = Database::connect();
$auth = getenv('DB_NAME') === 'auth_db';
check($db->query('SELECT DATABASE()')->fetchColumn() === getenv('DB_NAME'), 'Own database connection');
$other = $auth ? 'inventario_db.categorias' : 'auth_db.usuarios';
denied($db, 'SELECT * FROM ' . $other, [1142, 1044]);
denied($db, 'CREATE TABLE persistence_should_not_exist (id INT)', [1142, 1044]);
$tag = 'test_' . bin2hex(random_bytes(8));
// Agrupa estas consultas en una transacción para poder confirmarlas o revertirlas juntas.
$db->beginTransaction();
try {
    if ($auth) {
        $query = $db->prepare('INSERT INTO usuarios(nombre,email,password_hash) VALUES (?,?,?)');
        $query->execute([$tag, $tag . '@example.invalid', password_hash('test-only', PASSWORD_BCRYPT)]);
        $id = $db->lastInsertId();
        $query = $db->prepare('SELECT rol,password_hash FROM usuarios WHERE id=?'); $query->execute([$id]); $row = $query->fetch();
        check($row['rol'] === 'cajero' && password_verify('test-only', $row['password_hash']), 'User persistence and password hash');
        $query = $db->prepare('INSERT INTO usuarios(nombre,email,password_hash) VALUES (?,?,?)');
        try { $query->execute([$tag, $tag . '@example.invalid', 'duplicate']); throw new RuntimeException('Duplicate accepted'); } catch (PDOException $e) { check((int)$e->errorInfo[1] === 1062, 'Unique email'); }
    } else {
        $query = $db->prepare('INSERT INTO categorias(nombre) VALUES (?)'); $query->execute([$tag]); $category = $db->lastInsertId();
        $query = $db->prepare('INSERT INTO productos(categoria_id,codigo,nombre,precio,stock) VALUES (?,?,?,?,?)'); $query->execute([$category,$tag,$tag,'12.50','1.250']); $id = $db->lastInsertId();
        $db->exec("UPDATE productos SET stock=stock+0.750 WHERE id=$id");
        $query = $db->prepare('INSERT INTO movimientos_stock(producto_id,usuario_id,cantidad,stock_anterior,stock_nuevo,motivo) VALUES (?,1,0.750,1.250,2.000,?)'); $query->execute([$id,$tag]);
        check($db->query("SELECT stock FROM productos WHERE id=$id")->fetchColumn() === '2.000', 'Exact decimal stock and movement');
        denied($db, "UPDATE productos SET stock=-1 WHERE id=$id", [4025]);
        denied($db, "INSERT INTO movimientos_stock(producto_id,usuario_id,cantidad,stock_anterior,stock_nuevo,motivo) VALUES ($id,1,1,2,9,'invalid')", [4025]);
    }
} finally { $db->rollBack(); }
$table = $auth ? 'usuarios' : 'productos';
check((int)$db->query("SELECT COUNT(*) FROM $table WHERE id=$id")->fetchColumn() === 0, 'Test records rolled back');
putenv('DB_PASSWORD=invalid-test-password');
try { Database::connect(); throw new RuntimeException('Bad credentials accepted'); } catch (PDOException $e) { check((int)$e->errorInfo[1] === 1045, 'Bad credentials rejected'); }