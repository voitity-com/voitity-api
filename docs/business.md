# Business: chatbot guiado, leads y widget

## Objetivo

Business permite que un administrador cree chatbots que siguen un flow explícito. El motor no decide libremente qué acción ejecutar: solo avanza por bloques publicados de indicación, decisión y acción. Cada conversación queda fijada a una versión del flow, registra su consumo y puede terminar creando un lead y enviando notificaciones.

El módulo está protegido por dos condiciones simultáneas:

1. El feature flag global `business` debe estar activo en **Nuevas funcionalidades**.
2. El usuario administrativo debe tener rol `admin` y la ability requerida por el endpoint.

Los perfiles y usuarios normales no reciben abilities de Business.

## Arquitectura

```mermaid
flowchart LR
    Admin["Admin web"] -->|Sanctum + abilities| AdminAPI["Business Admin API"]
    AdminAPI --> DB[("Tablas business_*")]
    AdminAPI --> Sources["Cola de indexación de fuentes"]
    Sources --> Embeddings["Embeddings + pgvector"]
    AdminAPI --> Versions["Borrador y versiones publicadas"]

    Host["Sitio permitido"] --> Widget["Business widget en Shadow DOM"]
    Widget -->|Key + Origin + Session| Runtime["Business Runtime API"]
    Runtime --> Runner["BusinessFlowRunner"]
    Runner --> AI["BusinessFlowAI"]
    Runner --> DecisionAI["BusinessDecisionAI"]
    Runner --> Retrieval["Recuperación híbrida semántica + lexical"]
    Retrieval --> Embeddings
    Runner --> Actions["Acciones idempotentes"]
    Runner --> DB
    Actions --> Leads["Lead"]
    Actions --> Mail["Correo al negocio y visitante"]
```

### Capas del backend

- `app/Http/Controllers/api/v1/Business*`: endpoints administrativos.
- `PublicBusinessChatController`: contrato público del chatbot.
- `AuthenticateBusinessClient`: valida feature, estado, key, expiración y origen exacto; también genera CORS dinámico.
- `BusinessFlowService`: inicializa, guarda, valida y publica versiones.
- `BusinessFlowValidator`: verifica un único inicio, tipos válidos, salidas, ramas y nodos alcanzables.
- `BusinessFlowRunner`: ejecuta el grafo, limita pasos y fija cada conversación a una versión publicada.
- `BusinessLocalization`: resuelve `es`/`en`, mensajes por nodo, etiquetas de campos y textos dinámicos sin obligar al frontend a traducir respuestas.
- `BusinessFlowAI`: interfaz desacoplada para extracción de datos y análisis de la posible solución. El driver local permite pruebas deterministas sin servicios externos.
- `BusinessKnowledgeIndexer`: divide cada fuente, calcula embeddings por lotes y guarda chunks versionados por hash. La indexación se ejecuta en cola y deja la fuente en `processing`, `indexed` o `failed`.
- `BusinessKnowledgeRetriever`: hace búsqueda híbrida. Combina similitud vectorial, coincidencia lexical y límites de relevancia, cantidad de chunks y contexto antes de consultar la IA.
- `BusinessDecisionAI`: responde exclusivamente `yes` o `no` a la pregunta escrita en el nodo; recibe descripción, problema, mensajes recientes y solo los chunks recuperados. También devuelve confianza, motivo e IDs de chunks utilizados.
- `BusinessLeadService`: crea el lead y ejecuta notificaciones idempotentes.
- `BusinessUsageRecorder`: registra tokens de fuentes, mensajes, recuperación, decisiones, extracción y análisis.

## Modelo de datos

Todas las tablas son propias del módulo:

