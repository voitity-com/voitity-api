# Profile Insights: implementación y evolución

Este documento describe cómo se construyen los reportes de Insights por perfil, cómo se preserva el histórico y qué optimizaciones quedan deliberadamente pendientes. Las definiciones exactas de cada evento y métrica están en [`INSIGHTS_METRICS.md`](INSIGHTS_METRICS.md).

## Experiencia del administrador

`/dashboard/profiles/{profile}/insights` contiene tres pestañas y comparte el mismo filtro de fechas y zona horaria:

1. **Dashboard**: la vista ejecutiva elegida como V1. Incluye los diez KPI principales, la tendencia, el embudo por proveedor y el resumen de objetivos.
2. **Chats**: total/estado/duración/mensajes por chat, cobertura y confianza de la clasificación, objetivo principal, evolución por objetivo y chats que después hicieron clic en productos, WhatsApp, redes o contenido externo.
3. **Productos**: tarjetas mostradas, clics, CTR, visitantes con clic, chats alcanzados, superficie del clic, destino y objetivos de los chats que hicieron clic en cada producto.

La pestaña Productos sólo aparece cuando la funcionalidad está habilitada y existe al menos un producto publicado o actividad histórica. Por eso continúa visible en modo `historical_only` si todos los productos que generaron métricas fueron despublicados o eliminados.

## Endpoints y autorización

- `GET /api/profile/{profile}/insights/dashboard`
- `GET /api/profile/{profile}/insights/chats`
- `GET /api/profile/{profile}/insights/products`
- `GET /api/profile/{profile}/insights` se conserva como alias compatible de Dashboard.

Todos requieren Sanctum, capacidad `insights:read` y que el usuario sea propietario del perfil o administrador. Aceptan `from`, `to`, `timezone` y `group_by=day|month`; el rango predeterminado es el último mes calendario y el máximo es 24 meses.

## Flujo de datos

```text
perfil público / respuesta del chat
            |
            +-- chats + messages -------------> Dashboard / Chats
            +-- chat_analyses ----------------> objetivos y confianza
            +-- profile_interaction_events ---> visitantes, medios, redes y productos
                                                      |
                                                      +-- snapshot del producto
```

Las impresiones de medios y productos se crean en el servidor al persistir una respuesta. Los clics se reciben en el endpoint público con una clave idempotente. El servidor valida que el recurso pertenezca al perfil y, para productos, ignora datos descriptivos enviados por el navegador: toma su propio snapshot autoritativo.

Los reportes no cargan eventos ni mensajes completos en PHP. `ProfileInsightsReportService` ejecuta `COUNT`, `SUM(CASE...)`, `COUNT(DISTINCT...)`, `AVG` y agrupaciones temporales en la base de datos. Cada pestaña consulta únicamente los agregados que necesita. Los índices cubren perfil+rango y los cruces por tipo, chat, visitante y producto.

Cada respuesta incluye `definitions_version=v2`, el rango efectivo, el inicio conocido del tracking y disponibilidad de pestañas. El controlador registra perfil, usuario, sección, rango, zona horaria, agrupación, duración y cantidad de filas del resultado. Si la consulta supera `INSIGHTS_QUERY_WARN_MS` (500 ms por defecto), el mismo log sube a nivel `warning`.

## Categorías de objetivo de conversación

La clasificación representa el **objetivo principal de la conversación completa**, no el tema de un mensaje aislado. Se ejecuta después de cerrar el chat y produce una categoría principal, categorías secundarias opcionales, un nivel de confianza, un resumen breve y los mensajes que respaldan la decisión. Un resultado de baja confianza se marca como `needs_review`.

### Irrelevante o spam (`irrelevant_or_spam`)

Conversaciones que no contienen una intención útil o relacionada con el perfil. Incluye pruebas sin contenido real, texto aleatorio o incomprensible, publicidad no solicitada, enlaces maliciosos, automatizaciones, mensajes repetitivos, abuso y contenido claramente ajeno.

