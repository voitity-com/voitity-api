# Credenciales de Runway

## Regla principal

`RUNWAY_API_KEY` nunca debe almacenarse en Git, colecciones de Postman,
documentación, pruebas, imágenes Docker ni artefactos de CI. Una credencial que
haya llegado a un commit se considera comprometida y debe revocarse, aunque se
elimine posteriormente del archivo actual.

## Entornos

- Producción obtiene el archivo de entorno completo desde el secreto de AWS
  Secrets Manager `bigmelo/prod/api/env` en cada despliegue a `prod`.
- Desarrollo local utiliza `src/.env`, que está ignorado por Git.
- Postman utiliza un Environment personal no exportado. La colección versionada
  conserva `RUNWAY_API_KEY` con valor vacío.
- Las herramientas promocionales leen la credencial local en tiempo de ejecución
  y no deben copiarla a sus scripts o artefactos.

Se recomienda utilizar credenciales diferentes para producción, desarrollo y
herramientas promocionales. Para aislar también presupuesto y atribución de uso,
se deben utilizar organizaciones de Runway diferentes.

## Postman

1. Importe `postman/runway-foto-a-video-loop.postman_collection.json`.
2. Cree un Environment personal, por ejemplo `Bigmelo local`.
3. Agregue `RUNWAY_API_KEY` como variable secreta y active ese Environment.
4. No sincronice ni exporte el Environment con el valor secreto.

La colección falla antes de enviar una solicitud cuando la variable no está
configurada.

## Controles automáticos

`scripts/check-no-runway-secrets.sh` revisa todos los archivos rastreados por Git
y rechaza patrones compatibles con claves de Runway. También comprueba que la
variable de Postman permanezca vacía.

El control se ejecuta:

- en pushes a `main` y `prod`;
- en pull requests;
- antes de construir o desplegar la imagen de producción.

GitHub Secret Scanning y Push Protection deben permanecer habilitados como una
segunda barrera.

## Sincronización de producción

El workflow `.github/workflows/deploy-prod.yml` ejecuta
`scripts/deploy/sync-production-env.sh` en la instancia EC2 antes de las
migraciones y antes de recrear los contenedores. El script:

1. descarga una versión fijada por commit del ejecutor oficial `asm-exec` y
   valida su SHA-256;
2. resuelve `AWSCURRENT` mediante una referencia dinámica con hasta tres
   intentos para tolerar timeouts transitorios, sin devolver el contenido del
   secreto a GitHub Actions ni a SSM;
3. valida las variables obligatorias y el formato de `RUNWAY_API_KEY`;
4. incorpora la configuración no secreta de dominios personalizados;
5. reemplaza `/opt/bigmelo/api/.env` de forma atómica y con permisos `0600`;
6. continúa con limpieza de configuración, migraciones, recreación de
   contenedores y reinicio de colas.

Si la resolución, el checksum o una validación falla, el archivo `.env` vigente
no se reemplaza y el despliegue termina con error. Actualizar el secreto no
reinicia la aplicación por sí solo; el valor nuevo se aplica en el siguiente
deploy a `prod` o en una ejecución manual del workflow con `deploy=true`.

## Respuesta a una exposición

1. Revocar inmediatamente la clave comprometida en Runway Developer Portal.
2. Crear credenciales nuevas y separadas por entorno.
3. Actualizar producción y desarrollo sin registrar los valores en terminales o
   logs.
4. Ejecutar `bash scripts/check-no-runway-secrets.sh`.
5. Revisar `POST /v1/organization/usage` y confirmar que no haya modelos o fechas
   inesperados.
6. Si la clave estuvo en un repositorio público, solicitar a Runway los registros
   disponibles y evaluar una limpieza coordinada del historial de Git.