| Tabla | Responsabilidad |
| --- | --- |
| `businesses` | Identidad y estado `draft`, `active` o `paused`. |
| `business_settings` | Correos, locale y apariencia del widget. |
| `business_api_clients` | Key pública almacenada únicamente como SHA-256, expiración y uso. |
| `business_api_client_origins` | Orígenes HTTP/HTTPS exactos permitidos. |
| `business_sources` | Archivo/texto extraído y estado de indexación. |
| `business_knowledge_chunks` | Fragmentos, hash, embedding vectorial, modelo, dimensiones, estado y metadatos de recuperación. En PostgreSQL usa `pgvector` e índice HNSW. |
| `business_flows` | Punteros al borrador y a la versión publicada. |
| `business_flow_versions` | Versiones inmutables publicadas y borrador editable. |
| `business_flow_nodes` | Bloques, posiciones y configuración. |
| `business_flow_edges` | Flechas, rama y destino. |
| `business_conversations` | Estado, idioma activo, versión fijada, nodo actual y contexto. |
| `business_messages` | Mensajes del visitante/asistente con tokens. |
| `business_node_executions` | Auditoría de cada bloque ejecutado. |
| `business_leads` | Nombre, email, teléfono, WhatsApp, empresa/sitio opcionales, problema y análisis interno de posible solución. |
| `business_lead_status_histories` | Creación y cambios de estado del lead, usuario responsable y observaciones. |
| `business_usage_events` | Consumo granular por tipo de evento. |
| `business_action_runs` | Idempotencia, intentos y errores de acciones. |

## Interfaz administrativa

Cuando el toggle está activo, el menú **Business** aparece inmediatamente después de **Perfiles** solo para administradores.

### Listado

Muestra nombre, estado y última actualización. **Agregar** solicita nombre y descripción y crea automáticamente el flow de ejemplo como borrador.

### Submenú

- **General**: edita nombre y descripción.
- **Fuentes**: conserva el mismo patrón visual de Perfiles. El alta abre un modal para cargar PDF, TXT, Markdown, CSV, JSON o texto pegado. El texto pegado se persiste además como `.txt`, con nombre seguro derivado del nombre de la fuente. La fuente queda en procesamiento mientras una tarea genera sus chunks y embeddings; la tabla se actualiza automáticamente hasta `indexed` o `failed`. Permite descargar tanto archivos subidos como fuentes de texto y exige confirmación antes de eliminar una fuente y sus chunks. Las fuentes de texto históricas sin archivo físico se descargan directamente desde `extracted_text`.
- **Flow**: canvas gráfico editable.
- **Leads**: tabla compacta con contacto, empresa, estado, creación y actualización. Filtra por rango sobre la fecha de creación o actualización y por varios estados (`created`, `contacted`, `sale`, `no_response`, `closed`). Al abrir un lead, el modal separa toda la información y el historial desde la creación; cada cambio de estado exige confirmación y admite observaciones.
- **Uso**: tokens, fuentes, mensajes, conversaciones, leads, conversaciones sin lead y desglose de eventos. Incluye rango `Desde`/`Hasta`, carga por defecto el último mes calendario y conserva el rango en la URL.
- **Configuración**: se organiza en las pestañas **Correo**, **Widget** y **API**. Correo contiene receptor/remitente, Widget contiene apariencia y activación, y API administra keys y orígenes.
- **Docs**: documentación integrada del runtime.

El botón superior **Activar business** publica el chatbot para el runtime. La activación exige una versión válida publicada; pausar bloquea nuevas llamadas públicas.

El menú lateral, su estado seleccionado, colores, iconos, ancho, separación, comportamiento sticky y selector móvil reutilizan la organización visual de las secciones de Perfil. Los títulos de página usan `h4` y texto descriptivo como Configuración e Insights de Perfil.

## Editor del flow

El canvas usa HTML, pointer events y SVG, sin una dependencia remota. Se puede navegar horizontal y verticalmente arrastrando con el mouse cualquier zona vacía del fondo; durante el paneo el cursor cambia de mano abierta a mano cerrada. Las barras de scroll nativas continúan disponibles. El gesto no se activa al presionar un nodo, de modo que el drag de bloques conserva su comportamiento independiente. El plano se recalcula y expande en las cuatro direcciones cuando un bloque se mueve, incluyendo coordenadas negativas, por lo que no existe un límite funcional fijo de navegación.

