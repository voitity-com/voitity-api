# Generación de avatar y clonación de voz

Este documento describe el flujo técnico vigente entre `voitity-admin` y `voitity-api`. La validación facial confirma que la imagen puede procesarse; no comprueba identidad, propiedad, edad ni presencia física (liveness). La interfaz debe seguir exigiendo que la persona use su propia imagen y su propia voz, o material para el que tenga autorización expresa.

## Flujo del avatar

### 1. Preparación y prevalidación en el navegador

La pantalla `voitity-admin/src/src/pages/dashboard/profile-details/avatar.tsx` permite seleccionar JPG, PNG o WEBP, mover la imagen y ajustar el zoom. Al guardar:

1. El recorte visible de 400 × 400 se exporta a PNG de 1024 × 1024. El tamaño mayor conserva detalle suficiente para Runway y para la validación del rostro.
2. `voitity-admin/src/src/lib/avatar/face-detector.ts` carga MediaPipe Face Detector localmente y analiza el recorte final.
3. El navegador bloquea errores evidentes: ningún rostro, más de un rostro, baja confianza, rostro demasiado pequeño/grande o fuera del centro.
4. Si MediaPipe no puede inicializarse, la interfaz registra el fallo y continúa. Esta capa mejora la experiencia, pero no se considera una barrera de seguridad.

Los artefactos de MediaPipe están versionados con la aplicación:

- Modelo: `public/models/face-detector/blaze-face-short-range-float16.tflite`.
- Runtime WASM: `public/wasm/mediapipe/`.
- Paquete: `@mediapipe/tasks-vision@1.0.1`, licencia Apache-2.0.
- Modelo oficial: `https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/latest/blaze_face_short_range.tflite`.

Esto evita descargar código o el modelo desde un CDN durante el uso normal.

### 2. Validación en la API

`POST /api/avatar/generate` mantiene las validaciones de archivo del `GenerateAvatarRequest` y luego aplica el siguiente orden:

1. Autenticación, autorización sobre el perfil y disponibilidad del plan.
2. Comprobación de que no exista otro avatar en procesamiento.
3. `AvatarImageValidator` decodifica la imagen con GD, elimina metadatos al normalizarla a JPEG y llama a `AvatarImageValidationClient`.
4. El adaptador `RekognitionAvatarImageValidationClient` ejecuta `DetectFaces` con `Attributes=ALL` y bytes en memoria. La API de Rekognition es stateless: Bigmelo no crea colecciones faciales ni indexa identidades.
5. Solo después de aprobar se guarda la fuente, se crea `ProfileAvatar`, se reserva el consumo y se llama a Runway.

Cuando `config('app.env')` es exactamente `local`, `AvatarImageValidator` omite la llamada a Rekognition y registra `Avatar source image validation skipped in local environment.`. Esto permite desarrollar sin credenciales de AWS. Las validaciones del archivo y la prevalidación de MediaPipe en el navegador permanecen activas. En cualquier otro entorno —incluidos `testing`, `staging` y `production`— Rekognition sigue siendo obligatorio y falla de forma cerrada si no está disponible.

La regla de negocio exige exactamente un rostro y evalúa:

- Dimensiones mínimas de 512 × 512.
- Confianza mínima de detección.
- Proporción y posición del rostro dentro de la imagen.
- Nitidez, iluminación y pose (yaw, pitch y roll).
- Rostro no ocluido, ojos abiertos y ausencia de gafas oscuras.

Los umbrales están centralizados en `voitity-api/src/config/avatar-image-validation.php`. Deben calibrarse con un conjunto representativo de imágenes antes de endurecerlos. Un rechazo devuelve HTTP 422:

```json
{
  "message": "No se detectó un rostro. Usa una foto clara de una sola persona.",
  "code": "avatar_source_image_invalid",
  "errors": {
    "image": ["No se detectó un rostro. Usa una foto clara de una sola persona."]
  },
  "data": {
    "reason_codes": ["no_face"]
  }
}
```

Fuera de `local`, si Rekognition no está disponible, se devuelve HTTP 503 con `avatar_image_validation_unavailable`. En ese caso no se almacena la imagen, no se crea `ProfileAvatar`, no se consume cuota y no se llama a Runway.

### 3. Credenciales, IAM y privacidad

El SDK de AWS usa su cadena estándar de credenciales. No se guardan claves en el repositorio:

- Local: perfil de AWS/SSO o variables temporales de la sesión que ejecuta PHP.
- Producción: rol de la tarea, instancia o workload identity.
- Permiso mínimo requerido: `rekognition:DetectFaces` en la región configurada por `AWS_DEFAULT_REGION`.

Los logs incluyen IDs internos, proveedor, request ID, duración, códigos de rechazo y métricas redondeadas. Nunca deben incluir bytes, base64, landmarks, credenciales ni el contenido completo de la respuesta biométrica. Las imágenes rechazadas solo viven en la solicitud HTTP y memoria de proceso durante la validación.

