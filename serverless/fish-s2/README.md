# MesoAI Fish S2 Serverless preflight

This directory contains the **preflight-only** RunPod Serverless worker for MesoAI's real Maissoun reply voice.

## Invariants

- Canonical Fish source: `fishaudio/fish-speech`
- Fish source pin: `e5e292632cb11e7a27b2b7487f58f612bc101e13`
- Model: `fishaudio/s2-pro`
- One GPU per worker.
- Minimum verified VRAM: 23,000 MiB.
- No permanently warm worker after preflight; the endpoint must return to scale-to-zero.
- No persistent/network volume is required for Maissoun private data.
- Private reference WAV/transcript are supplied only per authenticated job.
- Private reference bytes are not written to the worker filesystem.
- Fish `use_memory_cache` is set to `off` for private requests.
- The browser never receives the reference WAV or RunPod API key.
- Existing browser TTS remains the fallback until a real Fish WAV passes the end-to-end smoke test.

## Serverless request

Health (contains no private data):

```json
{"input":{"mode":"health"}}
```

Private synthesis requests are created only by the MASTER-PC bridge and contain:

```text
text
reference_audio_b64
reference_text
reference_sha256
```

The worker returns the generated WAV as base64 plus its SHA-256. The MASTER-PC bridge must verify the WAV header and hash before exposing it to `web/api/tts.php`.

## Model image

The immutable worker image contains only public Fish source/runtime material and the public `fishaudio/s2-pro` model weights. The image is built while network access is available, verifies `checkpoints/s2-pro/codec.pth`, and then runs Hugging Face/Transformers in offline mode. RunPod cached-model storage remains an optional fail-closed fallback in the handler, but the primary preflight does not depend on a console-only model-cache setting.

No Maissoun reference WAV, transcript, Fish license acceptance record, private manifest, or speaker material is copied into the image.

## Promotion rule

Do not merge this preflight branch or switch the live Meso TTS endpoint to Serverless until all of the following are green:

1. Python/source invariants.
2. Reproducible Docker build with the public S2-Pro checkpoint baked and verified.
3. Registry pull verification.
4. RunPod Serverless endpoint with one bounded preflight worker and maximum one worker.
5. Synthetic health job with >=23,000 MiB VRAM and no private data.
6. One private Maissoun WAV smoke with the expected reference SHA-256.
7. MASTER-PC verification of returned WAV/hash.
8. Browser `/meso/api/tts.php` real Fish playback smoke.
9. Endpoint returned to `workersMin=0`, scale-down confirmed, and billing bounded.
