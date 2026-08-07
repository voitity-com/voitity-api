# Índice de conocimiento de perfiles con embeddings

## Objetivo y estado actual

Bigmelo prepara un índice de conocimiento por perfil y usa recuperación aumentada por generación (RAG) para responder. Los embeddings no entrenan ni modifican el modelo de OpenAI: convierten texto en vectores que permiten localizar los fragmentos relevantes antes de generar una respuesta.

En producto debe llamarse **Índice de conocimiento de la IA**, **Preparación del conocimiento** o **Actualización del conocimiento**. No debe presentarse como “entrenamiento del modelo”.

RAG con embeddings es el único flujo de conocimiento para generar respuestas. No depende de una opción en **Nuevas funcionalidades**, un feature flag ni una variable de activación. El backend nunca vuelve a enviar el bloque completo de conocimiento mediante el prompt legacy.

## Controles de configuración

| Variable | Responsabilidad | Valor normal |
| --- | --- | --- |
| `AI_KNOWLEDGE_INDEX_VERSION` | Versión de las reglas de construcción del índice. | `2026-08-06-v2` |
| `AI_KNOWLEDGE_RETRIEVAL_TOP_K` | Cantidad base de fragmentos recuperados. | `8` |
| `AI_KNOWLEDGE_RETRIEVAL_CANDIDATE_LIMIT` | Candidatos por similitud vectorial. | `40` |
| `AI_KNOWLEDGE_RETRIEVAL_LEXICAL_CANDIDATE_LIMIT` | Candidatos por palabras, nombres y referencias. | `30` |
| `AI_KNOWLEDGE_RETRIEVAL_MINIMUM_SCORE` | Puntaje mínimo para aceptar un fragmento no forzado. | `0.35` |
| `AI_KNOWLEDGE_MAX_CONTEXT_TOKENS` | Presupuesto aproximado de conocimiento enviado al chat. | `2500` |
| `AI_KNOWLEDGE_PROACTIVE_MEDIA_ENABLED` | Permite adjuntar medios aunque el visitante no los haya pedido. | `false` |

Comportamiento de respuesta:

| Estado del índice | Resultado |
| --- | --- |
| `ready`, misma versión/modelo/dimensiones | Retrieval híbrido y respuesta RAG. |
| Faltante, pendiente, procesando, desactualizado o fallido | Reconstrucción síncrona antes del retrieval y luego respuesta RAG. |
| Versión, modelo o dimensiones incompatibles | Reconstrucción síncrona con la configuración vigente y luego respuesta RAG. |
| Error de indexación, embeddings o retrieval | La generación se detiene y reporta el error; nunca usa el prompt legacy. |

Las variables que permanecen son parámetros técnicos del índice, no interruptores. No existe una configuración para desactivar embeddings.

## Momento exacto en que se genera un embedding

### Embeddings persistentes del conocimiento

Los modelos `Profile`, `ProfileSource`, `ProfileSourceItem`, `ProfileFact`, `ProfileIntegrationMedia` y `ProfileProduct` tienen el observer `ProfileKnowledgeSourceObserver`.

Cuando uno de esos registros se crea, actualiza, elimina o restaura:

1. El observer identifica el perfil afectado.
2. Después de confirmar la transacción, `ProfileKnowledgeIndexScheduler` marca el índice como `pending` u `outdated`.
3. Se encola `IndexProfileKnowledge`.
4. El worker usa `WithoutOverlapping` para que un mismo perfil no se reconstruya en paralelo.
5. `ProfileKnowledgeDocumentBuilder` vuelve a construir la representación textual completa del perfil.
6. `ProfileKnowledgeIndexer` compara la clave estable, el `content_hash`, modelo, dimensiones y vector existente.
7. Solo los documentos nuevos, modificados, sin embedding o incompatibles se envían al proveedor de embeddings, en lotes de 50 por defecto.
8. Los documentos sin cambios reutilizan su vector. Los chunks que dejaron de existir se eliminan.
9. El índice pasa a `ready` únicamente después de guardar correctamente documentos, vectores y estados.

Por tanto, guardar un contenido no genera necesariamente el vector dentro de la misma petición HTTP. Normalmente deja un job en cola y el embedding se crea pocos segundos después por el worker. La operación es incremental, no reconstruye ni vuelve a cobrar todo el perfil ante cada cambio.

