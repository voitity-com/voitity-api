# Set funcional y visual: versiones del avatar

## Objetivo

Comprobar que cada intento aceptado conserva las versiones producidas y permite activar únicamente archivos disponibles. Fuera de `APP_ENV=local`, un rechazo facial del API no debe aparecer en el historial; en local la validación remota se omite.

## Matriz

| Caso | Historial | Original | Mejorada | Animación | Consumo esperado |
|---|---|---:|---:|---:|---|
| Validación facial rechazada | No aparece | No | No | No | Sin reserva |
| Falla la mejora | Aparece parcial | Disponible | Falló | No generada | Reserva liberada |
| Falla el video | Aparece parcial | Disponible | Disponible | Falló | 1 imagen, 0 segundos |
| Proceso completo | Aparece completo | Disponible | Disponible | Disponible | 1 imagen y duración del video |

## API

1. Consultar `GET /api/avatar/{profile}/history`.
2. Verificar por intento `generation_status`, `selected_variant` y las claves `variants.original`, `variants.enhanced` y `variants.animation`.
3. Activar cada archivo disponible con `POST /api/avatar/{profile}/activate`:

```json
{
  "avatar_id": 123,
  "variant": "original"
}
```

4. Repetir con `enhanced` y `animation` cuando estén disponibles.
5. Confirmar que el API devuelve 422 para una variante inexistente o no producida y que nunca acepta una URL del cliente.
6. Consultar el endpoint público y comprobar que `data.file` coincide con la última variante seleccionada.

## Administrador

1. Abrir Avatar y seleccionar `Avatares anteriores`.
2. Confirmar que cada círculo representa un intento, no una variante.
3. Abrir un intento y verificar las tres opciones circulares: Original, Imagen mejorada y Animación. Deben conservar el tamaño del historial, mostrarse superpuestas y desenfocar la cuadrícula inferior. Los estados deben aparecer debajo de cada círculo y nunca cubrir el rostro.
4. Confirmar estados Disponible, Procesando, Falló, No generada y No disponible según la matriz.
5. Activar cada versión disponible y comprobar el distintivo `En uso`, el avatar principal y la web pública.
6. Cambiar a inglés y repetir la inspección de textos.

## Datos antiguos

En intentos anteriores a la migración, la tarjeta Original puede indicar No disponible. No se debe inferir la asociación con archivos antiguos por fecha o nombre. Las relaciones existentes con `AiImage` y `AiVideo` sí deben mostrarse.

## Pruebas automatizadas mínimas

Desde `voitity-api`:

```bash
docker compose exec -T app php -d memory_limit=512M vendor/bin/phpunit \
  tests/Unit/Classes/Repositories/AvatarRepositoryTest.php \
  tests/Feature/Http/Controllers/api/v1/AvatarControllerTest.php \
  tests/Unit/Listeners/AI/Images/GetAIImageForAvatarTest.php \
  tests/Unit/Listeners/AI/Videos/CreateAiVideoForAvatarTest.php \
  tests/Unit/Listeners/AI/Videos/GetAIVideoForAvatarTest.php
```

Desde `voitity-admin/src`:

```bash
npm run typecheck
npm run lint
npm test -- --runInBand --watch=false
npm run build
```

Guardar capturas y hallazgos en `qa/avatar-variants/outputs/<fecha>-<entorno>/`.
