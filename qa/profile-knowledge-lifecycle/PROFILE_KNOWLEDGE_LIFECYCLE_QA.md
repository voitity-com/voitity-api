# Suite funcional y visual: conocimiento del perfil

## Objetivo

Validar de punta a punta que la información del perfil, Fuentes, redes sociales, TikTok, YouTube, OnlyFans, Otro y Productos se indexa, recupera y presenta correctamente; y que al deseleccionar, eliminar o desconectar deja de utilizarse en conversaciones nuevas.

## Alcance y reglas

- Entorno local únicamente. No desplegar ni usar datos de producción.
- Usar una cuenta real local de QA con rol suficiente; nunca guardar sus credenciales aquí.
- Crear un perfil exclusivo cuyo nombre incluya el `run-id`.
- No conectar Instagram en esta suite.
- Abrir cada pregunta en una ventana privada/incógnita y en un chat nuevo. Si el navegador automatizado no ofrece incógnito, usar un contexto de navegador limpio y registrar esa limitación.
- No aprobar manualmente una fuente. Debe pasar sola por `pending_sync`, `syncing`, `indexing` e `indexed`.
- Una respuesta pasa solo si contiene el dato explícito esperado y no lo inventa. La similitud semántica sin el dato centinela no basta.
- Guardar capturas, transcriptos y logs bajo `outputs/<run-id>/`.

## Prerrequisitos

1. Docker y Node instalados; API, worker de cola, admin y web levantados.
2. Migraciones aplicadas, incluida la extensión `vector` de PostgreSQL.
3. Variables de OpenAI y embeddings configuradas; no existen interruptores para desactivar embeddings.
4. Funcionalidades TikTok, YouTube, OnlyFans, Otro y Productos activas en el perfil de QA.
5. Una cuenta TikTok de QA autorizable y dos videos públicos de un canal propio/de QA en YouTube.
6. Ejecutar `scripts/verify-local-services.sh` y luego `scripts/run-automated.sh`.

## Datos deterministas

Usar exactamente [`manifests/test-data.json`](manifests/test-data.json). Los valores `TIKTOK_VIDEO_URL_*`, `YOUTUBE_CHANNEL_URL` y `YOUTUBE_VIDEO_URL_*` se sustituyen en tiempo de ejecución sin editar ni versionar credenciales.

Fuentes:

1. Subir `fixtures/sources/source-orquidea.txt` como archivo.
2. Subir `fixtures/sources/source-faro.md` como archivo.
3. Copiar el contenido de `fixtures/sources/source-nebula-text.txt` en el campo de texto, sin elegir archivo. Confirmar que “Ver archivo” abre un `.txt` real.
4. Esperar a que cada fuente llegue a `indexed`. No pulsar Sincronizar salvo que llegue a `failed`.

Integraciones:

1. TikTok: autorizar/sincronizar, elegir dos piezas y asignar las descripciones del manifiesto.
2. YouTube: conectar el canal, agregar los dos videos propios y usar las descripciones del manifiesto.
3. OnlyFans: conectar manualmente y subir `qa-card-ambar.png` y `qa-motion-selva.mp4` con sus descripciones. El material es sintético y propio de QA.
4. Otro: subir los mismos dos tipos de fixture, con destinos y enlaces indicados en el manifiesto.
5. Confirmar que no aparece botón Desconectar en OnlyFans ni Otro.

Productos y redes:

1. Cargar las cinco redes del manifiesto.
2. Crear los tres productos publicados y la guía de recomendación indicada.
3. Confirmar visualmente etiquetas, enlaces y estados.

## Conversación

Ejecutar todas las entradas de [`manifests/questions.json`](manifests/questions.json). Para cada una:

1. Abrir contexto privado limpio y el perfil público de QA.
2. Enviar solo la pregunta especificada.
3. Guardar respuesta, tarjetas/botones adjuntos, URL del chat si existe y captura.
4. Evaluar contra `must_include`, `must_not_include` y `expected_ui`.
5. Marcar `PASS`, `FAIL` o `BLOCKED` en una copia de `templates/QA_RUN_REPORT.md`.