`DetectFaces` se cobra como una operación de análisis de imagen. A modo de referencia, el primer nivel público ha sido aproximadamente USD 0,001 por imagen; hay que confirmar la tarifa vigente de la región antes de calcular márgenes. MediaPipe corre en el dispositivo y no genera costo por llamada.

### 4. Generación y almacenamiento

Cuando la fuente fue aprobada, `AvatarRepository`:

1. Guarda la fuente en el disco `videoai.profiles.disk`, carpeta `images/sources`.
2. Persiste la URL de ese recorte en `ProfileAvatar.original_file`. Esta es la versión `original`: el recorte de 1024 × 1024 aprobado por la persona, no el archivo completo sin recortar del dispositivo.
3. Crea un `ProfileAvatar` en estado `processing` y `generation_status=processing`.
4. Reserva una imagen y los segundos de video definidos por `AvatarGenerationSpecification`.
5. Envía a Runway la referencia junto al prompt de imagen y modelo configurados en `config/videoai.php`.
6. Emite `AiImageForAvatarCreated`.

El procesamiento posterior es asíncrono:

1. `GetAIImageForAvatar` consulta la tarea, descarga la imagen generada al disco de perfiles y emite `AiImageForAvatarGenerated`.
2. `CreateAiVideoForAvatar` crea el clip con el modelo, prompt y duración configurados, y emite `AiVideoForAvatarCreated`.
3. `GetAIVideoForAvatar` consulta la tarea, guarda el video, activa el nuevo avatar, inactiva el anterior y finaliza el consumo reservado.
4. En fallos o timeouts se marca la etapa correspondiente, se ajusta el consumo según los artefactos realmente producidos y se notifica al dueño del perfil y a los administradores.

#### Restricción del prompt de imagen en Runway

La operación `POST /v1/text_to_image` usada con el modelo de imagen de Runway acepta `promptText` con un máximo de **1.000 caracteres**. El prompt predeterminado de `config/videoai.php` debe mantenerse dentro de ese límite, contando el texto final que realmente se envía al proveedor.

Si se supera el límite, Runway responde HTTP 400 con `Validation of body failed` y un issue `too_big` para `promptText`. Como la respuesta rechazada no contiene un ID de tarea, la capa de aplicación también puede mostrar el mensaje secundario `Video AI image generation did not return a source id.`. Para diagnosticarlo debe revisarse primero el log `Runway: Image generation failed`, que conserva el estado HTTP y la respuesta sanitizada del proveedor.

La prueba `VideoAIServiceTest::default_image_prompt_respects_runway_character_limit` verifica que el prompt no esté vacío y que `mb_strlen(config('videoai.prompts.image')) <= 1000`. Cualquier modificación futura del prompt debe conservar esta prueba aprobada antes de probar con la API real.

### Versiones disponibles e historial

Cada `ProfileAvatar` representa un intento de generación y puede exponer hasta tres versiones:

| Variante API | Fuente | Tipo |
|---|---|---|
| `original` | `profile_avatars.original_file` | Imagen recortada enviada al API |
| `enhanced` | `aiimages.file` | Imagen mejorada por Runway |
| `animation` | `aivideos.file` | Video generado desde la mejorada |

`generation_status` conserva el resultado técnico del intento (`processing`, `completed`, `image_failed` o `video_failed`) aunque después se active una versión parcial. `selected_variant` indica cuál versión está en uso. El campo histórico `file` continúa siendo la fuente pública activa para mantener compatibilidad con el endpoint público y con `voitity-web`.

`GET /api/avatar/{profile}/history` devuelve un objeto `variants` por intento. Cada versión informa `kind`, `status`, `file`, fallo y si está seleccionada. `POST /api/avatar/{profile}/activate` exige `avatar_id` y `variant`; el servidor resuelve el archivo desde sus relaciones y no acepta una URL enviada por el cliente.

Reglas del historial:

- Un rechazo de validación facial no almacena la fuente ni crea `ProfileAvatar`, por lo que no aparece.
- Si falla la imagen mejorada, el intento conserva la original y marca `generation_status=image_failed`.
- Si falla el video, conserva original y mejorada y marca `generation_status=video_failed`.
- Si termina todo, conserva las tres, activa la animación por defecto y permite cambiar posteriormente a cualquiera de ellas.
- Mientras haya una generación en curso no se cambia el avatar activo.

En un fallo de imagen se libera la reserva completa. En un fallo de video, como la imagen mejorada sí se produjo, `AvatarGenerationUsageService::finalizeImageOnly` reemplaza la reserva por una imagen y cero segundos, finaliza ese consumo y devuelve los segundos de video. Los reintentos son idempotentes sobre la misma clave de uso.

