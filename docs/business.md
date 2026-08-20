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
    AdminAPI --> Sources["Indexación local de fuentes"]
    AdminAPI --> Versions["Borrador y versiones publicadas"]

    Host["Sitio permitido"] --> Widget["Business widget en Shadow DOM"]
    Widget -->|Key + Origin + Session| Runtime["Business Runtime API"]
    Runtime --> Runner["BusinessFlowRunner"]
    Runner --> AI["BusinessFlowAI"]
    Runner --> Sources
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
- `BusinessFlowAI`: interfaz desacoplada para clasificación, extracción y análisis. El driver local `LocalBusinessFlowAI` permite pruebas deterministas sin servicios externos.
- `BusinessKnowledgeRetriever`: recuperación lexical local sobre chunks indexados.
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
| `business_knowledge_chunks` | Fragmentos recuperables de las fuentes. |
| `business_flows` | Punteros al borrador y a la versión publicada. |
| `business_flow_versions` | Versiones inmutables publicadas y borrador editable. |
| `business_flow_nodes` | Bloques, posiciones y configuración. |
| `business_flow_edges` | Flechas, rama y destino. |
| `business_conversations` | Estado, versión fijada, nodo actual y contexto. |
| `business_messages` | Mensajes del visitante/asistente con tokens. |
| `business_node_executions` | Auditoría de cada bloque ejecutado. |
| `business_leads` | Nombre, email, teléfono, WhatsApp, empresa/sitio opcionales, problema y análisis interno de posible solución. |
| `business_lead_status_histories` | Cambios de estado del lead. |
| `business_usage_events` | Consumo granular por tipo de evento. |
| `business_action_runs` | Idempotencia, intentos y errores de acciones. |

## Interfaz administrativa

Cuando el toggle está activo, el menú **Business** aparece inmediatamente después de **Perfiles** solo para administradores.

### Listado

Muestra nombre, estado y última actualización. **Agregar** solicita nombre y descripción y crea automáticamente el flow de ejemplo como borrador.

### Submenú

- **General**: edita nombre y descripción.
- **Fuentes**: conserva el mismo patrón visual de Perfiles. El alta abre un modal para cargar PDF, TXT, Markdown, CSV, JSON o texto pegado; la tabla permite descargar fuentes con archivo y exige confirmación antes de eliminar una fuente y sus chunks.
- **Flow**: canvas gráfico editable.
- **Leads**: lista contacto, teléfono, WhatsApp, empresa/sitio, problema y posible solución interna; permite cambiar estado a `created`, `contacted`, `sale` o `no_response`.
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

Las flechas guardan bloque origen, destino, etiqueta y `source_handle`. En decisiones, `source_handle` identifica la rama. El inspector permite editar mensajes, espera de input, modo de decisión, ramas, acción, nodo inicial, conexiones y eliminación.

### Flow inicial

```mermaid
flowchart LR
    Welcome["Indicación: saludar y preguntar la necesidad"] --> Qualify{"Decisión: necesidad tecnológica"}
    Qualify -->|other| Redirect["Indicación: orientar a tecnología"]
    Redirect --> Qualify
    Qualify -->|technology| Capture["Acción: guardar el problema descrito"]
    Capture --> Ask["Indicación: solicitar datos"]
    Ask --> Extract["Acción: extraer datos"]
    Extract --> Complete{"Decisión: datos completos"}
    Complete -->|incomplete| Missing["Indicación: pedir datos faltantes"]
    Missing --> Extract
    Complete -->|complete| Analyze["Acción interna: plantear posible solución con IA"]
    Analyze --> Closing["Indicación: análisis y prototipo en 2 semanas"]
    Closing --> Finalize["Acción: crear lead, enviar correos y finalizar"]
```

La publicación valida todo el grafo y crea automáticamente un nuevo borrador basado en la versión publicada. Las conversaciones que ya comenzaron continúan sobre la versión anterior.

El flow inicial contiene 11 nodos y 12 flechas. Solo permite finalizar cuando existen todos estos valores válidos:

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

{"visitor_id":"visitor-123"}
```

Respuesta relevante:

```json
{
  "data": {
    "conversation_id": "uuid",
    "status": "in_progress",
    "session": "encrypted-token",
    "messages": [{"role":"assistant","content":"¡Hola!..."}]
  }
}
```

### Enviar mensaje

```http
POST /api/business/conversations/{conversation_id}/messages
X-Bigmelo-Business-Session: encrypted-token
Idempotency-Key: request-uuid

{"message":"Necesito automatizar un proceso con IA"}
```

La respuesta incluye todos los mensajes del asistente producidos durante ese avance y:

```json
{"data":{"status":"completed","finished":true,"messages":[]}}
```

El integrador debe dejar de pedir input cuando `finished` sea `true` o el estado deje de ser `in_progress`.

### Consultar estado

```http
GET /api/business/conversations/{conversation_id}/status
X-Bigmelo-Business-Session: encrypted-token
```

Estados: `in_progress`, `completed`, `abandoned` y `failed`.

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

## Pruebas

- Unitarias: validador del grafo, extracción/normalización de teléfono y WhatsApp, separación entre problema y datos de contacto, y heurísticas deterministas del driver local.
- Feature API: feature toggle, rol, listado, creación, Fuentes, descarga/eliminación de archivos, aislamiento de fuentes entre negocios, publicación, activación, hash de keys, CORS exacto, origen bloqueado, conversación incompleta/completa, lead, solución interna, correos, status, rango de Uso y validación de fechas.
- Build: TypeScript, ESLint, Vite admin y Vite web.
- Funcionales/visuales: editor real con 11 bloques y 12 flechas, inspector sincronizado con el nodo inicial, publicación, canvas navegable en ambos ejes, configuración, fuente, activación, widget real, rama no tecnológica, datos faltantes, finalización, Leads, cambio de estado, Uso y Docs.

### Ciclos de QA local ejecutados

1. Reproducción de `Failed to fetch`; corrección de CORS para que `/api/businesses` conserve la política del API administrativo.
2. Creación del negocio de software/IA/datos/cloud y ampliación del modelo para teléfono, WhatsApp y sitio web.
3. Validación visual del editor, selección coherente del nodo inicial y publicación de la versión 1.
4. Configuración de correos/widget/orígenes, fuente indexada, corrección de la etiqueta “Nombre de la fuente” y activación.
5. Conversación pública real: reorientación no tecnológica, captura del problema, bloqueo por WhatsApp faltante, solución interna y finalización; corrección del script clásico del widget en desarrollo.
6. Verificación de Leads, estado `contacted`, métricas de Uso, Docs y sustitución del input deshabilitado por el estado visible “Conversación finalizada”.

La regresión final se ejecutó en dos procesos para evitar la acumulación de memoria del runner monolítico: 438 pruebas unitarias con 1.675 assertions y 454 pruebas feature con 2.713 assertions. El total fue de **892 pruebas y 4.388 assertions aprobadas**, además de typecheck, ESLint y builds de admin y web aprobados.

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