Cada bloque tiene:

- identificador estable;
- tipo `instruction`, `decision` o `action`;
- título;
- posición X/Y;
- configuración JSON tipada por el runtime.

Las flechas guardan bloque origen, destino, etiqueta y `source_handle`. En decisiones, `source_handle` identifica la rama. El inspector permite editar el mensaje en español y en inglés, espera de input, modo de decisión, acción, nodo inicial, conexiones y eliminación. Para compatibilidad, `config.message` conserva el español y `config.messages.es|en` es el contrato localizado.

Un nodo de decisión ofrece dos modos definidos y comprensibles, sin identificadores internos creados manualmente:

- **Pregunta Sí/No con IA y fuentes**: el usuario escribe la pregunta en español e inglés y elige si la evaluación puede usar la descripción del Business y las fuentes indexadas. Sus ramas son fijas: `yes` y `no`.
- **Validar campos obligatorios**: comprueba de forma determinista los campos indicados. Sus ramas son fijas: `complete` e `incomplete`.

El validador impide publicar una pregunta vacía, ramas diferentes de las esperadas, dos flechas para la misma rama, una rama sin destino o nodos inalcanzables. Al cambiar de modo en el editor, se eliminan las conexiones salientes incompatibles para que el usuario vuelva a conectar los destinos correctos.

Ejemplo de configuración persistida para una decisión basada en conocimiento:

```json
{
  "mode": "knowledge_yes_no",
  "question": "¿El problema descrito corresponde a un producto, servicio o problema que este negocio puede solucionar?",
  "questions": {
    "es": "¿El problema descrito corresponde a un producto, servicio o problema que este negocio puede solucionar?",
    "en": "Does the described problem match a product, service, or problem that this business can solve?"
  },
  "use_business_description": true,
  "use_sources": true,
  "branches": ["yes", "no"]
}
```

### Flow inicial

```mermaid
flowchart LR
    Greeting["Indicación: saludar y pedir nombre"] --> Name["Acción: guardar nombre"]
    Name --> Welcome["Indicación: preguntar la necesidad"]
    Welcome --> Qualify{"Decisión: pregunta Sí/No con descripción y fuentes"}
    Qualify -->|no| Redirect["Indicación: orientar al usuario"]
    Redirect --> Qualify
    Qualify -->|yes| Capture["Acción: guardar el problema descrito"]
    Capture --> ProblemComplete{"Decisión: problema completo"}
    ProblemComplete -->|incomplete| Clarify["Indicación: profundizar problema"]
    Clarify --> Capture
    ProblemComplete -->|complete| Ask["Indicación: solicitar datos"]
    Ask --> Extract["Acción: extraer datos"]
    Extract --> Complete{"Decisión: datos completos"}
    Complete -->|incomplete| Missing["Indicación: pedir datos faltantes"]
    Missing --> Extract
    Complete -->|complete| Analyze["Acción interna: plantear posible solución con IA"]
    Analyze --> Closing["Indicación: análisis y prototipo en 2 semanas"]
    Closing --> Finalize["Acción: crear lead, enviar correos y finalizar"]
```

La publicación valida todo el grafo y crea automáticamente un nuevo borrador basado en la versión publicada. Las conversaciones que ya comenzaron continúan sobre la versión anterior.

### Cómo se resuelve una pregunta Sí/No

1. El runner arma una consulta con el problema guardado y los mensajes recientes del visitante.
2. Se genera un embedding de esa consulta y se buscan candidatos entre chunks activos del mismo Business.
3. Se combinan la similitud semántica y la lexical; se descartan resultados por debajo del umbral y se limita el contexto enviado.
4. La IA recibe la pregunta exacta del nodo, la descripción habilitada, el problema, los mensajes recientes y los fragmentos relevantes. Las fuentes completas nunca se envían indiscriminadamente.
5. La salida estructurada obliga a devolver `answer`, `confidence`, `reason` y `source_chunk_ids`. Una respuesta inválida, un error o confianza insuficiente toma la rama segura `no`.
6. La ejecución del nodo registra la rama tomada, confianza y chunks usados; luego avanza por la flecha `yes` o `no`.