Si llega una conversación antes de que termine el job, el servicio de contexto detecta que el índice no está listo y ejecuta la indexación incremental de forma síncrona antes de buscar. Esto puede aumentar la latencia de ese primer mensaje, pero garantiza que la respuesta siempre use embeddings.

Como mecanismo de recuperación, cada cinco minutos se ejecuta:

```bash
php artisan ai-knowledge:index --pending
```

Ese comando vuelve a encolar perfiles sin índice, desactualizados, fallidos o incompatibles con la versión, el modelo o las dimensiones configuradas.

### Momento por tipo de contenido

| Contenido | Qué evento programa la indexación | Cuándo queda utilizable |
| --- | --- | --- |
| Información general, identidad, profesión y guía de productos | Guardar el `Profile`. | Cuando termina el job y el índice vuelve a `ready`. |
| Redes y enlaces sociales | Guardar `profiles.networks` dentro del perfil. | Después del mismo ciclo del `Profile`. |
| Datos Fuentes | Crear/importar la fuente programa una revisión, pero su contenido solo es elegible al aprobar la fuente y sus ítems/hechos. La aprobación también programa el índice. | Al terminar el job posterior a la aprobación. |
| Ítems de fuentes | Crear, editar, aprobar o eliminar `ProfileSourceItem`. | Tras el job; solo los aprobados generan chunks activos. |
| Hechos de fuentes | Crear, editar, aprobar, cambiar visibilidad o eliminar `ProfileFact`. | Tras el job; solo hechos aprobados y públicos son elegibles. |
| Instagram, TikTok, YouTube, OnlyFans y Otro | Crear, sincronizar, seleccionar, editar descripción/observación o eliminar `ProfileIntegrationMedia`. | Tras el job; solo elementos seleccionados participan en retrieval. |
| Productos | Crear, editar, publicar, despublicar o eliminar `ProfileProduct`. | Tras el job; solo publicados participan en retrieval. Las actualizaciones masivas programan el índice explícitamente. |
| Guía para recomendar productos | Guardar `product_recommendation_guidance` en el perfil. | Tras el job del perfil. |

En **Otro**, Instagram, TikTok, YouTube y OnlyFans se vectoriza únicamente el texto descriptivo: proveedor, tipo, caption, observación, fecha, destino y restricciones. La imagen o el video binario nunca se envían al proveedor de embeddings. El archivo continúa en `storage` local o en S3 según el filesystem configurado.

### Embedding temporal de cada pregunta

Al recibir un mensaje de chat, se genera un embedding adicional para la consulta. La consulta combina el mensaje actual y hasta dos mensajes anteriores inmediatos para resolver referencias como “esa foto”, “el segundo” o “¿y dónde fue?”.

Este vector de consulta:

- se usa para buscar en pgvector;
- no se guarda como conocimiento permanente;
- no modifica el índice;
- se genera una vez por respuesta RAG.

El modelo de chat recibe aparte hasta seis mensajes recientes como historial conversacional. El historial no se vectoriza ni se incorpora permanentemente al conocimiento del perfil.

## Información indexada

| `source_type` | Fuente y contenido | Elegibilidad |
| --- | --- | --- |
| `profile_identity` | Nombre, descripción, género, personalidad y profesión. | Activo. La identidad mínima también permanece en el prompt de sistema. |
| `profile_data` | Secciones e ítems de `profiles.data`: biografía, trabajo, estudios, proyectos, habilidades y campos adicionales. | Activo; se excluyen `networks` y flags de voz. |
| `profile_source_item` | Nombre e ID de la fuente, tipo, título y contenido del ítem. | Fuente e ítem aprobados, fuente no duplicada. |
| `profile_source` | Texto extraído cuando una fuente aprobada no tiene ítems aprobados. | Fuente aprobada, con texto y no duplicada. |
| `profile_fact` | Categoría y texto del hecho, conservando trazabilidad a la fuente. | Aprobado, público y de una fuente no duplicada. |
| `social_link` | Red y URL exacta de `profiles.networks`. | Activo. |
| `integration_media` | ID, proveedor, destino, tipo, caption, observación, fecha y restricción de edad. | Existe para todos; `active=true` solo si `selected=true`. |
| `product` | ID, nombre, descripción y tipo de destino. | Existe para todos; `active=true` solo si está publicado. |
| `product_guidance` | Guía editorial para decidir cuándo recomendar productos. | Activa como regla de routing, no como característica de un producto. |

