# Informe de ejecución: validación de imagen de avatar

Fecha: 2026-08-10  
Entorno: local (`voitity-admin` en `localhost:3000`, `voitity-api` en `localhost:8000`)  
Resultado general: **APROBADO**

## Alcance

Se probó la prevalidación local de MediaPipe, la barrera obligatoria de AWS Rekognition en el API, la ausencia de efectos laterales para imágenes inválidas y el flujo completo de Runway para una imagen válida. También se verificaron los textos en español e inglés, los logs sanitizados, la colección de Postman y las suites automatizadas.

## Datos de prueba

- Usuario QA local: `qa.avatar.validation.20260810@bigmelo.com` (`user_id=105`).
- Suscripción Starter local: `subscription_id=71`.
- Perfil creado desde la interfaz: `Perfil QA Avatar 20260810` (`profile_id=41`, alias `qa-avatar-20260810`).
- Avatar generado: `profile_avatar_id=38`.
- Fixtures sintéticos y no asociados con personas conocidas:
  - `fixtures/valid-single-face.png`.
  - `fixtures/invalid-no-face.png`.
  - `fixtures/invalid-two-faces.png`.

## Resultados funcionales y visuales

| Caso | Resultado | Evidencia |
|---|---|---|
| Guía previa a la carga | Aprobado. Explica una persona, nitidez, vista frontal, iluminación, ojos visibles y rostro libre. | `01-avatar-upload-guide.png` |
| Imagen sin rostro, español | Aprobado. MediaPipe bloqueó el guardado con texto accionable y mantuvo abierto el modal. | `02-invalid-no-face.png` |
| Imagen con dos rostros, español | Aprobado. MediaPipe bloqueó el guardado con el mensaje específico de varios rostros. | `03-invalid-two-faces.png` |
| Imagen válida | Aprobado. Pasó MediaPipe y Rekognition; la UI mostró el estado de procesamiento. | `04-valid-processing.png` |
| Generación completa | Aprobado. Runway generó imagen y video; el avatar terminó `active`. | `05-valid-active.png` |
| Imagen sin rostro, inglés | Aprobado. Guía, controles y rechazo se mostraron traducidos. | `06-invalid-no-face-en.png` |

Los rechazos de MediaPipe no llamaron a `POST /api/avatar/generate`. Después de ambos intentos inválidos el perfil conservaba cero avatares, cero consumos de avatar y la unidad disponible del plan.

La imagen válida sí atravesó el backend. Rekognition reportó exactamente un rostro con confianza 100, proporción de rostro `0.1317`, centro aproximado `(0.5001, 0.5040)`, nitidez `92.23` e iluminación `85.64`. La validación ocurrió antes de almacenar la fuente, reservar consumo o llamar a Runway.

El flujo asíncrono terminó de esta forma:

- `ProfileAvatar 38`: `active`, sin código ni motivo de fallo.
- `AIImage 29`: `succeeded`.
- `AIVideo 31`: `succeeded`, duración de 2 segundos.
- `SubscriptionUse 1568`: `finalized`, una imagen y dos segundos de video cubiertos por el plan.

## Prueba real aislada del proveedor

Después de cerrar las suites se ejecutó nuevamente el validador final contra AWS Rekognition:

- `valid-single-face.png`: aceptada, un rostro, confianza 100, nitidez `95.52` e iluminación `84.98`.
- `invalid-no-face.png`: rechazada con `reason_codes=["no_face"]`.

La prueba es detección stateless. No creó colecciones, identidades ni enrollment facial en AWS.

## Pruebas automatizadas

| Verificación | Resultado |
|---|---|
| Casos focalizados de validador, repositorio y controlador | 23 pruebas, 85 aserciones, aprobadas |
| Suite completa del API | 847 pruebas, 4.102 aserciones, aprobadas |
| Laravel Pint sobre archivos modificados | Aprobado |
| `composer validate --strict` | Aprobado |
| TypeScript `tsc --noEmit` | Aprobado |
| ESLint sin warnings | Aprobado |
| Jest del administrador | 1 suite, 5 pruebas, aprobadas |
| Build de producción Vite | Aprobado |
| JSON de traducciones ES/EN | Aprobado |
| JSON de la colección de Postman | Aprobado |
| `git diff --check` en API, admin y web | Aprobado |

## Logs verificados

Se observaron los eventos esperados:

- `Avatar source image validation passed.`
- `Avatar source image validation failed.`
- `Avatar source image validation unavailable.`
- `Avatar source image accepted for generation.`
- `Avatar image generation started.`
- `Subscription usage reserved.` y `Subscription usage finalized.`

Los eventos de validación incluyen proveedor, request ID, duración, códigos de motivo y métricas redondeadas. No registran la imagen, bytes, base64, landmarks ni credenciales.

## Hallazgos operativos

1. **No se encontraron defectos funcionales o visuales en los casos ejecutados.** Los tres fixtures produjeron el resultado esperado y el caso válido completó el pipeline real.
2. El contenedor local no tenía inicialmente credenciales AWS y el backend respondió correctamente con 503. Para la prueba se expuso temporalmente al contenedor el perfil local de AWS; se retiró al finalizar. En producción debe existir un rol con `rekognition:DetectFaces` en la región configurada.
3. MediaPipe es una mejora de experiencia, no la barrera de seguridad. Si el runtime del navegador falla, el backend sigue validando obligatoriamente con Rekognition.
4. Los recursos locales de MediaPipe evitan depender de un CDN, pero agregan aproximadamente 21 MB de WASM/modelo a los archivos estáticos. Debe comprobarse que el hosting entregue `.wasm` con un MIME compatible.
5. Vite conserva la advertencia general de chunks mayores a 500 kB; el chunk de Avatar quedó alrededor de 158 kB. No bloquea la compilación.
6. Los gestores de paquetes informan advisories pendientes en dependencias del proyecto (Composer y npm). No se modificaron automáticamente porque no forman parte de esta funcionalidad y requieren una revisión de actualización separada.

## Conclusión

La validación está lista localmente: ofrece respuesta inmediata en el navegador y defensa obligatoria en el API, no cobra ni inicia generación ante un rechazo del backend, registra evidencia operativa segura y permite completar el avatar cuando la fotografía cumple los requisitos. Antes de desplegar se debe configurar el permiso IAM de Rekognition y repetir este set en el entorno de staging.