La matriz completa también puede ejecutarse por HTTP, con un visitante y chat nuevos por pregunta, sin guardar tokens:

```bash
qa/profile-knowledge-lifecycle/scripts/run-chat-matrix.sh <profile-id> \
  qa/profile-knowledge-lifecycle/outputs/<run-id>/results.json
```

El script respeta el límite local de mensajes, exige modo `rag`, evalúa los centinelas y valida que integraciones, productos y redes incluyan su tarjeta o botón correspondiente.
Las preguntas de hechos puntuales pueden definir `must_include` y `require_attachment: false` para evitar falsos negativos por no repetir el identificador o no adjuntar una tarjeta cuando solo se pidió un dato.

### Regresión de necesidad indirecta e historial

Para cada ejecución completa, agregar este escenario con un producto publicado cuya descripción resuelva claramente una necesidad:

1. En un chat nuevo, preguntar por un video de YouTube y confirmar que se adjunta el video correcto.
2. En el mismo chat, escribir una necesidad concreta sin usar el nombre del producto ni las palabras producto, comprar o recomendar, por ejemplo: “Quiero construir mi perfil con inteligencia artificial”.
3. Confirmar que la segunda respuesta muestra la tarjeta del producto semánticamente relevante y que el YouTube del turno anterior no se vuelve a adjuntar.
4. Repetir la segunda pregunta en un chat privado limpio y confirmar el mismo producto.
5. Hacer una pregunta no relacionada y confirmar que no se fuerza ninguna tarjeta de producto.

## Ciclo de vida negativo

1. Deseleccionar una pieza de cada proveedor. Abrir chats limpios y repetir sus tres preguntas: no debe aparecer su centinela ni su tarjeta.
2. Volver a seleccionar y esperar reindexación; repetir una pregunta directa: debe reaparecer.
3. Eliminar un elemento de YouTube, OnlyFans y Otro; repetir sus preguntas en chats limpios: no debe reaparecer.
4. Desconectar TikTok y YouTube. Confirmar el modal correspondiente y repetir preguntas de cualquier elemento que pertenecía a la conexión: no deben recuperarse fragmentos anteriores.
5. Eliminar una fuente desde la interfaz y aceptar el modal. Confirmar que desaparece el archivo y que sus tres preguntas dejan de recuperar el centinela.
6. La cobertura técnica del fallo final/reintento se ejecuta en las pruebas automatizadas. Para QA visual, si existe una fuente local en estado `failed`, abrir el icono de alerta, verificar etapa/mensaje/intentos y pulsar Sincronizar solo si `retryable=true`.

## Criterios de aceptación

- Todos los tests automatizados terminan en verde.
- Las fuentes se autoaprueban, autosincronizan y autoindexan.
- Un error queda visible, tiene logs y permite reintento solo cuando corresponde.
- Eliminar una fuente elimina sus hechos, datos derivados, archivo y chunks; no aparece en un chat nuevo.
- Deselect/delete/disconnect desactiva o elimina el conocimiento inmediatamente y programa reindexación.
- Los enlaces sociales generan el botón localizado “Ir a <red>” / “Go to <network>”.
- Cada integración adjunta únicamente los elementos relevantes y seleccionados.
- Los productos respetan estado publicado y la guía sin inventar datos.
- No existe selector de profesión ni acceso visible a “Datos del perfil”.

## Evidencia y cierre

Crear `outputs/<run-id>/` con:

- `report.md`
- `results.json`
- `screenshots/`
- `chat-transcripts/`
- `logs/api.log` y `logs/queue.log` con secretos redactados

Al finalizar, eliminar el perfil de QA y sus archivos solo si el entorno ofrece un borrado seguro y confirmado. Conservar la evidencia, nunca tokens o información personal.
