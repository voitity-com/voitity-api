# Configuración de producción de Bigmelo

Última revisión: 2 de septiembre de 2026. Región principal: `us-east-1`.

## Objetivo operativo

La plataforma queda diseñada para tolerar la pérdida de una instancia del API y la pérdida de una zona de disponibilidad de la base de datos. El tráfico público entra por servicios administrados, las tareas lentas no bloquean las solicitudes web y la capacidad de cómputo aumenta o disminuye automáticamente entre 2 y 6 instancias.

La infraestructura está declarada en dos stacks:

- `bigmelo-prod`: DNS, CloudFront, S3, ECR, IAM y PostgreSQL.
- `bigmelo-prod-ha`: ALB, Auto Scaling, SQS, WAF, health checks, alarmas y dashboard.

Los templates fuente son `infra/cloudformation/bigmelo-prod.yml` e `infra/cloudformation/bigmelo-prod-ha.yml`. Los secretos existentes se conservaron en Secrets Manager, quedaron desacoplados de CloudFormation para impedir que un cambio de infraestructura sobrescriba sus valores y no se almacenan en estos templates ni en GitHub.

## Sitios y dominios revisados

| Sitio | Entrada pública | Origen | Estado |
| --- | --- | --- | --- |
| Web Bigmelo | `bigmelo.com`, `www.bigmelo.com` | CloudFront y S3; rutas dinámicas hacia el API | Activo |
| Administración | `admin.bigmelo.com` | CloudFront y S3 | Activo |
| Blog | `blog.bigmelo.com` | CloudFront y S3 | Activo |
| API | `api.bigmelo.com` | ALB, WAF y Auto Scaling | Alta disponibilidad |
| Assets generados | Dominio CloudFront administrado | CloudFront y S3 privado | Activo |
| Dominios de perfiles | Distribución multi-tenant y connection group de CloudFront | Web Bigmelo en S3 | Activo |
| Bigmelo Labs | `bigmelolabs.com`, `www.bigmelolabs.com` | Stack y CloudFront independientes | Revisado, sin cambios |
| Abeldev | `abeldev.com`, `www.abeldev.com` | Stack y CloudFront independientes | Revisado, sin cambios |

Los buckets de web, admin, assets y archivos privados bloquean acceso público directo y usan cifrado en reposo. CloudFront es la entrada pública para los sitios estáticos.

## Flujo del API

```text
Internet
  -> Route 53
  -> AWS WAF
  -> Application Load Balancer HTTPS
  -> Target group /health/ready
  -> Auto Scaling Group (2 a 6 EC2 t4g.small, dos zonas)
  -> Nginx :8000
  -> PHP-FPM / Laravel
  -> RDS PostgreSQL Multi-AZ
```

El ALB termina TLS, redirige HTTP a HTTPS y sólo permite llegar a las instancias desde su propio security group. Las instancias no aceptan tráfico público directo en los puertos del API. IMDSv2 es obligatorio y los discos raíz son gp3 cifrados.

El contenedor del API usa Nginx y PHP-FPM. `php artisan serve` queda únicamente para desarrollo, no para producción.

## Procesos en cola

| Cola | Procesos | Worker por instancia | Timeout / visibilidad | DLQ |
| --- | --- | --- | --- | --- |
| `bigmelo-prod-chat` | Construcción de respuestas de chat e IA después de guardar el mensaje | `worker-chat` | 90 s por job; 180 s en SQS | `bigmelo-prod-chat-dlq` |
| `bigmelo-prod-media` | Generación y consulta de imágenes y videos, clonación y muestras de voz | `worker-media` | Hasta 600 s | `bigmelo-prod-media-dlq` |
| `bigmelo-prod-default` | Correos, dominios de perfiles, notificaciones y demás jobs generales | `worker-chat`, después de chat | Hasta 360 s | `bigmelo-prod-default-dlq` |

Las colas usan long polling de 20 segundos, cifrado administrado por SQS, retención de 14 días y máximo 5 recepciones antes de enviar un job a su DLQ. Laravel despacha después del commit de base de datos.

La web reconoce el `202 Processing`, conserva el token cifrado de la sesión y consulta el estado del mensaje hasta obtener la respuesta. Una solicitud de IA lenta ya no mantiene ocupado un proceso HTTP.

