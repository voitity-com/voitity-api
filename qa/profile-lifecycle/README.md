# QA del ciclo completo de perfiles

## Objetivo

Validar de forma reproducible el recorrido de un usuario nuevo o existente desde el inicio de sesión hasta la publicación de un perfil, incluidos los límites del plan, edición del chat, datos, integraciones y navegación de un administrador.

## Entorno local

- Admin: `http://localhost:3000`
- API: `http://localhost:8000`
- Web público: `http://localhost:3001`
- Escritorio de referencia: `1440 x 900`
- Móvil de referencia: `390 x 844`

La prueba automatizada debe usar SQLite en memoria y proveedores simulados. No se deben guardar contraseñas, tokens, cookies ni claves de proveedores en los artefactos de QA.

## Escenarios de usuario

Crear cuentas locales desechables con etiquetas reconocibles, sin reutilizar usuarios reales:

| Escenario | Rol | Suscripción | Perfiles iniciales |
| --- | --- | --- | --- |
| `NEW_NO_PLAN` | user | ninguna | 0 |
| `STARTER_EMPTY` | user | Starter activa | 0 |
| `STARTER_EXISTING` | user | Starter activa | 1 |
| `ADMIN_MULTI` | admin | Admin activa | 2 o más |

## Regresión automatizada de API

Desde la raíz de `voitity-api`:

```bash
qa/profile-lifecycle/run-api-regression.sh
```

La ejecución debe confirmar explícitamente que la base es SQLite en memoria. Cubre perfiles, publicación, avatar, voz, muestras de voz, fuentes, redes, integraciones, productos, chats, insights, límites de suscripción y administración.

## Casos funcionales de navegador

### Plan y entrada

| ID | Acción | Resultado esperado |
| --- | --- | --- |
| PLAN-01 | Iniciar sesión como `NEW_NO_PLAN` | Abre `/dashboard/settings/billing` sin tutorial superpuesto. |
| PLAN-02 | Abrir `/dashboard/profiles` sin plan | Redirige a Facturación y planes. |
| PLAN-03 | Abrir directamente `/dashboard/profiles/{id}` sin plan | Muestra el aviso de plan requerido y el enlace `Adquirir plan`; no muestra datos del perfil. |
| PLAN-04 | Pulsar `Adquirir plan` | Abre Facturación y planes con las opciones disponibles. No completar pagos en QA local. |
| ENTRY-01 | Abrir `/dashboard/profiles` con plan y sin perfiles | Abre automáticamente el formulario de creación. |
| ENTRY-02 | Abrir `/dashboard/profiles` con perfiles | Abre el último perfil visitado; si no existe, el más reciente. |

### Creación, edición y contenido

| ID | Acción | Resultado esperado |
| --- | --- | --- |
| PROFILE-01 | Crear un perfil como `STARTER_EMPTY` | Guarda el perfil y abre `/dashboard/profiles/{id}`. |
| PROFILE-02 | Editar nombre desde el lápiz del chat | Guarda y refleja el nombre inmediatamente en el chat. |
| SOCIAL-01 | Agregar una red desde el lápiz de redes | Guarda y muestra el enlace inmediatamente. |
| TEMPLATE-01 | Elegir otro template | Guarda, cierra el modal y actualiza el chat. |
| MESSAGE-01 | Cambiar el mensaje inicial | Guarda y muestra el nuevo mensaje al cerrar el modal, sin conservar una conversación previa en el preview administrativo. |
| AVATAR-01 | Abrir Editar avatar, cargar una imagen válida y guardar | Valida una sola cara, crea la versión y permite seleccionarla. En automatización, usar proveedor simulado. |
| VOICE-01 | Abrir Editar voz, clonar y probar | Crea la voz y permite reproducir una muestra. En automatización, usar proveedor simulado; la captura real requiere permiso explícito. |
| DATA-01 | Agregar una fuente | La fuente recorre sus estados hasta quedar indexada o informa el fallo. En automatización, usar embeddings simulados. |
| INTEGRATION-01 | Abrir Integraciones y guardar una integración habilitada | Persiste la configuración y respeta las funcionalidades habilitadas del perfil. |
| PRODUCT-01 | Crear, editar y eliminar un producto | Cada operación se refleja en la lista y en el API. |
| CHAT-01 | Abrir Chats, Calidad e Insights | Las vistas cargan sin error y muestran sus estados vacíos o métricas. |
| SETTINGS-01 | Abrir Configuración | Cargan funcionalidades, widget y dominio sin abandonar el perfil. |

### Publicación y administración

| ID | Acción | Resultado esperado |
| --- | --- | --- |
| PUBLISH-01 | Publicar un perfil listo | Cambia a publicado y el enlace público responde. |
| PUBLISH-02 | Despublicar el perfil | Cambia a oculto y deja de estar disponible públicamente. |
| PUBLISH-03 | Volver a publicar | Recupera el contenido público. |
| ADMIN-01 | Abrir el selector como `ADMIN_MULTI` | Lista todos los perfiles accesibles y muestra `Crear perfil`. |
| ADMIN-02 | Cambiar de perfil | Actualiza URL, alias y chat al perfil elegido. |
| ADMIN-03 | Crear otro perfil | Permite crearlo y navegarlo porque el plan admite múltiples perfiles. |
| LIMIT-01 | Abrir el selector con Starter y un perfil | No muestra `Crear perfil` cuando el plan llegó a su límite. |

## Casos visuales y móviles

1. En `390 x 844`, el chat debe conservar separación lateral visible y no desbordar horizontalmente.
2. El menú compacto debe mostrar solo iconos.
3. Tocar cualquier parte del menú compacto debe abrir el panel blanco expandido.
4. Tocar fuera del panel debe cerrarlo.
5. En un dispositivo táctil real, arrastrar desde el borde derecho hacia la izquierda debe abrirlo; un desplazamiento vertical normal no debe activarlo.
6. Cada modal debe caber en el viewport, permitir desplazamiento interno y conservar una acción de cierre visible.
7. `Versión web` solo debe aparecer en escritorio y abrir un chat de tamaño grande.

## Evidencia mínima

- salida de la regresión API;
- salida de lint, typecheck y build de admin;
- salida de build y pruebas de web;
- captura de escritorio del chat publicado;
- captura móvil del chat y del menú expandido;
- captura móvil del bloqueo sin plan;
- registro de casos aprobados, parciales y pendientes, sin secretos.

