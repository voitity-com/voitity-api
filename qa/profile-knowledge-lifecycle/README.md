# Profile Knowledge Lifecycle QA

La fuente de verdad de esta suite es [`PROFILE_KNOWLEDGE_LIFECYCLE_QA.md`](PROFILE_KNOWLEDGE_LIFECYCLE_QA.md).

Estructura:

- `fixtures/sources`: documentos deterministas para Fuentes.
- `fixtures/media`: imagen y video propios para OnlyFans y Otro.
- `manifests`: datos, preguntas y resultados esperados legibles por humanos o agentes.
- `scripts`: verificaciones y ejecución automatizada sin secretos.
- `templates`: plantilla del informe final.
- `outputs`: evidencia generada por cada ejecución; crea un subdirectorio con fecha y hora.

Para ejecutar desde un computador recién instalado, inicia API, cola, admin y web; configura OpenAI/pgvector; luego indica al agente: “Ejecuta la suite descrita en `qa/profile-knowledge-lifecycle/PROFILE_KNOWLEDGE_LIFECYCLE_QA.md` y guarda la evidencia en `outputs/<run-id>`”.

`scripts/run-chat-matrix.sh` ejecuta las 63 preguntas con chats aislados y genera un `results.json` redactado, sin `chat_token`, cookies ni respuestas crudas del proveedor.
