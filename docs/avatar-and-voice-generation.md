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

### 2. Validación obligatoria en la API

`POST /api/avatar/generate` mantiene las validaciones de archivo del `GenerateAvatarRequest` y luego aplica el siguiente orden:

1. Autenticación, autorización sobre el perfil y disponibilidad del plan.
2. Comprobación de que no exista otro avatar en procesamiento.
3. `AvatarImageValidator` decodifica la imagen con GD, elimina metadatos al normalizarla a JPEG y llama a `AvatarImageValidationClient`.
4. El adaptador `RekognitionAvatarImageValidationClient` ejecuta `DetectFaces` con `Attributes=ALL` y bytes en memoria. La API de Rekognition es stateless: Bigmelo no crea colecciones faciales ni indexa identidades.
5. Solo después de aprobar se guarda la fuente, se crea `ProfileAvatar`, se reserva el consumo y se llama a Runway.

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

Si Rekognition no está disponible, se devuelve HTTP 503 con `avatar_image_validation_unavailable`. En ambos casos no se almacena la imagen, no se crea `ProfileAvatar`, no se consume cuota y no se llama a Runway.

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
2. Crea un `ProfileAvatar` en estado `processing`.
3. Reserva una imagen y los segundos de video definidos por `AvatarGenerationSpecification`.
4. Envía a Runway la referencia junto al prompt de imagen y modelo configurados en `config/videoai.php`.
5. Emite `AiImageForAvatarCreated`.

El procesamiento posterior es asíncrono:

1. `GetAIImageForAvatar` consulta la tarea, descarga la imagen generada al disco de perfiles y emite `AiImageForAvatarGenerated`.
2. `CreateAiVideoForAvatar` crea el clip con el modelo, prompt y duración configurados, y emite `AiVideoForAvatarCreated`.
3. `GetAIVideoForAvatar` consulta la tarea, guarda el video, activa el nuevo avatar, inactiva el anterior y finaliza el consumo reservado.
4. En fallos o timeouts se marca el avatar como `failed`, se libera la reserva y se notifica al dueño del perfil y a los administradores.

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
