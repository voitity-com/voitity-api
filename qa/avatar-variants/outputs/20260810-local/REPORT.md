# Resultado QA local: versiones del avatar

- Fecha: 2026-08-10
- Entorno: local (`voitity-admin` en `http://localhost:3000`, API en `http://localhost:8000`)
- Perfil inspeccionado: `2`
- Navegadores/idiomas: interfaz real en español e inglés

## Resultado

Todos los escenarios de la matriz pasaron:

| Escenario | Resultado |
|---|---|
| Proceso completo | Se mostraron Original, Imagen mejorada y Animación como disponibles. Las tres se pudieron activar desde la interfaz. |
| Fallo de mejora | Solo Original quedó disponible; Imagen mejorada apareció como Falló y Animación como No generada. Original se pudo activar. |
| Fallo de animación | Original e Imagen mejorada quedaron disponibles; Animación apareció como Falló. La mejorada se pudo activar. |
| Presentación del selector | Las tres versiones se muestran como círculos de 112 px, superpuestas sobre el historial desenfocado. No se navega a otra sección y la selección se realiza pulsando directamente el círculo disponible. |
| Visibilidad del avatar | Los estados Disponible, No disponible, En uso y Falló aparecen debajo de cada círculo y no cubren la imagen ni el rostro. |
| Selección persistida | Después de cada activación, `profile_avatars.file`, `selected_variant` y el estado activo coincidieron con la versión elegida. |
| Idiomas | Los títulos, estados, mensajes y acciones se mostraron correctamente en español e inglés. |
| Datos históricos | Un avatar anterior sin `original_file` mostró Original como No disponible y conservó sus versiones relacionadas. |

Las activaciones funcionales quedaron registradas en `storage/logs/laravel.log` con el mensaje `Avatar version activated.` y las variantes `original`, `enhanced` y `animation`.

## Evidencia

- `01-history-grid.png`: un círculo por intento y distintivo Parcial.
- `03-original-active.png`: original reflejada en el avatar principal.
- `04-enhanced-active.png`: mejorada reflejada en el avatar principal.
- `05-animation-active.png`: animación reflejada en el avatar principal.
- `09-restored-profile-state.png`: perfil 2 restaurado al estado previo a la prueba.
- `10-circular-overlay-complete.png`: selector circular con las tres versiones disponibles y el historial desenfocado.
- `11-circular-overlay-video-failed.png`: selector circular con original y mejorada disponibles, y animación fallida.
- `12-circular-overlay-image-failed.png`: selector circular con original en uso, mejora fallida y animación no generada.
- `13-status-below-avatar.png`: estados debajo de los círculos sin cubrir los avatares.

Las capturas del diseño anterior con tarjetas grandes se conservaron de forma recuperable en `archive/`, pero ya no representan la interfaz vigente.

## Limpieza

Los tres intentos sintéticos y sus relaciones `AiImage`/`AiVideo` se eliminaron al terminar. El avatar que estaba activo antes de la prueba (`profile_avatar_id=12`, variante `animation`) quedó restaurado. No se invocaron proveedores externos ni se consumieron créditos durante esta validación visual.

## Observación fuera de alcance

La página mostró `No se pudo cargar el estado de publicación` antes, durante y después de esta prueba. Es un estado preexistente de la cabecera del perfil y no afectó el flujo de variantes del avatar.