Las decisiones heredadas `technology_interest` siguen siendo legibles para conversaciones fijadas a versiones antiguas, pero el editor no permite crear nuevas y el comando de actualización las migra a `knowledge_yes_no`.

La plantilla base contiene 13 nodos y 15 flechas. El flow publicado del negocio de demostración agrega la bienvenida y captura separada de nombre, para un total de 15 nodos y 17 flechas. Solo permite finalizar cuando existen todos estos valores válidos:

- problema del cliente de al menos 20 caracteres;
- nombre y apellido;
- email válido;
- teléfono en formato internacional con `+` e indicativo de país;
- WhatsApp en formato internacional con `+` e indicativo de país;
- posible solución interna ya generada por la IA.

Empresa y sitio web son opcionales. La acción `analyze_solution` está marcada como interna: guarda el análisis en el contexto y en el lead, pero no produce un mensaje visible para el visitante.

## Seguridad del runtime

1. Al crear una key se devuelve el valor `biz_pk_...` una sola vez.
2. La base de datos conserva solo `hash('sha256', key)` y un prefijo identificable.
3. Cada key exige al menos un origen HTTP/HTTPS exacto, por ejemplo `http://localhost:3001`.
4. El middleware exige `Origin` y responde CORS únicamente si ese origen está permitido.
5. El inicio devuelve una session cifrada ligada a negocio, cliente, conversación, origen y expiración.
6. Mensajes y status exigen la session en `X-Bigmelo-Business-Session`.
7. `Idempotency-Key` evita guardar dos veces el mismo mensaje del visitante.
8. Las acciones finales tienen una clave de idempotencia por conversación, nodo y operación.

## Runtime API

Base local por defecto: `http://localhost:8000`.

Headers de todas las llamadas:

```http
Origin: http://localhost:3001
X-Bigmelo-Business-Key: biz_pk_...
Accept: application/json
Content-Type: application/json
```

### Configuración pública del widget

```http
GET /api/business/widget
```

Devuelve título, botón, color, posición, locale y nombre del negocio. Devuelve 404 si el widget no está activo.

### Iniciar conversación

```http
POST /api/business/conversations

{"visitor_id":"visitor-123","locale":"en"}
```

Respuesta relevante:

```json
{
  "data": {
    "conversation_id": "uuid",
    "status": "in_progress",
    "locale": "en",
    "session": "encrypted-token",
    "messages": [
      {
        "role": "assistant",
        "content": "Hello! I am the BIGMELOlabs bot. What is your name?",
        "locale": "en",
        "required_fields": ["full_name"],
        "fields": [
          {"key":"full_name","label":"Full name","type":"text","required":true}
        ]
      }
    ]
  }
}
```

El integrador debe conservar `conversation_id` y `session`. `locale` acepta `es` o `en`, queda persistido en la conversación y se devuelve a nivel de `data` y de cada mensaje. `data.messages` contiene las indicaciones iniciales del asistente generadas al arrancar el flow.

Cuando una indicación espera campos concretos, el mensaje incluye tres representaciones complementarias:

- `required_fields`: keys estables todavía obligatorias;
- `optional_fields`: keys que deben mostrarse, pero no bloquean el avance;
- `fields`: definiciones listas para renderizar con `key`, `label` traducido, `type` HTML y `required`.

El frontend debe construir el formulario desde `fields`, no analizar el texto de `content` ni mantener traducciones duplicadas.

### Enviar mensaje

