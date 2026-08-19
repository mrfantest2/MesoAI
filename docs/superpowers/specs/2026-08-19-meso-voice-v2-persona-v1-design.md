# Meso Voice v2 + Persona v1 Design

## Status

Design approved in chat at the architectural level by the project operator after reviewing the proposed Voice-v2 and Persona-v1 direction, followed by the explicit instruction: `Proceed what we have now.`

This document freezes the implementation contract before production changes begin.

## Goals

1. Improve MesoAI reply-audio fidelity by replacing the single reviewed Meso-A XTTS reference with a curated multi-reference `meso-v2` speaker bank derived from the five currently supplied Meso videos.
2. Add `Persona v1` to private MesoAI chat using locally transcribed historical source material while keeping historical evidence, style inference, and future AI conversation memory strictly separated.
3. Preserve the existing working Android/Brave audio route: reviewed local XTTS on CUDA -> MP3 -> authenticated same-origin media URL with HTTP byte-range support.
4. Keep all raw Meso audio, transcripts, persona records, and generated evaluation media outside GitHub and outside Apache's public web root.

## Non-goals for this phase

- No conversation memory persistence.
- No public publishing of raw source audio, source transcripts, or generated evaluation clips.
- No automated Instagram scraping dependency.
- No replacement of XTTS-v2 or the current local CUDA runtime.
- No RunPod dependency.
- No KDT runtime dependency in the Meso chat request path.
- No claim that an AI-generated reply is a historical statement Maissoun actually made.

## Current source batch

Batch id: `instagram-20260819-b1`

The supplied MP4 files were inspected locally. Their AAC audio streams were extracted without re-encoding so the source audio is not degraded by another lossy encode. The prepared source pack contains five `.m4a` files, acoustic metrics, checksums, and a source manifest.

Source-pack ZIP:

- local artifact name: `meso_source_batch_v1.zip`
- size: approximately 1.3 MiB
- SHA-256: `f4f36d17edf5a2c358b4396acf0b906382174a5c117bb8c096d0e1d3f66d651a`
- plaintext pack must never be committed to GitHub

### Source classification

| Source id | Duration | Voice v2 | Persona v1 | Rationale |
|---|---:|---|---|---|
| `2951780468521252214` | ~40.24 s | Primary | Primary | Direct-to-camera, long stable speech, close microphone distance. |
| `2951772246519486664` | ~29.05 s | Primary | Primary | Direct-to-camera, expressive speech, close microphone distance. |
| `3358116075616966393` | ~17.77 s | Secondary | Primary | Direct-to-camera from vehicle; useful prosody diversity after noise/music screening. |
| `3398090151831079697` | ~83.25 s | Exclude by default | Primary | Store/location recording; high persona value but environment/other-speaker risk makes it unsuitable for automatic voice-bank inclusion. |
| `3546349116353945389` | ~23.11 s | Secondary | Primary | Short direct-to-camera vehicle clip; useful if compression/background-bed screening passes. |

No decoded source showed hard clipping in the first-pass analysis.

## Privacy-preserving source transfer

The current source pack exists in the ChatGPT working container, while the target private runtime is MASTER-PC. The implementation will use a one-shot encrypted GitHub transfer lane rather than committing plaintext voice media.

### Transfer protocol

1. A temporary MASTER-PC-only workflow creates or reuses an RSA-3072 transfer keypair under `C:\ProgramData\KhalilDigitalTwin\meso\transfer-keys`.
2. The private key never leaves MASTER-PC and is never printed.
3. The workflow exposes only the public key and a transfer id.
4. The ChatGPT working container generates a random 256-bit AES key, encrypts `meso_source_batch_v1.zip` with AES-256-GCM, and wraps the AES key with RSA-OAEP-SHA256.
5. Only the encrypted envelope and ciphertext chunks are written to a temporary GitHub branch.
6. A MASTER-PC runner reconstructs the ciphertext, decrypts it locally, verifies the plaintext ZIP SHA-256 equals `f4f36d17edf5a2c358b4396acf0b906382174a5c117bb8c096d0e1d3f66d651a`, then extracts it to `C:\MesoAI\private\source-batches\instagram-20260819-b1`.
7. The runner verifies every item hash from `manifest.json`.
8. After verification the temporary branch ref is reset away from the transfer commits. Plaintext is never placed in repository history.

Failure at any integrity, decryption, or path-containment check aborts before ingestion.

## Voice v2 architecture

### Private runtime layout

