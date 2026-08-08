# Fixtures

Todos los datos son sintéticos y usan códigos centinela para detectar recuperación correcta sin depender de redacción exacta.

- `sources/source-orquidea.txt`: `ORQUIDEA-41`
- `sources/source-faro.md`: `FARO-82`
- `sources/source-nebula-text.txt`: `NEBULA-73`
- `media/qa-card-ambar.png`: PNG sintético, SHA-256 `ecb9e5e83cf00f16fa9c3e5bc8d6262d664f6cefc2e79575586fe7e04b0ec00d`
- `media/qa-motion-selva.mp4`: MP4 sintético, SHA-256 `7b3106fd97b6d75b4201f0249a21d9356a79188b0f4c01a2ba1c4d1cc83e80cf`

El video se puede regenerar en macOS con:

```bash
swift scripts/generate-synthetic-video.swift fixtures/media/qa-motion-selva.mp4
```