```http
POST /api/business/conversations/{conversation_id}/messages
X-Bigmelo-Business-Session: encrypted-token
Idempotency-Key: request-uuid

{
  "locale": "en",
  "fields": {
    "project_summary": "We need a chatbot that answers from our knowledge base and captures qualified leads."
  }
}
```

La respuesta incluye únicamente los mensajes del asistente producidos durante ese avance. No contiene el historial completo y el arreglo puede traer uno o varios elementos:

```json
{
  "message": "Business message processed successfully.",
  "data": {
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "in_progress",
    "locale": "en",
    "finished": false,
    "messages": [
      {
        "id": 104,
        "role": "assistant",
        "content": "Great! To continue, please provide: full name, valid email, phone with country code and WhatsApp with country code. You may also provide company and website; these fields are optional.",
        "locale": "en",
        "created_at": "2026-08-20T15:32:01.000000Z",
        "required_fields": ["full_name", "email", "phone", "whatsapp"],
        "optional_fields": ["company", "website"],
        "fields": [
          {"key":"full_name","label":"Full name","type":"text","required":true},
          {"key":"email","label":"Email","type":"email","required":true},
          {"key":"phone","label":"Phone with country code","type":"tel","required":true},
          {"key":"whatsapp","label":"WhatsApp with country code","type":"tel","required":true},
          {"key":"company","label":"Company","type":"text","required":false},
          {"key":"website","label":"Website","type":"url","required":false}
        ]
      }
    ]
  }
}
```

La interfaz cliente agrega esos elementos al chat en el orden recibido y conserva localmente los mensajes anteriores. Puede enviar texto libre en `message` o valores de formulario en `fields`; al menos uno de ambos es obligatorio. Las keys admitidas son `full_name`, `email`, `phone`, `whatsapp`, `company`, `website` y `project_summary`. Los campos estructurados se validan y normalizan en el backend, por lo que no dependen del idioma de sus etiquetas.

Debe enviarse `locale` también en cada turno. Si el usuario cambia el selector, el siguiente request usa el nuevo valor y todas las indicaciones y labels posteriores se devuelven en ese idioma, sin crear otra conversación. El integrador debe dejar de pedir input cuando `finished` sea `true` o el estado deje de ser `in_progress`.

Una secuencia típica contiene varias llamadas al mismo endpoint:

1. `{"locale":"es","fields":{"full_name":"Laura Gómez"}}` devuelve la indicación para describir el problema.
2. `{"locale":"es","fields":{"project_summary":"Procesamos facturas manualmente y queremos extraer y validar sus datos con IA."}}` devuelve la solicitud de contacto.
3. Una respuesta estructurada con datos incompletos devuelve exactamente los campos faltantes, con labels en español.
4. Si el siguiente request usa `"locale":"en"`, la indicación de faltantes y sus labels llegan en inglés.
5. Al completar los datos devuelve `status: "completed"`, `finished: true` y el mensaje final del asistente en el idioma activo.

La posible solución generada por IA es interna: se guarda en el lead y se envía al negocio, pero nunca aparece en `data.messages` para el visitante.

### Consultar estado

```http
GET /api/business/conversations/{conversation_id}/status
X-Bigmelo-Business-Session: encrypted-token
```

Estados: `in_progress`, `completed`, `abandoned` y `failed`.

El response de status incluye `locale`, `current_node`, `started_at` y `completed_at`. No contiene `messages` y no permite recuperar el historial completo.

## Widget incluido

El build web genera `widget/business-v1.js`. El snippet se muestra en Configuración inmediatamente después de crear una key:

```html
<script
  src="http://localhost:3001/widget/business-v1.js"
  data-bigmelo-business="biz_pk_..."
  data-bigmelo-api="http://localhost:8000"
></script>
```

El widget:

- usa Shadow DOM;
- no modifica CSS del sitio anfitrión;
- respeta color, título, texto y posición configurados;
- es responsive y accesible por teclado;
- conserva un visitor ID local sin guardar la key;
- envía el `locale` configurado al iniciar y en cada mensaje;
- maneja session, idempotencia, errores y finalización;
- al finalizar oculta el compositor y muestra un estado explícito de conversación finalizada;
- se sirve como script clásico tanto en desarrollo como en el build de producción.

## Correos y leads

`analyze_solution` genera primero el análisis interno y `finalize_lead` crea el lead únicamente si el problema, el análisis y todos los datos obligatorios son válidos. Si están configurados:

- `lead_recipient_email` recibe nombre, email, teléfono, WhatsApp, empresa, sitio web, problema y posible solución planteada por la IA;
- el email extraído del visitante recibe confirmación;
- `sender_email`, `sender_name` y `reply_to_email` se aplican a ambos correos.

La posible solución nunca se incluye en la conversación ni en el correo de confirmación enviado al visitante.

Las notificaciones usan la cola de Laravel. En desarrollo local debe existir un worker para procesarlas.

## Observabilidad y logs

La implementación registra eventos estructurados sin guardar en el log el texto completo del problema ni de las fuentes:

- inicio, terminación y fallo de indexación, con Business, fuente, modelo, dimensiones, chunks y tokens;
- recuperación de conocimiento, con hash de la consulta, IDs de fuentes/chunks, puntajes, tokens de contexto y latencia;
- evaluación de cada pregunta, con nodo, rama, respuesta, confianza, proveedor/modelo e IDs de chunks recuperados y citados;
- respuesta inválida o error del proveedor, tomando la rama segura `no`;
- eventos de uso separados para `source_indexed`, `source_retrieval` y `flow_decision`.

Esto permite reconstruir por qué el flow tomó una rama desde `business_node_executions` y `business_usage_events`, sin exponer datos personales en los logs de aplicación.

## Pruebas

### Matriz funcional del flow publicado

| Escenario | Entrada representativa | Respuesta o resultado esperado |
| --- | --- | --- |
| Nombre | `Peter Parker` | Solicitar `project_summary`. |
| Necesidad tecnológica demasiado breve | `Quiero un chatbot` | Pedir de inmediato situación/proceso, usuarios y resultado esperado; no solicitar todavía contacto. |
| Chatbot suficientemente descrito | `Necesito un chatbot para recibir pedidos, consultar inventario y derivar conversaciones` | Solicitar Email, Teléfono y WhatsApp; mostrar Empresa y Sitio web como opcionales si el nombre ya fue capturado. |
| Software, IA, datos o cloud suficientemente descritos | Descripción concreta del proceso y resultado | Avanzar a contacto con `required_fields` y `optional_fields`. |
| Solicitud no tecnológica | `Quiero organizar una fiesta` | Explicar el alcance tecnológico y permitir que el visitante reformule. |
| Recuperación después de una solicitud no tecnológica | Una nueva descripción tecnológica | Retomar el flow, guardar esa descripción y solicitar contacto. |
| Contacto parcial por API | Faltan uno o más obligatorios | Responder solamente con los `required_fields` pendientes; mostrar recordatorio de indicativo únicamente si falta teléfono o WhatsApp. |
| Empresa y sitio web vacíos | Todos los obligatorios válidos | Finalizar y crear el lead con ambos opcionales en `null`. |
| Empresa y sitio web diligenciados | Todos los obligatorios y opcionales | Finalizar y guardar ambos valores en el lead. |
| Solución interna para chatbot | Problema menciona chatbot y datos de contacto | Priorizar una solución de asistente conversacional; no confundirla con un proyecto de arquitectura de datos. |
| Conversación en inglés | Iniciar con `locale: en` | Todo `content` y `fields[].label` se devuelve en inglés. |
| Cambio de idioma | Iniciar en español y enviar el siguiente turno con `locale: en` | Conservar conversación/contexto y responder desde ese turno en inglés. |
| Formulario estructurado | Enviar `fields` sin `message` | Validar por key estable, guardar los valores y avanzar sin depender del texto traducido. |

