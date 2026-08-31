# Bigmelo local setup

Esta guía levanta el proyecto completo en local usando los tres repositorios
hermanos:

```text
/Users/abel/Documents/tike/apps/
├── voitity-api
├── voitity-web
└── voitity-admin
```

El procedimiento no modifica AWS ni la configuración de producción. El único
ajuste específico para desarrollo es `voitity-api/docker-compose.local.yml`,
que hace que el worker local procese las colas `chat` y `default`. La plantilla
reproducible es `docker-compose.local.example.yml`; el archivo activo está
excluido localmente mediante `.git/info/exclude` y debe seguir sin versionarse.

## Requisitos

- Docker Desktop o Docker Engine en ejecución.
- Docker Compose v2 (`docker compose version`).
- Git.
- Puertos locales libres: `3000`, `3001`, `8000` y `5433`.
- Node.js 22 o superior y npm 10 o superior solo si se ejecutan los frontends
  fuera de Docker.

## URLs y servicios

| Componente | URL o puerto | Contenedor |
| --- | --- | --- |
| Admin | <http://localhost:3000> | `voitity-admin-app` |
| Web y perfiles públicos | <http://localhost:3001> | `voitity-web-app` |
| API | <http://localhost:8000> | `voitity-laravel-app` |
| Swagger | <http://localhost:8000/api/documentation> | `voitity-laravel-app` |
| PostgreSQL | `localhost:5433` | `voitity-pgvector-db` |
| Worker | sin puerto público | `voitity-laravel-queue` |
| Scheduler | sin puerto público | `voitity-laravel-scheduler` |

Un perfil publicado se abre con su alias, por ejemplo
<http://localhost:3001/abel-dev>.

## 1. Preparar las variables de entorno

No copies valores reales de producción. Los archivos `.env` son locales y no
deben versionarse.

### API

El entrypoint crea `voitity-api/src/.env` desde `.env.example` y genera
`APP_KEY` si aún no existen. Para prepararlo manualmente:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
cp -n src/.env.example src/.env
```

Para el flujo básico, confirma en `src/.env`:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=360
DB_QUEUE_AFTER_COMMIT=true
ADMIN_APP_URL=http://localhost:3000
PROFILE_DOMAIN_DRIVER=local
PROFILE_DOMAIN_LOCAL_PUBLIC_URL_PATTERN=http://{hostname}:3001
FILESYSTEM_PROFILES_DRIVER=local
PROFILE_APPEARANCE_DISK=profiles
PROFILE_APPEARANCE_VISIBILITY=public
```

Docker Compose inyecta la conexión local a PostgreSQL, por lo que los valores
SQLite de `.env.example` no se usan dentro de los contenedores. Las funciones
de IA necesitan además una credencial local válida en `OPENAI_API_KEY`. Voz,
video, redes sociales, correo o captcha solo requieren sus variables si se va
a probar esa integración. Nunca agregues esas credenciales a este documento.

### Web pública

```sh
cd /Users/abel/Documents/tike/apps/voitity-web
cp -n src/.env.example src/.env
```

Configuración mínima de `src/.env`:

```env
VITE_API_BASE_URL=http://localhost:8000
VITE_ADMIN_BASE_URL=http://localhost:3000
```

### Admin

```sh
cd /Users/abel/Documents/tike/apps/voitity-admin
cp -n src/.env.example src/.env
```

Configuración mínima de `src/.env`:

```env
VITE_API_BASE_URL=http://localhost:8000
VITE_PUBLIC_PROFILE_BASE_URL=http://localhost:3001
```

El Compose del admin ya fija `VITE_API_BASE_URL` a la API local. Configura
Google OAuth u otro proveedor únicamente si necesitas probar ese inicio de
sesión.

## 2. Levantar la API y sus procesos

En la primera instalación, crea el override local desde la plantilla:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
cp -n docker-compose.local.example.yml docker-compose.local.yml
```

Usa siempre ambos archivos de Compose en desarrollo. El segundo reemplaza solo
el comando del worker:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.local.yml ps
```

El worker debe ejecutar este comando:

```text
php artisan queue:work database --queue=chat,media,default --sleep=1 --tries=3 --timeout=300 --max-time=3600
```

`chat` aparece primero para priorizar las respuestas del chat; `media` procesa
la clonación de voz y otros trabajos multimedia; `default` mantiene funcionando
notificaciones y el resto de trabajos generales. No uses
`QUEUE_CONNECTION=sync` para resolver esto: ocultaría el comportamiento real
de la cola y haría que las peticiones HTTP esperaran el procesamiento de IA.

En la primera instalación, crea las tablas y un usuario local con credenciales
que tú elijas:

```sh
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan migrate
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan dev:create-test-user nombre@local.test 'elige-una-clave-local'
```

El comando de usuario está protegido y solo funciona cuando `APP_ENV=local`.
Si necesitas los datos base adicionales definidos por el proyecto, ejecuta:

```sh
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan db:seed
```

## 3. Levantar la web pública

```sh
cd /Users/abel/Documents/tike/apps/voitity-web
docker compose up -d --build
docker compose ps
```

## 4. Levantar el admin

```sh
cd /Users/abel/Documents/tike/apps/voitity-admin
docker compose up -d --build
docker compose ps
```

## 5. Verificar el entorno

```sh
curl --fail http://localhost:8000/api/health
curl --fail --output /dev/null http://localhost:3001
curl --fail --output /dev/null http://localhost:3000
```

La API debe responder `{"message":"ok"}`. Después:

1. Inicia sesión en <http://localhost:3000>.
2. Abre un perfil publicado en <http://localhost:3001/alias>.
3. Envía un mensaje en el chat.
4. Confirma que el worker registra el procesamiento y que la respuesta deja de
   estar en estado pendiente.

Para revisar el worker:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml logs --tail=100 queue
docker inspect voitity-laravel-queue --format '{{json .Config.Cmd}}'
```

Para ver cuántos trabajos quedan por cola, sin mostrar el contenido de los
jobs:

```sh
docker compose -f docker-compose.yml -f docker-compose.local.yml exec -T db \
  psql -U voitity -d voitity -c 'select queue, count(*) from jobs group by queue order by queue;'
```

## Operación diaria

Levantar todo después de reiniciar Docker:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d

cd /Users/abel/Documents/tike/apps/voitity-web
docker compose up -d

cd /Users/abel/Documents/tike/apps/voitity-admin
docker compose up -d
```

Si cambias código PHP, React o TypeScript, los bind mounts actualizan el código
sin reconstruir normalmente. Reconstruye cuando cambien `Dockerfile`, paquetes
de Composer, `package.json` o archivos lock. Si cambias variables de entorno,
recrea el servicio afectado.

Después de modificar código que consume la cola, reinicia el proceso de larga
duración:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml restart queue
```

Logs útiles:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml logs -f app queue scheduler

cd /Users/abel/Documents/tike/apps/voitity-web
docker compose logs -f

cd /Users/abel/Documents/tike/apps/voitity-admin
docker compose logs -f
```

## Pruebas y builds

API:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan test
```

Web pública:

```sh
cd /Users/abel/Documents/tike/apps/voitity-web/src
npm ci
npm run build
```

Admin:

```sh
cd /Users/abel/Documents/tike/apps/voitity-admin/src
npm install
npm run typecheck
npm run build
```

## Detener el entorno

Detén cada Compose sin borrar los datos:

```sh
cd /Users/abel/Documents/tike/apps/voitity-admin
docker compose down

cd /Users/abel/Documents/tike/apps/voitity-web
docker compose down

cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml down
```

No ejecutes `docker compose down -v` salvo que quieras eliminar deliberadamente
la base PostgreSQL local y las dependencias guardadas en volúmenes.

## Solución de problemas

### El chat queda esperando y la API devuelve 202

Comprueba primero el comando del worker y el conteo de colas con los comandos
de verificación anteriores. Si hay trabajos en `chat` y el worker no muestra
`--queue=chat,media,default`, recréalo con el override. La cola `media` procesa, entre otras tareas, la clonación de voz:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --force-recreate queue
docker compose -f docker-compose.yml -f docker-compose.local.yml logs --tail=100 queue
```

Si el trabajo sí se consume pero falla, revisa `src/storage/logs/laravel.log` y
confirma que `OPENAI_API_KEY` está configurada localmente. No copies una clave
desde producción ni la imprimas en la terminal.

### Un frontend no refleja cambios de `.env`

Vite carga las variables al arrancar. Recrea el contenedor correspondiente:

```sh
docker compose up -d --force-recreate
```

Ejecuta el comando desde `voitity-web` o `voitity-admin`, según corresponda.

### La API no conecta a PostgreSQL

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml ps
docker compose -f docker-compose.yml -f docker-compose.local.yml logs --tail=100 db app
```

Dentro de Docker, el host de base de datos es `db:5432`. Desde macOS es
`localhost:5433`.

### Un puerto ya está ocupado

Detén el proceso que usa `3000`, `3001`, `8000` o `5433`, o cambia de forma
coordinada el mapeo en Compose y las URLs de los `.env` de ambos frontends.

## Separación respecto a producción

- `docker-compose.local.yml` solo se aplica al invocarlo explícitamente con
  `-f` y está excluido por Git en esta máquina. La plantilla `.example.yml` no
  se carga automáticamente.
- No cambia `docker-compose.yml`, `ProcessStoredMessageJob`, CloudFormation ni
  las variables SQS.
- Producción conserva sus workers y colas administradas por su propia
  infraestructura.
- No ejecutes desde esta guía comandos de AWS, despliegue ni migraciones contra
  una base remota.

## Fondos personalizados de perfiles

El editor de templates guarda las imágenes mediante el disco Laravel
`profiles`. En local, `FILESYSTEM_PROFILES_DRIVER=local` escribe los archivos
en `src/storage/app/public/profiles/{profileId}/backgrounds/` y la API los
publica desde `APP_URL/storage/...`. Si una instalación local no muestra la
imagen, crea el enlace público de Laravel una sola vez:

```sh
cd /Users/abel/Documents/tike/apps/voitity-api
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan storage:link
```

Producción usa el mismo código y cambia únicamente la configuración del disco:

```env
FILESYSTEM_PROFILES_DRIVER=s3
PROFILE_APPEARANCE_DISK=profiles
PROFILE_APPEARANCE_VISIBILITY=public
AWS_PROFILES_BUCKET=nombre-del-bucket
AWS_PROFILES_URL=https://dominio-publico-de-assets
AWS_DEFAULT_REGION=region-configurada
```

Las credenciales de AWS deben permanecer en el mecanismo seguro de variables
del entorno de despliegue; no se guardan en `.env.example`, Git ni Postman. No
es necesario crear carpetas manualmente en S3: la clave
`profiles/{profileId}/backgrounds/{uuid}.ext` crea el prefijo al subir el
objeto. Una imagen nueva se guarda primero, luego se actualiza la base de datos
y solo después se elimina la anterior, para conservar el fondo existente si la
carga falla.