- Ejemplos: “asdfgh”, una promoción masiva repetida o un bot que publica enlaces sin relación.
- No se debe usar sólo porque el usuario escribió poco. Un saludo legítimo o una conversación ambigua corresponde a **Otro o no concluyente** si no hay evidencia de spam.

### Descubrimiento del perfil (`profile_discovery`)

El visitante intenta conocer a la persona, marca o creador detrás del perfil: quién es, qué hace, su trayectoria, experiencia, proyectos, habilidades o información biográfica disponible. El interés principal es informativo y todavía no expresa una acción comercial o social concreta.

- Ejemplos: “¿A qué te dedicas?”, “¿Qué experiencia tienes?” o “Cuéntame sobre tus proyectos”.
- Si la conversación pasa a contratar, patrocinar o colaborar, el objetivo principal puede ser **Oportunidad comercial**.

### Interacción social (`social_engagement`)

El objetivo es encontrar, ver, seguir o interactuar con el contenido y los canales sociales del perfil. Incluye preguntas sobre Instagram, TikTok, OnlyFans u otras redes, publicaciones, fotos, videos, comunidad o formas de seguir al creador.

- Ejemplos: “¿Cuál es tu Instagram?”, “¿Dónde puedo ver tus videos?” o “¿Cómo te sigo en TikTok?”.
- Ver una publicación o abrir una red es distinto de interesarse por un producto. Si la conversación se concentra en características o compra de un artículo, se usa **Interés en producto** o **Intención de compra**.

### Interés en producto (`product_interest`)

El visitante está explorando o evaluando productos, pero todavía no demuestra una decisión inmediata de comprar. Incluye preguntas sobre características, beneficios, variantes, compatibilidad, comparaciones, recomendaciones o cuál producto se ajusta mejor a una necesidad.

- Ejemplos: “¿Qué incluye este producto?”, “¿Cuál me recomiendas?” o “¿Qué diferencia hay entre estos dos?”.
- Una pregunta general no equivale a una venta. Cuando aparecen precio, disponibilidad, envío, pago, pedido o una solicitud explícita de compra, corresponde **Intención de compra**.

### Intención de compra (`purchase_intent`)

Existe evidencia clara de que el visitante quiere avanzar hacia la adquisición de un producto o servicio. Incluye consultas sobre precio, disponibilidad, entrega, métodos de pago, proceso de pedido, enlace de compra o contacto para cerrar la operación.

- Ejemplos: “¿Cuánto cuesta y cómo lo compro?”, “¿Tienes disponibilidad?” o “Envíame el enlace para pagar”.
- Esta categoría mide intención, no una compra completada. Para afirmar una conversión se necesita un evento transaccional independiente que confirme el pago o la orden.

### Oportunidad comercial (`business_opportunity`)

El visitante propone o explora una relación profesional o de negocio distinta de comprar un producto como consumidor. Incluye contratación, reservas, representación, alianzas, patrocinios, afiliaciones, entrevistas, campañas y colaboraciones.

- Ejemplos: “Quiero contratarte para un evento”, “¿Hacemos una colaboración?” o “Nos interesa patrocinar tu contenido”.
- Si sólo desea adquirir un producto o servicio publicado, se clasifica como **Intención de compra**. La oportunidad comercial supone una relación profesional, negociación o colaboración más amplia.

### Soporte o reclamo (`support_or_complaint`)

El visitante necesita resolver un problema, solicita ayuda o expresa inconformidad. Puede ocurrir antes o después de una compra e incluye fallos de acceso, pago, entrega, enlaces, uso del producto, solicitudes de devolución y reclamos sobre la experiencia.

- Ejemplos: “Pagué y no tengo acceso”, “El enlace no funciona”, “Mi pedido no llegó” o “Quiero solicitar un reembolso”.
- Una pregunta informativa sobre cómo funciona un producto, sin un problema concreto, pertenece normalmente a **Interés en producto**.

### Otro o no concluyente (`other_or_unclear`)

Conversaciones legítimas para las que no existe evidencia suficiente para determinar uno de los objetivos anteriores, o cuyo propósito principal no encaja en la taxonomía actual. Es una salida válida para evitar forzar una clasificación incorrecta.