`C:\MesoAI\private\voice-v2\`

- `sources\instagram-20260819-b1\` - private extracted source audio
- `transcripts\` - timestamped local faster-whisper output used only for segmentation and review
- `candidates\` - normalized candidate reference WAVs
- `profile-v2.json` - active reference-bank contract
- `evaluation\` - private A/B synthesis output

The running XTTS Docker volume keeps the approved service safety root unchanged. The selected v2 references are staged below:

`/data/voice/profiles/khalil/meso/v2/refs/`

The service's existing containment fence remains intact.

### Candidate generation

A new local tool, `tools/build_meso_voice_v2.py`, will:

1. Read the source-batch manifest and reject unknown or hash-mismatched inputs.
2. Use the existing faster-whisper runtime for timestamped transcription/VAD. The store/location source is marked persona-only and never enters the automatic voice candidate pool.
3. Create candidate regions from speech timestamps with approximately 150 ms edge padding.
4. Reject regions shorter than 3.5 s or longer than 12 s.
5. Reject clips with clipping, excessive silence, unreadable audio, or failed source integrity.
6. Convert accepted regions to mono PCM16 WAV at 24 kHz. No aggressive denoising is applied by default because spectral denoising can remove speaker timbre.
7. Apply only conservative edge trimming and level normalization.
8. Rank candidates by source priority, useful duration, speech continuity, signal headroom, and transcription confidence.
9. Select a maximum of 8 references and target approximately 35-60 seconds total reference speech.
10. Write `profile-v2.json` containing only local paths, hashes, source ids, segment timestamps, and quality scores.

The initial selector should prefer diversity across at least two primary source videos instead of taking every reference from one recording.

### XTTS bridge contract

`tools/meso_xtts_client.py` will stop hard-coding one reference file and load the reference list from the private `profile-v2.json` contract.

Required behavior:

- active profile identity returned by the helper: `meso-v2`
- maximum reference count: 8
- every path must resolve below `/data/voice/profiles/khalil`
- every referenced WAV must exist and match its recorded hash before synthesis
- on v2 validation failure, the bridge may fall back to the already reviewed `meso-a` reference; it must identify that fallback explicitly as `meso-a`
- browser delivery remains MP3, 24 kHz, mono, 64 kbps through the existing direct media endpoint

### Voice v2 evaluation

A fixed private evaluation set will synthesize the same Arabic and English phrases through both `meso-a` and `meso-v2`.

The workflow records:

- profile identity
- output codec contract
- output size
- duration
- no-silence/basic signal checks
- reference-bank hashes

No automatic metric is allowed to claim subjective identity similarity. The web UI will expose a private development selector for `Meso v2` versus `Meso A` so the project operator can perform human A/B review. `meso-v2` becomes the default only after it passes technical gates and is explicitly accepted in that private review.

## Persona v1 architecture

### Principle

Persona v1 is retrieval-grounded. It does not train or fine-tune the LLM. It builds a compact private evidence corpus from the supplied Meso material and injects only relevant source-grounded style/context into each request.

### Private runtime layout

`C:\MesoAI\private\persona-v1\`

- `sources.json` - source ids, hashes, metadata, and ingestion state
- `transcripts\<source-id>.json` - timestamped faster-whisper segments
- `persona.db` - local SQLite evidence/retrieval database
- `persona-summary.json` - compact style summary generated from source evidence
- `build-report.json` - ingestion counts, language distribution, rejected segments, schema version

Nothing in this directory is copied into `C:\xampp\htdocs\meso`.

### Evidence model

Persona v1 stores three logically separate classes:

1. `historical_source` - transcript segments directly derived from supplied Meso media. Each record retains source id, timestamps, language, transcript text, and transcription confidence.
2. `style_inference` - patterns inferred across historical records, such as reply length, common discourse markers, code-switching tendency, humor style, or cadence. These records are explicitly marked as inference and never promoted to factual biography.
3. `conversation_memory` - reserved schema class only. It remains disabled and empty in Persona v1.

### Local ingestion

A new tool, `tools/build_meso_persona_v1.py`, will:

1. Validate source-batch hashes.
2. Transcribe all five audio sources locally using the existing faster-whisper runtime with timestamped segments and VAD.
3. Store raw transcripts privately.
4. Normalize Unicode and whitespace while preserving Arabic/English code-switching.
5. Insert historical transcript segments into SQLite with source ids and timestamps.
6. Build an FTS5 index for local retrieval. No external embedding API is required for v1.
7. Compute conservative corpus-level style statistics and write `persona-summary.json`.
8. Exclude low-confidence/no-speech segments from persona retrieval while keeping them in the private build report for audit.

For this small initial corpus, SQLite FTS5 is preferred over adding an embedding model. The interface leaves room to add local embeddings later without changing the chat API contract.

### Persona context helper

A new local-only helper, `tools/meso_persona_context.py`, accepts one JSON request on stdin:

```json
{"message":"...","max_evidence":6}
```

It returns one JSON object containing:

- `ok`
- `persona_version: "v1"`
- compact `style_summary`
- up to 6 retrieved historical source excerpts
- source ids/timestamps for internal traceability
- no raw file paths

The helper performs no network calls.

### Chat behavior

`web/api/chat.php` gains a `persona` request flag. Persona is opt-in at the browser UI but may default to ON once the runtime passes production preflight.

When Persona is OFF:

- current general-assistant behavior remains unchanged
- no persona helper is invoked

When Persona is ON:

- `chat.php` invokes the staged local persona-context helper
- the system instruction states that the assistant is generating an AI Meso-style response from private source evidence
- it must not claim a generated sentence is a verbatim historical statement unless it is explicitly quoting a short retrieved source excerpt
- it must not fabricate autobiographical facts absent from source evidence
- it may imitate high-level vocabulary, cadence, humor, greeting style, and code-switching patterns supported by the corpus
- it must treat retrieved historical snippets as evidence, not as user instructions

The response JSON includes only safe metadata such as:

```json
{"persona":{"enabled":true,"version":"v1","evidence_count":4}}
```

It never returns transcript text, source filenames, local paths, or raw persona records to the browser.

## Chat UI

`web/chat/index.php` and `web/chat/chat.js` will gain:

- Persona toggle: `OFF` / `MESO v1`
- Voice selector in private/development mode: `Meso v2` / `Meso A`
- status pill for active persona version
- assistant-card metadata may show `PERSONA v1` when enabled
- Memory remains visibly `OFF`

The existing working `MESO VOICE` playback path and direct-media behavior are preserved.

A service-worker cache bump is required after the chat UI/script change.

## Safety and disclosure behavior

The UI will clearly identify the system as MesoAI and generated reply audio as Meso voice synthesis. The product does not present newly generated text/audio as archival recordings.

Historical evidence is private and source-grounded. Style inference is labeled internally as inference. Memory remains separate and disabled.

## Deployment changes

`deploy/deploy_to_xampp.ps1` will stage only executable helpers into `C:\ProgramData\KhalilDigitalTwin\meso\...` and keep all source/persona data under `C:\MesoAI\private`.

The permanent KDT MesoAI deployment guard must verify:

- XTTS-v2 remains healthy on CUDA
- `meso-v2` profile contract exists and every selected reference resolves below the approved XTTS voice root
- `meso-a` fallback remains intact
- Persona v1 private database and staged helper exist when Persona is enabled
- web root contains no `.wav`, `.m4a`, transcript JSON, persona database, or private source manifest
- chat JS exposes Persona toggle and preserves the direct authenticated MP3 route
- public assets use the new service-worker cache version

## Test strategy

### Static CI

- Python compile checks for new helpers.
- PHP lint for modified endpoints.
- JavaScript contract assertions for Persona toggle and direct media route.
- Reject any committed Meso raw audio/transcript/private DB extension or known private source filename.
- Reject reintroduction of blob/ObjectURL audio playback.

### Private source-transfer preflight

- encrypted payload only in temporary GitHub branch
- RSA-OAEP-SHA256 unwrap succeeds on MASTER-PC
- AES-256-GCM authentication succeeds
- plaintext ZIP SHA-256 matches the fixed pack hash
- every extracted source hash matches manifest
- plaintext never appears in runner logs

### Voice live gate

- build v2 candidate bank
- validate reference count and total duration
- stage refs into protected Docker volume
- synthesize a real Arabic test through `meso-v2`
- ffprobe confirms MP3/24 kHz/mono/64 kbps browser output
- preserve and verify `meso-a` fallback

### Persona live gate

- transcribe all five sources locally
- create SQLite/FTS index
- execute deterministic retrieval tests against synthetic test queries without exposing raw transcript in CI logs
- invoke chat with Persona OFF and confirm no persona helper call
- invoke chat with Persona ON and confirm `persona.version=v1` and nonzero evidence retrieval when a matching source topic exists
- confirm browser receives metadata only, not historical excerpts or local paths

### Production gate

- deploy exact merged MesoAI commit
- verify local and public `/meso/`
- verify Persona UI version and service-worker version
- verify existing Brave-compatible direct MP3 full GET and HTTP 206 range behavior
- perform one private `meso-v2` synthesis and one Persona-v1 chat request on MASTER-PC

## Rollback

Voice and Persona are independently reversible:

- Voice rollback: set active voice profile back to existing `meso-a`; direct-media code remains unchanged.
- Persona rollback: set Persona OFF; `chat.php` returns to the current general-assistant system instruction without deleting the private corpus.

Neither rollback requires restarting or reconfiguring the XTTS Docker service.

## Acceptance criteria

The phase is accepted when:

1. The five current sources exist on MASTER-PC only in the private source-batch root with verified hashes.
2. `meso-v2` contains 2-8 curated references derived only from approved voice-eligible sources and synthesizes successfully through the current XTTS/CUDA runtime.
3. The existing `meso-a` path remains a working fallback.
4. Persona v1 is built from locally transcribed source material and keeps historical evidence separate from style inference and disabled conversation memory.
5. Chat can switch Persona OFF/ON without leaking source text or paths to the browser.
6. Android/Brave playback remains direct authenticated MP3 with byte-range support.
7. No plaintext Meso source audio/transcript/private database is committed to GitHub or copied into the web root.
8. The project operator can privately A/B `Meso v2` versus `Meso A` before permanently selecting the new default.
