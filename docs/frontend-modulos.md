# Navegación y presentación

El acceso del administrador abre únicamente **Dashboard**. El cajero entra directamente en **Caja / Ventas**. No se presenta el mensaje de sesión iniciada correctamente. Cada opción del menú muestra un único módulo y marca su enlace activo; se puede usar Atrás sin perder el carrito ni los datos de los formularios.

El dashboard muestra cifras reales de Inventario: cantidad de productos activos, categorías y productos con stock menor que 5. La tarjeta de stock bajo abre Productos con ese filtro aplicado. No se incluyen gráficas de ejemplo ni cifras de ventas todavía no consultadas.

**Categorías** tiene pantalla propia para crear y listar. Registrar producto incluye un enlace **+ Nueva categoría** que abre esa pantalla. Ya no se muestran dos formularios juntos. Los permisos de administrador/cajero continúan comprobándose en PHP.

La presentación conserva AdminHMD y sus créditos. Los ajustes locales usan verde, azul oscuro y ámbar, menú oscuro, tarjetas de resumen y un logo SVG con una F dentro de una tuerca. Todos los recursos permanecen locales. Los precios conservan Q y dos decimales; las cantidades son enteras.

## Códigos de producto

- Al crear sin `codigo`, o con una cadena vacía, el servidor asigna **PR-000123**, usando el id real del producto. No se calcula con MAX+1 ni depende del navegador.
- El código definitivo y el producto se guardan en la misma transacción. El índice único existente impide duplicados. No requiere migración ni modifica códigos anteriores.
- Puede escribirse un código propio o introducirlo con un lector de códigos de barras que funcione como teclado. Se conserva como texto, incluidos los ceros iniciales. No se incorpora escaneo por cámara en este cambio.
- El prefijo PR- seguido únicamente de números está reservado para la asignación automática. Los códigos pueden tener saltos por pruebas o intentos revertidos.
- Al editar con código vacío se conserva el actual. Se puede reemplazar por otro código manual no repetido. Mantener el código automático existente también está permitido.

## Validaciones realizadas

Pruebas de Inventario: CRUD, filtros, imágenes, cantidades, código automático vacío/omitido, conservación al editar, ceros de código de barras, duplicados y prefijo reservado. Pasó además la batería de 59 comprobaciones de Ventas y factura y la sintaxis PHP/JavaScript/Nginx.

En navegador: acceso del administrador solo al dashboard y sin mensaje de éxito; módulos separados, creación de categoría, creación de producto con código automático, edición, filtro desde tarjeta y navegación Atrás. En móvil: página sin desbordamiento horizontal y menú que cierra al seleccionar un módulo. Cajero: entrada directa en Caja, sin enlaces de administración; búsqueda y conservación del carrito al cambiar de módulo. Se retiraron productos, categorías y cuentas temporales. El producto previo CO-001 conservó Q 429.00 y 15 unidades.

Para ver los cambios, recargar con Ctrl + F5 e iniciar sesión. El frontend se sirve desde el mismo montaje de Nginx. Solo se reconstruyó Inventario para la asignación del código.
