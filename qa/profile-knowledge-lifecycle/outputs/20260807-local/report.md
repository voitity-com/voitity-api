# Informe QA: 20260807-local

> Reprueba posterior a los ajustes determinísticos: [`report-after-deterministic.md`](report-after-deterministic.md). El resultado mejoró de 51/63 a 63/63.

- Fecha: 2026-08-07
- Entorno: local; no se ejecutaron cambios ni pruebas contra producción o S3
- Cuenta local: `morenoabel@gmail.com`
- Perfil: `QA Embeddings 20260807` (`qa-embeddings-20260807`, ID 24)
- API: `main` en `f87fdd8`, más los cambios locales de esta tarea
- Admin: `main` en `31de4cd`, más los cambios locales de esta tarea
- Web: `main` en `69dc490`
- Aislamiento del chat: visitante y conversación nuevos para cada pregunta

## Resultado

La automatización, compilación y ciclo de vida de conocimiento quedaron operativos. El índice final está `ready`, con 30 chunks activos, 30 embeddings y ningún chunk activo sin embedding. La matriz conversacional obtuvo 51 PASS de 63 preguntas. Los 12 fallos se concentran en recuperación/presentación de enlaces sociales, selección de algunas piezas multimedia y aplicación de la guía de productos; no se encontró pérdida general del índice.

| Área | PASS | FAIL | BLOCKED | Resultado |
|---|---:|---:|---:|---|
| Perfil | 3 | 0 | 0 | Correcto |
| Fuentes | 9 | 0 | 0 | Correcto |
| Redes sociales | 10 | 5 | 0 | Requiere ajuste de intención/sinónimos |
| Integraciones | 20 | 4 | 0 | Requiere ajuste de selección/presentación |
| Productos | 8 | 1 | 0 | Un conflicto con la guía |
| Guía de productos | 1 | 2 | 0 | Requiere mayor prioridad y tarjetas |
| **Total** | **51** | **12** | **0** | **80,95 %** |

Los resultados estructurados, preguntas, evidencia de recuperación y adjuntos están en [`results.json`](results.json).

## Pruebas técnicas

- Suite API completa: 830 pruebas aprobadas, 4.045 aserciones.
- Pruebas focalizadas de fuentes, integraciones y controladores: 19 aprobadas, 127 aserciones.
- Pruebas existentes de integraciones: 32 aprobadas, 199 aserciones.
- Formato PHP de los archivos modificados: aprobado.
- Admin: typecheck, lint y build aprobados.
- Web: build aprobado.
- Única advertencia de compilación: chunks existentes de Rollup superiores a 500 KB en Admin.
- Colección Postman: JSON válido y endpoints de reintento/eliminación incluidos.

## Ciclos de vida comprobados

- Tres fuentes llegaron automáticamente a `indexed`; una creada desde texto se guardó como archivo `.txt` y se pudo abrir desde “Ver archivo”.
- Eliminar la fuente temporal `ELIMINAR-99` dejó 0 fuentes, 0 hechos y 0 chunks relacionados.
- Deseleccionar el elemento “Otro” 65 redujo inmediatamente sus chunks activos a 0; al seleccionarlo otra vez volvió a 1 tras la cola.
- El elemento temporal “Otro” 73 tenía 1 chunk antes de eliminarlo y 0 después; el registro también desapareció.
- Los modales de desconexión de TikTok y YouTube se verificaron visualmente y se cancelaron para conservar la matriz de prueba.
- OnlyFans y Otro no muestran botón Desconectar.
- No existe selector de profesión ni acceso visible a “Datos del perfil”.

Estado final del perfil de QA:

| Métrica | Valor |
|---|---:|
| Estado del índice | `ready` |
| Chunks totales/activos | 30 / 30 |
| Chunks activos sin embedding | 0 |
| Fuentes indexadas | 3 |
| Medios seleccionados | 8 |
| Productos publicados | 3 |

Distribución de chunks: 8 de integraciones, 3 de productos, 1 de guía, 6 hechos, 1 identidad, 6 elementos de fuentes y 5 redes sociales.

## Hallazgos