## Fuentes vacías y duplicadas

Antes de construir documentos, `ProfileKnowledgeSourceDeduplicator` normaliza espacios y mayúsculas y calcula un SHA-256 del texto extraído o de los ítems de cada fuente.

- La fuente más antigua con el mismo hash queda como canónica.
- Las siguientes guardan `duplicate_of_source_id` y no generan ítems, hechos ni texto duplicados en el índice.
- Una fuente vacía conserva `content_hash=null` y no se marca falsamente como indexada.
- Solo una fuente que produjo al menos un documento queda en estado `indexed` y recibe `indexed_at`.
- Ítems y hechos aprobados quedan con `indexed=true` únicamente si realmente produjeron un chunk.

Esto conserva la trazabilidad en base de datos sin gastar tokens ni competir dos veces por la misma información durante retrieval.

## Construcción y almacenamiento

Los textos extensos se dividen respetando primero párrafos y después oraciones. Solo cuando una oración individual supera el límite se usa un corte fijo. Así se evita separar arbitrariamente una idea, una experiencia laboral o un bloque de una fuente.

Tablas principales:

- `profile_knowledge_indexes`: estado por perfil, versión, modelo, dimensiones, conteos, tokens, errores y fechas.
- `profile_knowledge_chunks`: clave estable, texto, origen, metadatos, visibilidad, actividad, hash y vector.
- `profile_sources.content_hash` y `profile_sources.duplicate_of_source_id`: identidad y relación de duplicados.

PostgreSQL usa la extensión `vector`, una columna `vector(1536)` y un índice HNSW con distancia coseno. SQLite guarda vectores como JSON solo para pruebas.

La configuración actual usa `text-embedding-3-small` con 1536 dimensiones. Cambiar dimensiones requiere migrar la columna vectorial y reconstruir el índice. Cambiar reglas incompatibles de documentos requiere incrementar `AI_KNOWLEDGE_INDEX_VERSION` y reindexar.

## Retrieval híbrido

El retrieval ya no depende exclusivamente de similitud semántica:

1. Se analiza la intención: medios, solicitud explícita de mostrar, enlace social, producto, recomendación, proveedor, términos e identificadores numéricos.
2. pgvector obtiene candidatos semánticos por distancia coseno.
3. PostgreSQL full-text search agrega candidatos léxicos con prefijos; esto recupera nombres, referencias, lugares y palabras exactas aunque no estén en el top semántico.
4. Se agregan candidatos por tipo según la intención.
5. El ranking combina similitud semántica, coincidencia léxica, palabras en contenido/metadatos e identificadores exactos.
6. Una referencia exacta, por ejemplo `61385`, puede superar a un resultado semánticamente cercano pero incorrecto.
7. Se aplican umbral, `top_k` y presupuesto de contexto.

Reglas de intención importantes:

- pedir una foto, video, publicación o contenido busca `integration_media`;
- pedir el perfil, usuario, canal o link de una red busca `social_link` y excluye medios de esa red cuando no se pidió contenido;
- pedir un producto o una recomendación busca `product` y `product_guidance`;
- proveedores como Instagram, TikTok, Facebook, YouTube, LinkedIn, GitHub, OnlyFans, X, blog, sitio web y diario ayudan a restringir el origen.

El retrieval registra `semantic_score`, `lexical_score`, `keyword_score`, `identifier_score`, score final y si el resultado fue forzado por intención.

## Generación y validación de la respuesta

El prompt conserva identidad, personalidad, idioma, reglas de seguridad, esquema JSON y el historial reciente. El bloque completo de datos se sustituye por los chunks recuperados.

La selección vectorial nunca autoriza por sí sola un adjunto:

- `ProfileMediaPromptService` vuelve a validar feature del perfil, `selected`, plan, proveedor, restricciones, tipo y contenido ya mostrado;
- `ProfileProductPromptService` vuelve a validar feature del perfil, `products_enabled` y estado publicado;
- en modo RAG, `AnswerBuilder` limita tarjetas de medios y productos a los IDs presentes en `retrieved_sources`;
- si el visitante pide explícitamente mostrar un medio y el modelo no selecciona un ID válido, se adjunta de forma determinista el mejor medio recuperado que pasó todas las reglas;
- por defecto no se adjunta un medio solo porque su descripción se parezca a una pregunta informativa. Ese comportamiento solo puede habilitarse con `AI_KNOWLEDGE_PROACTIVE_MEDIA_ENABLED=true`;
- una solicitud de link social no se convierte en tarjeta de contenido, y una solicitud de contenido no agrega automáticamente todos los botones sociales.

