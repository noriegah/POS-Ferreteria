<?php
declare(strict_types=1);
require __DIR__ . '/../src/Database.php';
// Este comando se ejecuta desde la terminal, no desde una URL pública.
if (PHP_SAPI !== 'cli') { exit(1); }
// Muestra una pregunta en la terminal y recoge lo que escribe el usuario.
function ask(string $prompt): string { fwrite(STDOUT, $prompt); return trim((string)fgets(STDIN)); }
try {
    $name = ask('Nombre: '); $email = strtolower(ask('Email: '));
    if ($name === '' || strlen($name) > 150 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Nombre o email inválidos');
    }
    if (!stream_isatty(STDIN)) { throw new RuntimeException('Usa docker compose exec interactivo para introducir la contraseña'); }
    fwrite(STDOUT, 'Contraseña (12-72 bytes, oculta): ');
// Oculta la contraseña mientras se escribe; finally vuelve a mostrar la entrada normal de la terminal.
    exec('stty -echo', $output, $status);
    if ($status !== 0) { throw new RuntimeException('No se pudo ocultar la contraseña'); }
    try { $password = rtrim((string)fgets(STDIN), "\r\n"); }
    finally { exec('stty echo'); fwrite(STDOUT, "\n"); }
    if (strlen($password) < 12 || strlen($password) > 72) { throw new RuntimeException('Longitud inválida'); }
    $db = Database::connect();
// Solo permite crear el primer administrador activo; no reemplaza una cuenta existente.
    if ($db->query("SELECT 1 FROM usuarios WHERE rol='administrador' AND activo=1 LIMIT 1")->fetchColumn()) {
        throw new RuntimeException('Ya existe un administrador activo; este comando solo permite el alta inicial');
    }
// Guarda un hash bcrypt, nunca la contraseña original.
    $query = $db->prepare("INSERT INTO usuarios(nombre,email,password_hash,rol) VALUES (?,?,?,'administrador')");
    $query->execute([$name,$email,password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]);
    fwrite(STDOUT, "Administrador creado.\n");
} catch (Throwable $e) { fwrite(STDERR, 'No se pudo crear el administrador: ' . ($e instanceof PDOException ? 'error de persistencia o email duplicado' : $e->getMessage()) . "\n"); exit(1); }