| ID | Severidad | Área | Hallazgo |
|---|---|---|---|
| QA-01 | Alta | Redes | Cinco preguntas indirectas no resolvieron bien la red. “Videos largos” recuperó y adjuntó TikTok aunque también se recuperó el chunk de YouTube. “Red profesional”, “repositorio” y “perfil de código” no alcanzaron LinkedIn/GitHub. |
| QA-02 | Media | Integraciones | Cuatro preguntas no presentaron la pieza correcta: una de TikTok devolvió solo el enlace social, una duración de TikTok no fue respondida, y preguntas sobre OnlyFans/Otro recuperaron chunks de integración pero el modelo dijo no tener contenido. |
| QA-03 | Alta | Productos | La guía sí fue forzada al contexto, pero una recomendación devolvió `Guía Brújula 13` en vez de `Kit Horizonte 92`. Otra pregunta priorizó una fuente general sobre los productos. Conviene dar a la guía prioridad explícita sobre conocimiento general cuando la intención es recomendar/comparar. |
| QA-04 | Media | Productos/UI | La comparación identificó correctamente el producto más barato y el más costoso, pero no adjuntó tarjetas de producto. |
| QA-05 | Baja | Admin | El límite “sin límite” se visualiza como `2147483647` en una etiqueta de selección. Debe presentarse como “Sin límite”. |
| QA-06 | Baja | YouTube | El identificador del canal se visualiza como `@@openai`; debe normalizarse a una sola arroba. |

La evidencia indica que QA-01 a QA-04 son problemas de intención, priorización o construcción de adjuntos, no de generación de embeddings: las consultas fallidas siguieron en modo `rag` y, en varios casos, recuperaron el chunk correcto.

## Evidencia visual

- [`01-profile-edit-no-profession.png`](screenshots/01-profile-edit-no-profession.png): edición sin profesión.
- [`02-sources-indexed.png`](screenshots/02-sources-indexed.png): tres fuentes indexadas.
- [`03-other-integration.png`](screenshots/03-other-integration.png): Otro y su botón localizado.
- [`04-onlyfans-integration.png`](screenshots/04-onlyfans-integration.png): OnlyFans sin Desconectar.
- [`05-youtube-integration.png`](screenshots/05-youtube-integration.png): videos de YouTube.
- [`06-social-links.png`](screenshots/06-social-links.png): cinco redes configuradas.
- [`07-products.png`](screenshots/07-products.png): tres productos publicados.
- [`08-tiktok-fixture.png`](screenshots/08-tiktok-fixture.png): fixture local de TikTok.
- [`10-public-chat-other-card.png`](screenshots/10-public-chat-other-card.png): tarjeta de Otro en el chat.
- [`11-public-chat-social-button.png`](screenshots/11-public-chat-social-button.png): botón “Ir a YouTube”.
- [`12-public-chat-products.png`](screenshots/12-public-chat-products.png): comparación con tres tarjetas.

## Limitaciones

- No se usó Instagram, conforme al alcance solicitado.
- TikTok exige credenciales y consentimiento OAuth externos. Para no usar una cuenta ajena, se creó localmente una integración y dos piezas sintéticas marcadas `qa_fixture=true`; el índice, selección, deselección y chat sí se probaron con ellas. El flujo OAuth real queda pendiente de una cuenta QA autorizada.
- El perfil se publicó únicamente en la base local para probar el chat. No se declaró una voz clonada ni se aceptó consentimiento de voz en nombre del usuario.
- El navegador automatizado no ofrece una ventana incógnita independiente. La matriz HTTP creó un visitante y chat nuevos para cada una de las 63 preguntas, sin reutilizar historial ni guardar tokens.
- El perfil y los fixtures se conservaron localmente para inspección. No se escribió en producción ni en S3.

## Seguridad y evidencia

- Los archivos de resultados no contienen contraseña, token de autenticación ni `chat_token`.
- Los logs relevantes están en [`logs/api.log`](logs/api.log) y [`logs/queue.log`](logs/queue.log).
- El estado estricto inicial de la automatización se conserva como `automated_status`; `status` es la reevaluación por pregunta que evita falsos negativos cuando un hecho no necesitaba tarjeta.
