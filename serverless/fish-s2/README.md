# MesoAI Fish S2 Serverless preflight

This directory contains the **preflight-only** RunPod Serverless worker for MesoAI's real Maissoun reply voice.

## Invariants

- Canonical Fish source: `fishaudio/fish-speech`
- Fish source pin: `e5e292632cb11e7a27b2b7487f58f612bc101e13`
- Model: `fishaudio/s2-pro`
- One GPU per worker.
- Minimum verified VRAM: 23,000 MiB.
- Worker count: scale from 0; no permanently warm worker during preflight.
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

## Model cache

The worker expects RunPod's cached-model mount for `fishaudio/s2-pro` at `/runpod-volume/huggingface-cache/hub`. It runs Hugging Face/Transformers in offline mode and fails closed if the cache is not mounted.

## Promotion rule

Do not merge this preflight branch or switch the live Meso TTS endpoint to Serverless until all of the following are green:

1. Python/source invariants.
2. Reproducible Docker build.
3. Registry pull verification.
4. RunPod Serverless endpoint with 0 minimum / 1 maximum worker.
5. Synthetic health job with >=23,000 MiB VRAM.
6. One private Maissoun WAV smoke with the expected reference SHA-256.
7. MASTER-PC verification of returned WAV/hash.
8. Browser `/meso/api/tts.php` real Fish playback smoke.
9. Endpoint idle scale-down confirmed and billing bounded.