El scheduler se ejecuta en todas las instancias para no tener un punto único de falla. Las tareas críticas usan `onOneServer()` y locks en el cache de base de datos para que sólo una instancia las ejecute.

## Auto Scaling

El grupo `bigmelo-prod-api` tiene:

- mínimo 2 instancias;
- capacidad deseada 2;
- máximo 6;
- distribución en `us-east-1a` y `us-east-1b`;
- reemplazo automático de una instancia que falle el health check del ALB;
- warm-up de 180 segundos y grace period de 300 segundos;
- despliegue rolling de una instancia a la vez, manteniendo al menos una disponible.

El escalamiento aumenta capacidad cuando cualquiera de estas señales lo exige:

- CPU promedio objetivo de 55%;
- 600 solicitudes por target en el periodo de la métrica del ALB;
- aproximadamente 5 mensajes visibles de chat por instancia objetivo;
- aproximadamente 2 mensajes visibles de media por instancia objetivo.

Para reducir capacidad, las políticas de target tracking deben coincidir en que ya no hace falta capacidad adicional. Nunca se baja de dos instancias. Las instancias se encienden y terminan automáticamente; no requiere intervención manual.

## Base de datos

RDS PostgreSQL queda con:

- clase `db.t4g.micro`;
- Multi-AZ habilitado;
- 20 GiB gp3 cifrados;
- autoescalamiento de almacenamiento hasta 100 GiB;
- 7 días de backups automáticos;
- protección contra eliminación;
- acceso privado sólo desde los security groups autorizados;
- actualización automática de versiones menores.

Multi-AZ mantiene un standby síncrono en otra zona y RDS realiza el failover administrado. Esto mejora disponibilidad, pero no reemplaza backups ni pruebas periódicas de restauración.

## Despliegues

El push a `prod` del API:

1. construye una imagen ARM64 con tag del commit y tag `prod`;
2. la publica en ECR;
3. descubre las instancias `InService` del Auto Scaling Group;
4. ejecuta migraciones una sola vez;
5. actualiza una instancia a la vez mediante SSM;
6. valida `/health/ready` antes de continuar con la siguiente.

No se usa SSH. Los valores sensibles se resuelven en tiempo de ejecución mediante `asm-exec` y referencias dinámicas de Secrets Manager. Los despliegues de web también usan este mecanismo.

`main` y `prod` deben apuntar al mismo commit después de cada entrega a producción.

## Monitoreo y alertas

El dashboard `bigmelo-prod-operations` muestra tráfico, errores 5xx, capacidad, CPU, backlog de colas y métricas de PostgreSQL. Hay alarmas para:

- targets no saludables;
- menos de dos instancias disponibles;
- errores 5xx y latencia p99;
- CPU y espacio libre de RDS;
- antigüedad de jobs de chat y media;
- jobs en la DLQ de chat;
- health check HTTPS externo de `api.bigmelo.com`.

Los logs de API, workers y scheduler se envían a `/bigmelo/prod/api` con retención de 14 días. Las alarmas publican en el topic SNS `bigmelo-prod-operations`.

Pendiente operativo: confirmar el correo o canal de guardia que debe suscribirse al topic SNS. AWS exige confirmar la suscripción por parte del destinatario.

## Incidente de cargas multipart bloqueadas por AWS WAF

### Síntoma y diagnóstico

Entre el 1 y el 2 de septiembre de 2026, la edición de un producto desde `admin.bigmelo.com` fallaba al adjuntar una imagen y guardar su destino de WhatsApp. El navegador mostraba `TypeError: Failed to fetch`, aunque el número y el archivo cumplían las validaciones de la aplicación.

La investigación confirmó esta secuencia:

1. el preflight `OPTIONS` llegaba al API y respondía `204`;
2. el `POST multipart/form-data` no aparecía en los logs de Nginx ni de Laravel;
3. la métrica `AWS/WAFV2` registraba el bloqueo en la regla `BlockCrossSiteScriptingBody`;
4. la regla administrada `CrossSiteScripting_BODY` etiquetaba bytes del cuerpo multipart como XSS;
5. la regla personalizada convertía esa etiqueta en `BLOCK` antes de que la petición llegara al ALB;
6. la respuesta generada por WAF no incluía los encabezados CORS de Laravel, por lo que el navegador presentaba el bloqueo como `Failed to fetch` en lugar de mostrar el `403` real.

