# Búsqueda paginación alertas e imágenes de productos

## Uso desde el navegador

Ingresar en http://localhost:8080. En Productos activos se puede buscar por código o nombre, elegir categoría y marcar **Solo stock menor que 5**. Pulsar **Buscar** aplica los filtros y regresa a la primera página. **Limpiar filtros** muestra nuevamente todos los productos activos.

La tabla muestra 10 productos por página. **Anterior** y **Siguiente** cambian de página conservando los filtros. El contador indica los productos visibles y el total que coincide con la búsqueda. Si no hay coincidencias aparece un mensaje y los botones de página quedan desactivados.

Las alertas aparecen cuando hay productos activos con menos de 5 unidades. Este umbral es el solicitado en la guía y no depende del campo stock_minimo. Se actualizan al iniciar sesión, actualizar la lista y guardar o desactivar productos. No son notificaciones automáticas en tiempo real.

El administrador puede elegir una foto opcional al crear o editar un producto. Se aceptan JPG, PNG y WEBP de hasta 2 MB. En edición se muestra la imagen actual; si no se elige otro archivo, se conserva. El cajero puede consultar fotos y alertas, pero no subir imágenes ni modificar productos.

Primero se guarda el producto y después se carga la foto. Si la foto falla, el producto permanece guardado y la interfaz lo informa para evitar crear un duplicado. Se puede editar de nuevo y elegir otra imagen.

## API

Todas las rutas de Inventario requieren `Authorization: Bearer <token>`, salvo los archivos estáticos de imagen que sirve Nginx.

| Método | Ruta | Función |
|---|---|---|
| GET | `/api/inventario/productos` | Lista paginada de productos activos |
| GET | `/api/inventario/alertas` | Productos activos con stock menor que 5 |
| POST | `/api/inventario/productos/{id}/imagen` | Carga una imagen; solo administrador |

Parámetros opcionales del listado:

- `buscar`: texto del código o nombre, hasta 150 bytes. Los símbolos `%` y `_` se buscan literalmente.
- `categoria_id`: identificador de una categoría.
- `stock_bajo=1`: incluye únicamente existencias menores que 5; `0` no aplica este filtro.
- `pagina`: entero positivo; por defecto 1. Si supera la última página se devuelve la última disponible.
- `limite`: de 1 a 50 productos; por defecto 10. La interfaz usa 10.

Ejemplo: `/api/inventario/productos?buscar=martillo&categoria_id=1&pagina=1&limite=10`.

El listado conserva la clave `productos` y agrega `paginacion`:

```json
{
  "productos": [],
  "paginacion": {"pagina": 1, "limite": 10, "total": 0, "paginas": 1}
}
```

Alertas devuelve `productos` con id, código, nombre y stock, y `umbral: 5`. El endpoint de imagen recibe `multipart/form-data` con el campo de archivo `imagen` y devuelve `producto` con su `imagen_url`. No debe enviarse el archivo como JSON.

La API revisa el contenido real y el formato de la foto, no solo el nombre ni el MIME declarado por el navegador. Genera un nombre aleatorio. Un archivo no permitido devuelve 422; uno mayor que el límite devuelve 413. Los filtros incorrectos devuelven 422; los permisos conservan 401 y 403.

## Datos y archivos

`002-imagen-producto.sql` agrega el campo nullable `imagen_url` a productos sin borrar datos. `scripts/migrate.ps1` hace respaldo primero y aplica los archivos SQL ordenados. Los SQL actuales permiten repetirse sin recrear los datos; futuros cambios deben conservar esa precaución. En instalaciones nuevas también hay que ejecutar la migración.

Las imágenes se guardan en `uploads/productos/` del proyecto. Inventario monta esa carpeta en `/app/uploads` con escritura. Nginx la monta por separado en `/var/www/product-images` con solo lectura y la sirve mediante `/uploads/productos/`. Este montaje separado evita escribir dentro del montaje de solo lectura del frontend. La configuración admite cuerpos de hasta 3 MB para permitir el formulario y PHP valida cada imagen hasta 2 MB.

Las fotos sobreviven a reconstrucciones porque se guardan en el equipo, fuera del sistema de archivos del contenedor. Se excluyen de Git; se mantiene `.gitkeep` para documentar la carpeta. Para el VPS se deben copiar también estos archivos, junto con el respaldo SQL: el dump SQL solo contiene las direcciones, no los archivos de imagen. Las fotos anteriores no se borran automáticamente al sustituir una imagen.

El código nuevo está comentado en español en Products.php, ProductImages.php, index.php, app.js, la migración y la configuración correspondiente. No se agregaron servicios ni APIs externas en este bloque.

## Comprobaciones

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-inventario.ps1
```

La prueba anterior de CRUD se amplió con paginación, páginas distintas, búsqueda por código y nombre, filtros combinados, parámetros incorrectos, búsqueda literal de comodines y el límite exacto de stock (4 genera alerta, 5 no). También prueba permisos de imagen, archivo obligatorio, contenido falso, tamaño excesivo, nombre seguro, guardado de URL, conservación al editar y entrega del archivo por Nginx. Se conservó la prueba de Autenticación no disponible.

Las pruebas de API y la regresión JWT pasaron. En navegador se verificaron la segunda página, búsqueda y categoría combinadas, selección/subida de una imagen de prueba desde el formulario, su aparición en la tabla y consulta de fotos y alertas con el cajero, sin controles de carga. Las cuentas, productos, categorías e imágenes temporales se retiraron al finalizar. Los datos existentes se conservaron.