Los registros históricos creados antes de `original_file` no se relacionan automáticamente con objetos de `images/sources`: los nombres aleatorios anteriores no incluyen el ID del avatar y asociarlos por fecha sería inseguro. Para esos registros la API muestra las versiones mejorada y animación que sí estén relacionadas, y marca la original como no disponible.

El disco de perfiles determina el almacenamiento sin bifurcar el código: localmente puede apuntar a filesystem y en producción al bucket S3 de perfiles.

## Flujo de clonación de la propia voz

### 1. Captura y carga

La pantalla `voitity-admin/src/src/pages/dashboard/profile-details/voice.tsx` solicita acceso al micrófono con `navigator.mediaDevices.getUserMedia`, guía a la persona por un texto de seis partes y graba con `MediaRecorder`. Al confirmar:

1. Web Audio decodifica la grabación.
2. `lamejs` mezcla los canales a mono y codifica MP3 a 128 kbps.
3. La UI crea o actualiza el registro `Voice` con nombre, descripción, perfil e idioma (`es` o `en`).
4. Sube `voice-sample.mp3` a `POST /api/voice/{voice}/sample`.
5. Solicita el procesamiento en `POST /api/voice/{voice}/sample/{voice_sample}/process`.

`StoreVoiceSampleRequest` admite los formatos de audio configurados hasta 50 MB. `VoiceSampleFileManager` guarda el archivo en `files/samples` usando un UUID y obtiene la duración con getID3. El endpoint de proceso exige la duración mínima del driver, cinco segundos por defecto para ElevenLabs.

### 2. Reserva, cola y proveedor

El endpoint de proceso verifica autorización y plan, crea un `VoiceProviderRequest` pendiente y reserva una unidad de `voice_clones`. Después emite `VoiceSampleAdded`.

`CloneVoice`, que corre en cola con tres intentos y timeout de 120 segundos, resuelve el driver mediante `VoiceManager` y `VoiceService`. El driver actual `ElevenLabsVoiceClient`:

- Lee la muestra desde el disco configurado.
- Envía multipart a `POST /v1/voices/add` con nombre, descripción, `remove_background_noise=true` y etiquetas internas.
- Persiste `source=elevenlabs` y el `source_voice_id` devuelto.
- Marca `VoiceProviderRequest` como completado y finaliza la reserva.
- Si reemplazó una voz anterior, encola su eliminación en ElevenLabs.
- Genera los audios predeterminados que todavía falten y notifica al dueño.

`AddSample` también escucha `VoiceSampleAdded`: se omite cuando aún no existe `source_voice_id`; para una voz existente puede usar el endpoint de edición del proveedor. La ruta principal de reemplazo sigue siendo `CloneVoice`, que crea el nuevo ID y elimina el anterior de forma diferida.

Ante una excepción definitiva, `CloneVoice::failed` marca la solicitud como fallida, libera el consumo reservado y emite las notificaciones correspondientes.

### 3. Uso de la voz clonada

Para probar o responder con audio, `VoiceService::generateAudio` reserva caracteres TTS, llama al endpoint de text-to-speech de ElevenLabs con `eleven_multilingual_v2` por defecto y finaliza o libera la reserva según el resultado. Los audios generados usan el disco, carpeta y visibilidad de `voice.generated_audio`.

No debe registrarse la clave del proveedor ni el contenido binario de las muestras. Los IDs del proveedor son referencias operativas y se eliminan cuando una voz es reemplazada o retirada mediante los trabajos existentes.

## Pruebas y operación

Pruebas automatizadas relevantes:

- `AvatarImageValidatorTest`: aceptación y códigos de rechazo por rostro/calidad.
- `AvatarRepositoryTest`: demuestra que una imagen inválida no se almacena, no crea avatar, no reserva cuota y no llama al generador.
- `AvatarControllerTest`: contrato HTTP 422 traducido.
- `VideoAIServiceTest::default_image_prompt_respects_runway_character_limit`: impide enviar a Runway un prompt de imagen mayor de 1.000 caracteres.
- Typecheck, lint y build del administrador: comprueban la integración de MediaPipe y los recursos estáticos.

La colección `voitity-api/postman/voitity-api.postman_collection.json` incluye `Generate Avatar` y el caso negativo `Reject Invalid Avatar Image`. Para una prueba manual configure `avatar_image_file_path` e `invalid_avatar_image_file_path`.

Logs recomendados para diagnóstico:

```text
Avatar source image validation passed.
Avatar source image validation failed.
Avatar source image validation unavailable.
Avatar source image accepted for generation.
Avatar generation rejected by source image validation.
```

Si cambia un umbral, registre el motivo y repita el conjunto visual con imágenes válidas, sin rostro, con dos personas, oscuras, borrosas, ocluidas y con poses laterales. No use fotografías reales sin autorización en fixtures permanentes.