Durante los siete días revisados, WAF contó 11 coincidencias de `CrossSiteScripting_BODY` y bloqueó 9. También contó 153 coincidencias de `SizeRestrictions_BODY`. Esta última regla permanece en modo `COUNT`; los valores sirven como señal operativa y no son por sí mismos errores de la aplicación.

La excepción existente sólo cubría `POST /api/profile/{id}/appearance/background-image`. El administrador y la web usan el mismo formato para más operaciones, por lo que el problema también podía aparecer de manera intermitente al cambiar el archivo o el contenido inspeccionado.

### Superficies multipart revisadas

La lista administrada por infraestructura incluye únicamente estas rutas y mantiene una coincidencia exacta del método, la ruta y el tipo de contenido:

| Función | Ruta permitida |
| --- | --- |
| Generar avatar | `POST /api/avatar/generate` |
| Fuente de negocio | `POST /api/businesses/{business}/sources` |
| Fondo del perfil | `POST /api/profile/{profile}/appearance/background-image` |
| Audio inicial o sin respuesta | `POST /api/profile/{profile}/conversation-messages/{initial|fallback_no_answer}/audio` |
| Medio de OnlyFans | `POST /api/profile/{profile}/integrations/onlyfans/media` |
| Medio de integración Otro | `POST /api/profile/{profile}/integrations/other/media` |
| Audio de chat autenticado | `POST /api/profile/{profile}/messages/audio` |
| Crear producto | `POST /api/profile/{profile}/products` |
| Editar producto | `POST /api/profile/{profile}/products/{product}` |
| Previsualizar importación CSV | `POST /api/profile/{profile}/products/imports/preview` |
| CV o fuente del perfil | `POST /api/profile/{profile}/sources/cv` |
| Transcribir audio | `POST /api/profile/{profile}/transcriptions/audio` |
| Audio del perfil público | `POST /api/public/profiles/{profile}/messages/audio` |
| Muestra de voz | `POST /api/voice/{voice}/sample` |

Conectar OnlyFans, YouTube, Instagram o TikTok, sincronizar contenido y editar selecciones usa JSON u OAuth y no requiere esta excepción.

### Solución aplicada

`ApiMultipartUploadPathSet` es un `AWS::WAFv2::RegexPatternSet` que contiene las rutas anteriores. `BlockCrossSiteScriptingBody` omite su bloqueo adicional solamente cuando se cumplen las tres condiciones siguientes:

- método exactamente `POST`;
- URI incluida en `ApiMultipartUploadPathSet`;
- encabezado `Content-Type` que comienza por `multipart/form-data`.

La solución no desactiva AWS WAF ni permite rutas generales. Las reglas administradas, `AWSManagedKnownBadInputs`, el rate limit por IP, la autenticación, las abilities de Sanctum y las validaciones de tipo y tamaño del API continúan activas.

El primer change set de esta corrección declaró una expresión por operación y CloudFormation lo revirtió antes de modificar tráfico porque WAF limita cada `RegexPatternSet` a 10 expresiones. Las rutas relacionadas se agruparon sin ampliar segmentos ni métodos, dejando 9 expresiones. Este límite debe comprobarse al agregar cargas nuevas; cuando se alcance, se debe consolidar por prefijos exactos o crear otro pattern set, nunca reemplazar las rutas por un comodín general.

Para que un incidente futuro pueda atribuirse a una ruta concreta:

- WAF conserva durante 14 días sólo las peticiones bloqueadas en `aws-waf-logs-bigmelo-prod-api`;
- los encabezados `Authorization` y `Cookie` se redactan en los logs de WAF;
- el ALB guarda access logs cifrados con SSE-S3 en un bucket privado del stack;
- los objetos del access log del ALB expiran después de 30 días;
- la política del bucket limita la escritura al servicio de Elastic Load Balancing de esta cuenta y región.

### Regla obligatoria para nuevas cargas de archivos

Cada endpoint nuevo que use `FormData` o acepte `multipart/form-data` debe completar este checklist antes de llegar a producción:

1. declarar una ruta concreta; no usar comodines de varios segmentos;
2. mantener autenticación, autorización, validación MIME y límite de tamaño en Laravel;
3. agregar su expresión exacta a `ApiMultipartUploadPathSet` en `infra/cloudformation/bigmelo-prod-ha.yml`;
4. verificar que el pattern set no supere 10 expresiones y que cualquier agrupación mantenga segmentos exactos;
5. verificar que la excepción siga exigiendo `POST` y `multipart/form-data`;
6. validar el template con `cfn-lint` y `aws cloudformation validate-template`;
7. revisar un change set y confirmar que no reemplaza el ALB ni el Web ACL;
8. probar desde el dominio público con un archivo representativo para incluir CloudFront, WAF y ALB en la prueba;
9. confirmar que la petición llega al API y que no aumenta `BlockedRequests` para `BlockCrossSiteScriptingBody`;
10. revisar los logs de WAF y ALB si el navegador vuelve a mostrar `Failed to fetch`;
11. no establecer manualmente el `Content-Type` desde `fetch`; el navegador debe agregar el boundary multipart.

No se debe resolver este problema desactivando `CrossSiteScripting_BODY`, permitiendo todo `multipart/form-data` ni agregando CORS indiscriminadamente a respuestas de seguridad. La excepción debe permanecer limitada a las rutas de negocio auditadas.

## Capacidad y pruebas

Esta configuración establece disponibilidad y elasticidad, pero la capacidad contractual sólo se define con pruebas de carga sobre el sistema ya desplegado. El rango inicial de planeación es de 500 a 1.500 visitantes activos simultáneos, suponiendo que sólo una fracción envía mensajes al mismo tiempo. La capacidad real de respuestas de IA depende principalmente de latencia y límites de OpenAI, video y voz, además del tamaño de la base de datos.

Después del despliegue se deben ejecutar tres pruebas sin usar datos reales sensibles:

1. carga gradual de endpoints públicos y autenticados hasta hallar p95, p99 y punto de saturación;
2. ráfaga de mensajes para validar SQS, escalamiento, reintentos y DLQ;
3. simulación de pérdida de una instancia y restauración aislada de un snapshot de RDS.

El resultado de esas pruebas debe fijar SLO, límites de rate y umbrales finales. Objetivo recomendado: disponibilidad mensual del API de 99,9%, p95 menor a 500 ms para endpoints sin proveedor externo y cero jobs normales en DLQ.

## Costo mensual estimado

Estimación para `us-east-1`, 730 horas, tráfico todavía bajo y dos instancias base:

| Componente | Estimado mensual USD |
| --- | ---: |
| 2 EC2 `t4g.small` | 24-25 |
| EBS gp3, 2 x 30 GiB | 5 |
| ALB y LCU de tráfico bajo | 17-23 |
| Direcciones IPv4 públicas de ALB e instancias | 14-15 |
| RDS `db.t4g.micro` Multi-AZ y 20 GiB | 27-32 |
| WAF | 7-10 |
| CloudWatch, logs, dashboard, alarmas y health check | 8-13 |
| SQS, SNS, Route 53, Secrets Manager, S3 y CloudFront con tráfico bajo | 7-15 |
| **Total base esperado** | **120-145** |

Cada instancia adicional sostenida todo el mes agrega aproximadamente USD 20-23 entre EC2, EBS, IPv4 y monitoreo. Si el grupo permaneciera en 6 instancias durante todo el mes, el total aproximado subiría a USD 205-235. El uso de OpenAI, Runway, ElevenLabs, impuestos, transferencias extraordinarias y crecimiento fuerte de CloudFront no está incluido.

Queda creado el AWS Budget `bigmelo-prod-monthly` de USD 150. En cuanto se confirme el correo o canal de guardia, se deben añadir avisos al 80% y 100% y detección de anomalías. Cuando la carga sea estable, se puede evaluar Savings Plans para la base mínima de dos instancias; no se debe comprometer capacidad antes de medir al menos 30 días.

## Recuperación y operación

- RPO esperado de base de datos: hasta 5 minutos con point-in-time recovery dentro de la retención configurada.
- RTO orientativo de una instancia: 5 a 10 minutos por reemplazo del Auto Scaling Group.
- RTO orientativo de failover RDS Multi-AZ: normalmente minutos; debe medirse.
- Imágenes de aplicación: ECR conserva tags de commit para rollback.
- Infraestructura: rollback mediante change set de CloudFormation, sin editar recursos manualmente.

Antes de un cambio de tamaño, migración de motor o restauración, crear y revisar un change set. Nunca recuperar secretos con `get-secret-value`; usar las referencias dinámicas y `asm-exec` documentadas en los scripts de despliegue.
