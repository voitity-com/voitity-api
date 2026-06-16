# Reusable Prompts

Use these prompts when asking Codex to work in this repo.

## Implement API Endpoint

```txt
En este repo Laravel, implementa el endpoint [describe endpoint].

Antes de editar, revisa rutas, controller, modelos, requests, responses y tests
relacionados.

Requisitos:
- Metodo/ruta:
- Parametros:
- Ability requerida:
- Regla admin/owner:
- Respuesta esperada:
- Paginacion:
- Orden:
- Casos de error:

Tambien agrega o actualiza:
- pruebas Feature
- Swagger si aplica
- Postman si cambia la API publica

Manten el estilo actual del proyecto, no hagas refactors no relacionados y al
final corre el test mas especifico.
```

## Code Review

```txt
Revisa este cambio como code review.

Prioriza bugs, regresiones, problemas de autorizacion, rendimiento,
validaciones faltantes y pruebas faltantes.

No propongas refactors cosmeticos. Dame hallazgos con archivo/linea,
severidad y razon concreta.
```

## Fix Bug

```txt
Hay un bug en [endpoint/flujo].

Contexto:
- Endpoint/flujo:
- Input:
- Resultado actual:
- Resultado esperado:

Reproduce el problema revisando el codigo relacionado, encuentra la causa raiz,
corrige el codigo, agrega una prueba que falle antes del fix y verifica con
PHPUnit.
```

## Change Chat Runtime

```txt
Modifica el flujo de chat de Voitity.

Antes de editar revisa:
- MessageController
- ProfileChatController
- ProcessStoredMessage
- AnswerBuilder
- ChatAIClient
- OpenAIClient
- modelos Profile, Chat y Message

No mezcles logica del proveedor OpenAI con reglas de negocio. Si hay que
cambiar prompts, contexto, documentos, memoria o skills, intenta aislar esa
logica en un builder o servicio reutilizable.
```

## Add Third-Party Service Adapter

```txt
Implementa un nuevo driver para [ChatAIService|VideoAIService|VoiceService]
usando el patron adaptador existente.

Antes de editar revisa:
- la interface Client del servicio
- el Manager
- el config del servicio
- el ServiceProvider
- los DTO/result objects
- el driver existente
- los tests del manager, provider, DTOs y service wrapper

Requisitos:
- No llames la API real en tests.
- Usa Http::fake o mocks.
- Manten payloads, headers y parsing dentro del adapter.
- Devuelve los DTOs existentes o crea uno nuevo si la interface lo exige.
- Agrega config/env para credenciales, modelo, base URL y defaults.
- Agrega pruebas de exito, error HTTP y exception cuando aplique.
```
