# Informe QA posterior: referencias y adjuntos determinísticos

- Fecha: 2026-08-07
- Entorno: local; no se modificó producción ni S3
- Perfil: `QA Embeddings 20260807` (`qa-embeddings-20260807`, ID 24)
- Aislamiento: visitante y conversación nuevos para cada pregunta
- Resultado estructurado final: [`results-after-deterministic-v3.json`](results-after-deterministic-v3.json)

## Resultado ejecutivo

Los cambios mejoraron la matriz conversacional de **51/63 (80,95 %)** a **63/63 (100 %)**. Se corrigieron los 12 casos que fallaban sin hacer que el backend reemplace el texto normal de la IA: la IA sigue redactando la respuesta, mientras el backend valida las referencias y construye botones o tarjetas únicamente con registros recuperados y vigentes.

| Área | Antes | Después | Mejora |
|---|---:|---:|---:|
| Perfil | 3/3 | 3/3 | — |
| Fuentes | 9/9 | 9/9 | — |
| Redes sociales | 10/15 | 15/15 | +5 |
| Integraciones | 20/24 | 24/24 | +4 |
| Productos | 8/9 | 9/9 | +1 |
| Guía de productos | 1/3 | 3/3 | +2 |
| **Total** | **51/63** | **63/63** | **+12; +19,05 puntos porcentuales** |

La estabilización se comprobó en tres ejecuciones completas con los mismos 63 casos:

| Ejecución | PASS | FAIL | Ajuste validado |
|---|---:|---:|---|
| `results-after-deterministic.json` | 61 | 2 | Referencias sociales, productos y medios validadas |
| `results-after-deterministic-v2.json` | 62 | 1 | Recuperación de un hecho exacto de TikTok y conservación del medio elegido |
| `results-after-deterministic-v3.json` | 63 | 0 | Inferencia segura de una referencia omitida por el modelo |

## Funcionamiento comprobado

- La respuesta en lenguaje natural continúa siendo generada por la IA.
- El modelo puede declarar referencias estructuradas de tipo `social_link`, `integration_media` y `product`.
- El backend rechaza IDs que no estén disponibles y, cuando RAG está activo, también los que no hayan sido recuperados para esa pregunta.
- Para una solicitud de red social, el backend adjunta el enlace canónico de la base de datos y genera el CTA localizado, por ejemplo `Ir a YouTube`; no depende de que el modelo copie correctamente la URL.
- Para integraciones, conserva una referencia válida del modelo. Si el modelo la omite, solo la infiere cuando pregunta, respuesta y contenido recuperado comparten evidencia factual fuerte; la similitud vectorial por sí sola no adjunta una tarjeta.
- Para productos, conserva referencias válidas y puede recuperar tarjetas cuando la respuesta menciona exactamente productos que estaban dentro del contexto recuperado.
- Si el modelo contradice un dato recuperado con una respuesta de “no tengo información”, el backend puede usar el dato canónico recuperado, sin inventar información externa.

## Pruebas técnicas

- API completa: **840 pruebas y 4.084 aserciones aprobadas** con PHPUnit.
- Formato PHP focalizado: 7 archivos aprobados por Pint.
- Admin: typecheck, lint y build aprobados.
- Matriz funcional real con embeddings: **63 PASS, 0 FAIL, 0 BLOCKED**.
- Consola del navegador: sin errores en las vistas de Admin y los dos chats visuales.
- El comando `php artisan test` agotó el límite local de 128 MB durante la suite completa; la misma suite se ejecutó directamente con PHPUnit y 512 MB y terminó correctamente usando 162,5 MB. No fue un fallo de aserciones.
- Advertencia no bloqueante existente: algunos chunks de Rollup del Admin superan 500 KB.

## Validación visual

- [`14-public-chat-deterministic-social.jpg`](screenshots/14-public-chat-deterministic-social.jpg): “¿Dónde puedo ver tus videos largos?” produjo texto coherente y el botón `Ir a YouTube` con la URL canónica.
- [`15-public-chat-deterministic-other.jpg`](screenshots/15-public-chat-deterministic-other.jpg): la pregunta por el boletín Río produjo texto de IA, adjuntó el registro correcto de “Otro” y mostró `Leer en el blog`.
- El estado accesible del Admin confirmó `2/Sin límite seleccionadas`; la captura limpia se descartó porque la guía de publicación activa cubría visualmente esa sección.

## Hallazgos finales

1. **Sí mejoraron los resultados:** se recuperaron los 12 casos fallidos y la cobertura pasó a 100 % en esta matriz.
2. Los errores originales no eran pérdida de embeddings. Los chunks correctos existían; fallaban la clasificación de intención, la prioridad de la guía o la decisión del modelo de devolver/omitir el adjunto.
3. Separar redacción y presentación funcionó: el mensaje sigue siendo natural, pero URL, etiqueta, tarjeta e ID proceden de datos validados por el backend.
4. La inferencia de adjuntos quedó deliberadamente conservadora. No se adjunta contenido solo porque sea el vecino vectorial más cercano; debe existir intención y evidencia de uso en la respuesta.
5. La generación sigue siendo probabilística. El resultado de 63/63 demuestra la matriz definida y sus paráfrasis actuales, no todas las preguntas posibles. Conviene conservar esta matriz como regresión obligatoria al cambiar prompts, modelo, umbral o indexación.
6. La matriz funcional completa se ejecutó en español. Las etiquetas sociales en inglés están cubiertas por pruebas unitarias, pero una matriz funcional equivalente en inglés sería una ampliación útil.
7. El fixture `other_1` reutiliza `qa-card-ambar.png`, que contiene visualmente “AMBAR-58”, aunque el registro, descripción y enlace son `OTRO-RIO-70`. Esto no es un error de selección del chat, pero sí hace confusa la evidencia visual. Conviene crear un activo `qa-card-rio` propio para futuras ejecuciones.

## Alcance y seguridad

- No se usó Instagram, conforme al alcance de QA definido.
- TikTok continuó usando fixtures locales; no se autorizó una cuenta externa.
- Cada pregunta usó un chat nuevo, por lo que ninguna respuesta dependió del historial de otra prueba.
- No se escribieron contraseñas, tokens ni `chat_token` en los resultados.
- No se realizó ningún cambio en producción.
