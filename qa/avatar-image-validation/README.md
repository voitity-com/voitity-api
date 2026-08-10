# QA de validación de imagen de avatar

Este paquete contiene el conjunto reproducible para comprobar la validación facial previa a la generación de un avatar.

- Plan: `AVATAR_IMAGE_VALIDATION_QA.md`.
- Fixtures sintéticos: `fixtures/`.
- Evidencias e informe de una ejecución: `outputs/<fecha>-<entorno>/`.

Ningún fixture representa a una persona real conocida. Las dos fotografías fueron generadas con la herramienta integrada de generación de imágenes; el caso sin rostro se genera de forma determinística.

## Prompts de los fixtures generados

`valid-single-face.png`:

> Retrato fotográfico de estudio, claramente sintético, de exactamente una persona adulta ficticia; cabeza y hombros visibles, mirando de frente, ojos abiertos, sin gafas ni obstrucciones, fondo gris claro, luz uniforme, enfoque nítido, rostro centrado y con margen, sin texto, logos ni marca de agua.

`invalid-two-faces.png`:

> Fotografía de estudio, claramente sintética, con exactamente dos personas adultas ficticias lado a lado; ambos rostros completos, separados, mirando de frente, ojos abiertos, sin obstrucciones, fondo gris claro, luz uniforme, enfoque nítido, sin texto, logos ni marca de agua.

Ambos fixtures se generaron con la herramienta integrada `image_gen`. Se conservan dentro del workspace para que QA no dependa del directorio de salida de Codex.
