<?php
declare(strict_types=1);

// Guarda fotos de productos en la carpeta que comparten Inventario y Nginx.
final class ProductImages
{
    public static function upload(PDO $db, int $id): array
    {
        $products = new Products($db);
        $products->find($id);
        $file = $_FILES['imagen'] ?? null;
        if (!$file || !is_int($file['error'] ?? null)) { throw new HttpError(422, 'Selecciona una imagen'); }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpError(413, 'La imagen no debe superar 2 MB');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpError(422, 'No se pudo recibir la imagen');
        }
        if (filesize($file['tmp_name']) > 2 * 1024 * 1024) { throw new HttpError(413, 'La imagen no debe superar 2 MB'); }
        // Comprueba el contenido del archivo, no solo el nombre enviado por el usuario.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $formats = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $size = @getimagesize($file['tmp_name']);
        if (!isset($formats[$mime]) || !$size || ($size['mime'] ?? null) !== $mime) {
            throw new HttpError(422, 'Solo se permiten imágenes JPG, PNG o WEBP');
        }
        $folder = '/app/uploads';
        if (!is_dir($folder) || !is_writable($folder)) { throw new HttpError(503, 'La carpeta de imágenes no está disponible'); }
        // El nombre aleatorio evita sobreescrituras y no utiliza rutas recibidas del cliente.
        $name = bin2hex(random_bytes(16)) . '.' . $formats[$mime];
        $path = $folder . '/' . $name;
        $url = '/uploads/productos/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) { throw new HttpError(503, 'No se pudo guardar la imagen'); }
        try {
            $query = $db->prepare('UPDATE productos SET imagen_url=? WHERE id=? AND activo=1');
            $query->execute([$url,$id]);
            if ($query->rowCount() !== 1) { throw new HttpError(404, 'Producto no encontrado'); }
        } catch (Throwable $e) {
            // Si falla la actualización, elimina únicamente el archivo que acaba de recibir.
            @unlink($path);
            throw $e;
        }
        return $products->find($id);
    }
}