La posible solución permanece interna en todos los escenarios: se almacena en el lead y se incluye en la notificación al negocio, pero no se devuelve al visitante.

- Unitarias: validador del grafo y de sus ramas fijas, chunking con solapamiento, contrato estructurado de la decisión IA, mensajes localizados, extracción/normalización de teléfono y WhatsApp, separación entre problema y datos de contacto, y heurísticas deterministas en español e inglés.
- Feature API: feature toggle, rol, listado, creación, Fuentes, embeddings, recuperación vectorial, decisión apoyada por fuentes, migración de nodos heredados, descarga/eliminación de archivos, descarga `.txt` de texto nuevo e histórico, aislamiento de fuentes entre negocios, publicación, activación, hash de keys, CORS exacto, origen bloqueado, conversación incompleta/completa, locale inicial, cambio de idioma, campos estructurados/localizados, lead, solución interna, correos, status, rango de Uso y validación de fechas.
- Build: TypeScript, ESLint, Vite admin y Vite web.
- Funcionales/visuales: editor real con 15 bloques y 17 flechas, mensajes español/inglés en el inspector, publicación, canvas navegable en ambos ejes, configuración, fuente, activación, widget real, rama no tecnológica, aclaración de problemas breves, formularios localizados, cambio de idioma durante una conversación, datos faltantes, finalización, Leads, cambio de estado, Uso y Docs.

### Ciclos de QA local ejecutados

1. Reproducción de `Failed to fetch`; corrección de CORS para que `/api/businesses` conserve la política del API administrativo.
2. Creación del negocio de software/IA/datos/cloud y ampliación del modelo para teléfono, WhatsApp y sitio web.
3. Validación visual del editor, selección coherente del nodo inicial y publicación de la versión 1.
4. Configuración de correos/widget/orígenes, fuente indexada, corrección de la etiqueta “Nombre de la fuente” y activación.
5. Conversación pública real: reorientación no tecnológica, captura del problema, bloqueo por WhatsApp faltante, solución interna y finalización; corrección del script clásico del widget en desarrollo.
6. Verificación de Leads, estado `contacted`, métricas de Uso, Docs y sustitución del input deshabilitado por el estado visible “Conversación finalizada”.

La regresión final se ejecutó en dos procesos para evitar la acumulación de memoria del runner monolítico: 446 pruebas unitarias con 1.701 assertions y 463 pruebas feature con 2.896 assertions. El total fue de **909 pruebas y 4.597 assertions aprobadas**, además de typecheck, ESLint y build del admin aprobados.

### QA de paridad visual con Perfiles

Se ejecutó un segundo bloque de seis ciclos sobre `profiles/2` y `businesses/1`:

1. Comparación de Configuración, Fuentes, Insights, títulos, tarjetas y menú lateral de Perfil para identificar las reglas visuales de referencia.
2. Prueba del alta de una fuente desde el modal; ajuste de la tabla, formatos, estado, tokens, fecha, descarga y acción de eliminación.
3. Prueba visual y funcional del confirm de eliminación, el filtro de Uso por fechas y la separación inicial de Configuración.
4. Navegación de las pestañas Correo, Widget y API, comprobando persistencia de valores, URL seleccionada, key activa y orígenes permitidos.
5. Recorrido de General, Flow, Leads y Docs; uniformización de títulos `h4` y corrección del contraste de los bloques de código de Docs.
6. Revalidación posterior a ajustes: Docs legible y filtro de un día sin actividad con todas las métricas en cero. También se validaron por pruebas automatizadas el rango invertido, la descarga, el aislamiento y la eliminación física del archivo.

Durante la regresión, ESLint detectó que las columnas de Fuentes se definían dentro del render. Se movieron a un factory `getColumns`, igual al patrón usado por Fuentes de Perfil, y la validación posterior de lint y build quedó aprobada.

No se requiere ninguna escritura ni publicación externa para ejecutar estas pruebas.
