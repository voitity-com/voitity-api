# Set funcional y visual: validación de avatar

## Objetivo

Demostrar que Bigmelo acepta una imagen clara de una persona y rechaza imágenes sin rostro o con más de una persona, tanto en la UI como directamente en la API. Confirmar que un rechazo no almacena el archivo, no crea avatar, no consume cuota y no inicia Runway.

## Precondiciones

1. API local, cola, base de datos y administrador en ejecución.
2. Migraciones aplicadas.
3. Credenciales temporales o perfil AWS disponible para `rekognition:DetectFaces` en `AWS_DEFAULT_REGION`.
4. Un usuario local verificado con plan que incluya al menos una generación de avatar.
5. Un perfil perteneciente al usuario.

No ejecute este set contra producción salvo que se haya autorizado expresamente el costo de Rekognition y Runway.

## Datos

| Caso | Archivo | Resultado esperado |
|---|---|---|
| Válido | `fixtures/valid-single-face.png` | MediaPipe aprueba; Rekognition aprueba; comienza generación |
| Sin rostro | `fixtures/invalid-no-face.png` | rechazo `no_face` |
| Dos rostros | `fixtures/invalid-two-faces.png` | rechazo `multiple_faces` |

## Pruebas automatizadas

Desde `voitity-api/src`:

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Unit/Classes/AvatarImageValidation/AvatarImageValidatorTest.php \
  tests/Unit/Classes/Repositories/AvatarRepositoryTest.php \
  tests/Feature/Http/Controllers/api/v1/AvatarControllerTest.php
```

Desde `voitity-admin/src`:

```bash
npm run typecheck
npm run lint
npm run build
```

Validar además que la colección de Postman sea JSON válido y ejecutar `Avatar / Reject Invalid Avatar Image` con el fixture sin rostro.

## Prueba UI: sin rostro

1. Iniciar sesión con el usuario QA.
2. Abrir el perfil y entrar a Avatar.
3. Abrir el modal de edición, seleccionar `Subir imagen` y cargar `invalid-no-face.png`.
4. Confirmar visualmente la guía de una persona, buena luz, ojos visibles y rostro sin obstrucciones.
5. Pulsar Guardar.

Esperado:

- El botón muestra `Validando rostro...` durante el análisis.
- Aparece `No se detectó un rostro...` en español (o su traducción inglesa).
- El modal permanece abierto.
- No se envía `POST /api/avatar/generate`.

## Prueba UI: dos rostros

Repetir con `invalid-two-faces.png`.

Esperado:

- Mensaje `Se detectó más de un rostro...`.
- El modal permanece abierto.
- No se llama a la API.

## Prueba UI/API: rostro válido

1. Cargar `valid-single-face.png`.
2. Mantener ambos ojos y la cabeza dentro del recorte; no acercar excesivamente.
3. Pulsar Guardar.

Esperado:

- MediaPipe permite continuar.
- `POST /api/avatar/generate` devuelve 200.
- Los logs muestran `Avatar source image validation passed.` y `Avatar source image accepted for generation.`.
- Aparece el estado `Procesando` y comienza el polling.
- Se crea un solo `ProfileAvatar` en `processing`, una reserva de consumo y una tarea Runway.
- Cuando termina la cola, el avatar pasa a `active` o muestra un error explícito del proveedor y libera la reserva.

## Prueba directa de la barrera de backend

Con Postman, seleccione `invalid-no-face.png` en `invalid_avatar_image_file_path` y ejecute `Avatar / Reject Invalid Avatar Image`.

Esperado:

- HTTP 422.
- `code=avatar_source_image_invalid`.
- `data.reason_codes` contiene `no_face`.
- `errors.image` contiene texto accionable.
- No cambia el conteo de archivos en `images/sources`, `profile_avatars` ni `subscription_uses`.

Repita con dos rostros y espere `multiple_faces`.

## Evidencia visual

Para cada caso capture:

1. Modal completo con el archivo seleccionado y la guía visible.
2. Mensaje de rechazo para los casos inválidos.
3. Estado `Procesando` para el caso válido.
4. Network panel o respuesta de Postman para la barrera de backend.
5. Extracto sanitizado de logs con request ID y reason codes; nunca adjunte bytes, base64, credenciales o respuestas biométricas completas.

Registre versiones, usuario/perfil, resultados, métricas y hallazgos en `outputs/<fecha>-<entorno>/report.md`.
