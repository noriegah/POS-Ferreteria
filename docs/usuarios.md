# Registro y listado de usuarios y verificación REST

## Uso desde la interfaz

Ingresar como administrador y elegir **Usuarios** en el menú lateral. Completar nombre, correo, contraseña y rol; pulsar **Registrar usuario**. El formulario selecciona cajero por defecto. La lista muestra nombre, correo, rol y estado, sin contraseña ni hash. El botón **Actualizar usuarios** consulta nuevamente las cuentas.

El cajero no ve la sección y la API le devuelve 403 si intenta registrar o listar cuentas. No hay registro público. Se conserva el comando de terminal para crear el primer administrador en una instalación nueva.

En este bloque solo se agregaron registro y listado, tal como los solicita la guía. No se incluyeron editar/eliminar cuentas, recuperación de contraseña ni otros módulos.

## Endpoints

| Método | Ruta pública | Permiso | Respuesta correcta |
|---|---|---|---|
| POST | `/api/auth/register` | Administrador activo | 201 y `user` con los datos públicos |
| GET | `/api/auth/users` | Administrador activo | 200 y lista `usuarios` |
| POST | `/api/auth/verify` | Token válido de usuario activo | 200 con `valid: true` y `user` |

Los tres requieren `Authorization: Bearer <token>`. El registro también requiere `Content-Type: application/json` y este cuerpo:

```json
{
  "nombre": "Cajero de ejemplo",
  "email": "cajero@example.com",
  "password": "ElegirUnaClavePropia",
  "rol": "cajero"
}
```

Roles aceptados: `administrador` y `cajero`. La palabra admin de la guía corresponde a administrador en este proyecto. El campo de correo se llama `email`, como en el login existente. La contraseña requiere 12-72 bytes y se guarda con bcrypt. El correo es único; se normaliza a minúsculas y se eliminan espacios en sus extremos.

Errores: 400 JSON incorrecto, 401 sin token/token inválido/usuario inactivo, 403 rol insuficiente, 405 método incorrecto, 409 correo repetido, 413 cuerpo demasiado grande, 415 contenido distinto a JSON y 422 campos inválidos. Los errores operativos devuelven 503 sin SQL ni credenciales.

## Comunicación entre servicios

El recorrido de una petición protegida de Inventario es:

1. Inventario verifica la firma RSA y las fechas del JWT con su clave pública.
2. Su clase `AuthClient` hace POST con cURL a `http://auth-service:8083/verify` y envía el Bearer recibido.
3. Autenticación verifica el token y consulta su propia tabla de usuarios para confirmar estado y rol actuales.
4. Inventario aplica el rol confirmado y permite o rechaza la operación.

La ruta interna es `/verify`, porque el prefijo `/api/auth/` solo lo añade el gateway público. Inventario recibe `AUTH_URL` desde Compose. No accede a auth_db ni recibe la clave privada.

Si Autenticación no responde, Inventario devuelve 503 y no permite la operación. Los tiempos máximos de cURL son 2 segundos para conectar y 5 en total. `/health` y `/ready` conservan sus verificaciones anteriores; `/ready` consulta solamente la base propia.

Desactivar una cuenta en la base impide sus siguientes peticiones protegidas a Inventario; un cambio de rol también se aplica en la siguiente verificación, aunque el JWT conserve el rol anterior. Esta comprobación no es una lista de tokens revocados. Cerrar sesión sigue descartando el token del navegador; no invalida otras copias válidas del mismo token. El frontend no incorpora todavía controles para cambiar rol/estado de cuentas existentes.

## Archivos

- `services/auth-service/src/Users.php`: consulta del usuario activo, permiso de administrador, registro y listado.
- `services/auth-service/public/index.php`: rutas de Autenticación y respuestas JSON.
- `services/inventario-service/src/AuthClient.php`: verificación del usuario por REST con cURL.
- `services/inventario-service/public/index.php`: aplica la verificación antes de las rutas protegidas.
- `docker-compose.yml`: agrega AUTH_URL a Inventario.
- `services/inventario-service/Dockerfile`: incorpora la extensión PHP cURL.
- `frontend/index.html` y `frontend/app.js`: formulario y listado de Usuarios dentro de AdminHMD.

No se cambiaron tablas, claves JWT ni datos existentes. El código nuevo tiene comentarios sencillos en español.

## Pruebas

```powershell
docker compose cp tests/jwt-integration.php auth-service:/tmp/jwt-integration.php
docker compose exec -T auth-service php /tmp/jwt-integration.php
powershell -ExecutionPolicy Bypass -File scripts/test-inventario.ps1
```

El primer programa prueba JWT y los nuevos endpoints con cuentas temporales: registro de ambos roles, bcrypt, login de las cuentas creadas, listado, validaciones, permisos y verificación de tokens inválidos, estados y cambios de rol. El segundo prueba las 38 comprobaciones del CRUD y una URL de Autenticación inaccesible (503). No detiene contenedores para simular ese fallo: cambia la URL solo dentro del proceso de prueba.

Como la verificación REST exige usuarios existentes, el script del CRUD ahora crea dos cuentas temporales, genera sus tokens y las elimina al finalizar. Ya no usa identidades sintéticas sin registro. Los datos temporales se eliminan; pueden quedar saltos en los identificadores.


Validación local: pruebas de API/JWT y las 38 comprobaciones del CRUD aprobadas; también se comprobó el rechazo 503 si la URL de Autenticación no responde. En el navegador se verificaron la visibilidad del formulario y listado del administrador, la navegación a Usuarios y su ocultación al cajero. El envío de registros se comprobó por HTTP en las pruebas automáticas. Se eliminaron las cuentas y registros temporales. Los datos del proyecto se conservaron.