- Ejemplos: un saludo sin continuación, una charla general o varios objetivos posibles sin evidencia para elegir uno.
- No significa spam ni falta de valor. Un volumen alto y sostenido en esta categoría indica que conviene revisar ejemplos reales y evaluar una nueva categoría, sin cambiar retroactivamente el significado de las existentes.

### Criterio para conversaciones con varios objetivos

La categoría principal debe reflejar la intención dominante respaldada por la conversación. Por ejemplo, si alguien primero pregunta por las características de un producto y después solicita el enlace de pago, el resultado principal es **Intención de compra** y **Interés en producto** puede conservarse como categoría secundaria. Si la evidencia es insuficiente o dos objetivos quedan empatados, se prefiere **Otro o no concluyente** y/o `needs_review` antes que inventar precisión.

## Histórico de productos

`product_shown` y `product_clicked` guardan, junto con el ID interno, los siguientes datos del momento del evento:

- `subject_public_id`: identidad estable usada para agrupar;
- `subject_name` e `subject_image_url`;
- `subject_status`;
- `destination_type`.

El reporte agrupa por `subject_public_id` y usa la fila actual cuando todavía existe. Si fue despublicada muestra el estado actual; si fue eliminada usa el snapshot y estado `deleted`. No depende de un `JOIN` obligatorio con `profile_products`, de modo que eliminar el producto no elimina ni vuelve anónimas sus métricas. No se inventan impresiones anteriores al inicio de `product_shown`.

## Conciliación

La fuente de verdad es PostgreSQL y cada métrica usa su propio timestamp:

- chat nuevo: `chats.started_at`;
- mensaje: `messages.created_at`;
- interacción: `profile_interaction_events.occurred_at`;
- objetivo: chat cuyo `started_at` cae en el rango.

Un chat puede contener muchos mensajes; una impresión no implica apertura ni clic; el clic al producto, el clic en su imagen/botón y el destino WhatsApp/página son dimensiones separadas. Las pruebas de feature crean datos controlados y verifican los valores del JSON. El seeder local crea además un producto eliminado para comprobar el histórico en la UI.

## Optimizaciones futuras y cuándo aplicarlas

La implementación actual es adecuada mientras las agregaciones indexadas cumplen el objetivo de latencia. No conviene añadir infraestructura de resumen antes de observar carga real. Se debe revisar el siguiente escalón cuando el percentil 95 de un endpoint exceda 500 ms durante una semana, cuando un perfil supere aproximadamente un millón de eventos en el rango habitual, o cuando Insights produzca presión apreciable de CPU/IO en la base primaria.

1. **Caché corta por perfil/sección/rango** (primera opción): 1–5 minutos, invalidada o versionada por nuevas interacciones. Aplicar cuando haya filtros repetidos y el resultado no necesite tiempo real absoluto.
2. **Tabla diaria de agregados**: contadores por perfil, fecha, proveedor, objetivo y producto; mantener los eventos crudos como auditoría. Aplicar cuando la lectura histórica sea dominante o P95 siga alto con caché.
3. **Réplica de lectura o almacén analítico**: sacar consultas de la base transaccional. Aplicar cuando Insights compita con chat/escrituras o se necesiten rangos largos para muchos perfiles.
4. **Particionado mensual de eventos**: aplicar cuando mantenimiento, vacuum o índices de `profile_interaction_events` se vuelvan pesados (decenas de millones de filas) y exista una política clara de retención.
5. **Estimaciones de cardinalidad (por ejemplo HLL)**: sólo si `COUNT(DISTINCT visitor_id_hash)` es el cuello de botella y el producto acepta valores aproximados explícitamente etiquetados.

Antes de cada optimización se deben capturar `EXPLAIN (ANALYZE, BUFFERS)`, P50/P95/P99, filas por perfil/rango y tasa de escrituras. Las tablas resumidas deben conciliarse periódicamente contra eventos crudos y versionar las definiciones; nunca deben cambiar silenciosamente el significado de una métrica existente.
