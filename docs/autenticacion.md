# Fase 2 — bloque 2: autenticación JWT

## Endpoints implementados

| Método | Ruta pública | Comportamiento |
|---|---|---|
| POST | `/api/auth/login` | Recibe JSON con `email` y `password`; devuelve `access_token`, `token_type`, `expires_in` y datos públicos del usuario. |
| GET | `/api/auth/me` | Requiere `Authorization: Bearer <token>`; consulta los datos y el estado actual del usuario en Autenticación. |
| GET | `/api/inventario/session` | Valida el token con la clave pública y devuelve identidad, rol y permisos de inventario. |

`/health` y `/ready` conservan sus contratos. El bloque 3 incorpora CRUD de productos y aplica el guard de roles a las escrituras; consulta inventario.md. `session` conserva la consulta de identidad y permisos.

Credenciales incorrectas, usuarios inexistentes e inactivos producen el mismo error 401. También se rechazan JSON inválido (400), métodos incorrectos (405), solicitudes mayores de 8 KiB (413), tipo de contenido incorrecto (415) y campos inválidos (422). Un error operativo devuelve 503 sin detalles de SQL o claves. Las respuestas no se deben almacenar en caché.

## Crear el administrador inicial

Desde la raíz del proyecto, en una terminal interactiva:

```powershell
docker compose exec auth-service php /app/bin/create-admin.php
```

El comando solicita nombre, email y una contraseña de 12 a 72 bytes, que se introduce oculta. No recibe la contraseña en argumentos ni crea credenciales predeterminadas. Guarda un hash bcrypt de coste 12 y rechaza el alta si ya existe un administrador activo. No se ha creado un administrador permanente durante las pruebas. El registro y listado de usuarios se incorporaron en un bloque posterior; consultar usuarios.md.

Ejemplo de cuerpo de login (usar las credenciales elegidas):

```json
{"email":"tu-email@example.com","password":"tu-contraseña"}
```

Enviar ese JSON a `http://localhost:8080/api/auth/login` con `Content-Type: application/json`. Copiar el token devuelto a la cabecera `Authorization: Bearer <token>` al consultar `/api/auth/me` o `/api/inventario/session`. Los errores de autenticación incluyen `WWW-Authenticate: Bearer`.

## Claves y validación

Se utiliza `firebase/php-jwt` v7.2.1, fijado mediante `composer.lock` en cada servicio. Referencia de la biblioteca: https://github.com/googleapis/php-jwt.

La firma es RS256 con un par RSA de 3072 bits. Autenticación monta la clave privada y la pública en modo de lectura; Inventario monta únicamente la pública. `secrets/`, `.env`, dependencias descargadas y respaldos quedan excluidos de Git. Las claves tampoco se incluyen en las imágenes.

Variables documentadas en `.env.example`:

- `JWT_ISSUER=pos-ferreteria-auth`
- `JWT_AUDIENCE=pos-ferreteria-api`
- `JWT_TTL=900` (15 minutos; permitido entre 60 y 3600 segundos)

Se verifican firma, algoritmo fijado, emisor, audiencia, expiración, fechas de emisión e inicio, identificador del usuario, rol y `jti`. Los roles admitidos son `administrador` y `cajero`. El token no incluye email, contraseña ni información de conexión. Inventario no consulta tablas de Autenticación.

No hay refresh tokens ni lista de tokens revocados. En la actualización de registro y listado, Inventario agrega verificación REST contra /auth/verify: cada petición protegida confirma que el usuario esté activo y usa su rol actual. Cerrar sesión descarta el token del navegador, pero no invalida otras copias todavía vigentes. Ver usuarios.md para los nuevos endpoints y pruebas.

## Instalación nueva y VPS

Las claves existentes se conservan entre reconstrucciones. Para generar un par nuevo en Windows, antes de iniciar ambos servicios, ejecutar con PowerShell 7:

```powershell
pwsh -File scripts/generate-jwt-keys.ps1
```

El generador no sobrescribe claves. Para un VPS Linux con OpenSSL, desde la raíz del proyecto y con `secrets/` todavía vacío:

```sh
mkdir -p secrets
chmod 700 secrets
umask 077
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out secrets/jwt_private.pem
openssl pkey -in secrets/jwt_private.pem -pubout -out secrets/jwt_public.pem
```

No ejecutar esos comandos sobre las claves actuales: generar otro par invalida los tokens existentes al reemplazar también la clave pública. Mantener una copia protegida fuera del servidor. El despliegue público requiere HTTPS, un servidor PHP de producción y limitación de intentos de login; esta entrega sigue usando el servidor PHP local de la Fase 1 y aún no configura esas medidas del VPS.

Después de crear claves, preparar `.env`, aplicar las migraciones y ejecutar:

```powershell
docker compose up -d --build
docker compose exec gateway nginx -s reload
```

## Pruebas reproducibles

```powershell
docker compose cp tests/jwt-integration.php auth-service:/tmp/jwt-integration.php
docker compose exec -T auth-service php /tmp/jwt-integration.php
```

La prueba crea tres usuarios temporales con contraseñas aleatorias, utiliza HTTP a través de Nginx y los elimina en `finally`. No imprime tokens ni contraseñas. Comprueba login, perfil, permisos, guard de rol 403, usuarios inactivos, errores de entrada, firma alterada, expiración, fechas futuras, emisor, audiencia, claims requeridos y algoritmo incorrecto, además de los seis endpoints de infraestructura. El 403 se comprueba sobre el guard de roles; las rutas de escritura de inventario se prueban por separado en el bloque 3. Pueden quedar saltos en los identificadores de usuario.

Validación local del 20 de octubre de 2026: pruebas JWT y sintaxis PHP/Nginx aprobadas. Las cuentas de prueba se eliminaron. El volumen de MariaDB, las redes y los servicios de Ventas y Reportes se conservaron.