Para **Otro**, el destino y la acción final se vuelven a resolver con `IntegrationDestinationCatalog` según el idioma del perfil. El índice puede contener etiquetas útiles para retrieval, pero el botón visible se construye desde los metadatos vigentes, permitiendo “Ver en Instagram”, “Ver en TikTok”, “Leer en el medio”, “Visitar el sitio web” o el destino personalizado.

## Cómo se identifica que no hay respuesta

Que ningún chunk supere el umbral no provoca el envío automático de todos los datos. El modelo debe usar `[[BIGMELO_NO_ANSWER]]` cuando el conocimiento recuperado no contiene la respuesta.

`AnswerBuilder` elimina el marcador y usa la respuesta de ausencia configurada del perfil. Hay respuestas especializadas:

- solicitud de medios sin coincidencias: inventario vacío para el proveedor o tipo solicitado;
- solicitud de producto sin producto aplicable: `product_action: none`, sin inventar tarjetas;
- selección de ID inexistente o no autorizado: el ID se descarta.

No existe comparación contra el prompt legacy ni fallback técnico hacia él. La calidad se valida con pruebas de retrieval, respuestas funcionales y la metadata de los chunks recuperados.

## Logs y trazabilidad

La indexación registra perfil, modelo, dimensiones, ejecución forzada, chunks totales/activos/recalculados, tokens de embeddings, duplicados detectados y errores.

La recuperación registra perfil, IDs y tipos recuperados, intención detectada, proveedores, tokens de consulta/contexto y latencia. No escribe el texto de los chunks en el log.

`messages.data.chat_ai.response._bigmelo.knowledge` guarda `mode=rag`, ID del índice, `retrieved_sources`, scores y métricas. Esa metadata permite reproducir por qué se mostró una respuesta, una tarjeta o un link sin duplicar el texto indexado.

## Operación local

```bash
docker compose up -d db app queue scheduler
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan ai-knowledge:index --sync
docker compose exec -T app php artisan ai-knowledge:index 2 --sync
docker compose exec -T app php artisan ai-knowledge:index 2 --sync --force
docker compose exec -T app php artisan ai-knowledge:index --pending
```

Después de cambiar `.env`:

```bash
docker compose exec -T app php artisan optimize:clear
docker compose restart app queue scheduler
```

Para una nueva versión de reglas:

1. incrementar `AI_KNOWLEDGE_INDEX_VERSION`;
2. limpiar configuración;
3. ejecutar `ai-knowledge:index --pending` o reindexar un perfil específico;
4. comprobar que el índice quede `ready` con la versión nueva.

## Costos y cobro

Hay tres consumos distintos:

1. indexación inicial de los chunks del perfil;
2. actualización incremental solo de textos que cambiaron;
3. un embedding corto de consulta por cada respuesta RAG.

El ahorro principal está en el modelo generativo: se deja de enviar todo el perfil, fuentes, productos e integraciones en cada turno. Deben medirse `embedding_tokens`, tokens del prompt, latencia y calidad por perfil antes de definir precios.

Una presentación comercial adecuada es incluir **actualizaciones del índice de conocimiento de IA** dentro de cada plan y cobrar reconstrucciones extraordinarias o volúmenes muy altos. El embedding de consulta puede incluirse en el costo normal del mensaje. No se recomienda cobrarlo como “entrenamiento de IA”.

## Pruebas y Postman

Las pruebas automatizadas cubren adaptador y dimensiones, todos los tipos de documentos, actualización incremental, reconstrucción síncrona cuando el índice no está listo, deduplicación, fuentes vacías, estados veraces, intención, retrieval semántico/léxico, referencias exactas, links sociales, medios, productos y prompt reducido.

La colección `postman/voitity-api.postman_collection.json` conserva endpoints de administración y settings por perfil, pero ya no expone `features.ai.use_embeddings`: RAG es el flujo normal del backend.

## Componentes permanentes

La indexación incremental, pgvector, reconstrucción bajo demanda, control de versión, observabilidad y validación determinista de IDs forman parte del flujo obligatorio y deben permanecer.
