# Meso Voice v2.4 Chatterbox Identity Design

## Status
Approved architecture. This design replaces further XTTS candidate exploration after the user rejected every v2.3 blind candidate as not recognizably Meso.

## Goal
Build a private, review-only Meso Voice v2.4 identity benchmark using Chatterbox Multilingual V3, grounded only in verified Meso-authored audio, with real Meso anchor recordings beside blinded synthesized candidates. Production TTS remains `meso-v2` until the user explicitly approves a candidate.

## Problem statement
XTTS has now failed the human identity criterion across repeated reference strategies, including the v2.3 multi-reference sweep. The failure is architectural: synthesis is valid, but speaker identity is not recognizably Meso. Continuing to reshuffle XTTS references would not address the observed limitation.

## Trust and privacy constraints
- Raw WhatsApp exports, transcripts, source filenames, private reference mappings, model outputs under review, and voice-reference audio remain outside Git and outside the web root.
- Only audio confidently attributable to Maissoun/Meso may enter the voice-reference pool.
- Mira/children/third-party voices are excluded.
- Persona evidence and Voice reference material remain separate stores.
- Generated output is never historical evidence and must never be described as an authentic recording of Meso.
- The Voice Lab must label real recordings as historical reference anchors and generated recordings as synthesized candidates.
- No automatic candidate promotion, ranking, winner selection, or production-profile creation.
- Production continues to resolve to `meso-v2` until an explicit user choice is processed through a separate promotion operation.

## Architecture

### 1. Private reference corpus
Use the existing Meso-only Mira voice-note staging as the starting corpus. A new v2.4 selection manifest under `C:\MesoAI\private\voice-lab-v24\` will record opaque private reference IDs and quality metadata. The manifest must not contain raw conversation text and must not be copied to Git or the public web root.

Reference selection is identity-first rather than random. Candidate inputs should prefer clean conversational clips with stable pitch, low clipping/noise, and enough continuous voiced speech to represent Meso's timbre. Arabic and English references are evaluated independently; Arabic is primary because the current verified pool is Arabic-dominant.

### 2. Chatterbox inference runtime
Reuse KDT's existing Chatterbox container/runtime patterns where possible. The Meso v2.4 runtime is isolated from production XTTS and is review-only.

The inference contract accepts:
- `text`: evaluation phrase
- `language`: `ar` or `en`
- `reference_paths`: one or more private Meso-only reference WAVs selected by the private manifest
- `output_path`: private temporary output path
- `candidate_id`: opaque review identifier

It returns structured metadata including engine name, model version, language, reference count, duration, and output status. It must not expose private file paths in browser-facing JSON.

### 3. Identity benchmark design
Each blind candidate is evaluated against one or more real historical Meso anchors.

The review set has three lanes:
- Arabic casual
- Arabic warm/emotional
- English casual

For each lane, the page presents:
- a clearly marked `Real Meso reference` anchor selected from historical Meso audio
- blinded synthesized candidates A-E generated from the same target text and one controlled reference strategy per candidate

The anchor is not synthesized and is not part of voting. It exists solely to calibrate the user's identity judgment.

Candidate labels remain anonymous. The private mapping from A-E to reference strategy stays under `C:\MesoAI\private\voice-lab-v24\`.

### 4. Browser/API boundary
Add a separate v2.4 API namespace and keep v2.3 available only as rejected historical review data.

Authenticated review requests use `/meso/api/voice-lab-v24.php`. Tokenized historical-anchor and generated-candidate audio is served only through `/meso/api/voice-lab-v24-audio.php?id=<64hex>`.

The browser receives only:
- version
- batch/lane count
- labels A-E
- generated media URLs
- anchor media URLs
- non-sensitive candidate metadata such as reference count

It never receives:
- private source IDs
- source filenames
- transcripts
- local private paths
- model-control secrets

Authenticated same-origin access remains mandatory.

### 5. Rejection and promotion behavior
Votes append to a private v2.4 vote log. `reject` is a first-class choice for every batch/lane.

Promotion is out of scope for this implementation. There is no `meso-v2.4` production profile creation in the review workflow. Any future promotion requires a separate explicit user selection and a dedicated promotion design/check.

### 6. Production isolation
Deploying v2.4 review code may add browser/API files and stage the Chatterbox review helper, but must not change:
- active profile precedence
- production TTS provider
- `meso-v2` profile contents
- Chat v2 behavior
- Persona v2 corpus

A deployment/certification gate must prove `ACTIVE_PROFILE=meso-v2` and absence of a promoted v2.4 production profile after review deployment.

## Failure handling
- If Chatterbox cannot initialize or synthesize a valid file, return a generic review-unavailable error and keep production untouched.
- If a reference is missing, ambiguous, or fails quality checks, exclude it rather than substituting another speaker or unverified audio.
- If Arabic cloning works but English identity is weak, do not average the languages into one score. Report lane-specific quality and continue only where human identity judgment supports it.
- If all Chatterbox candidates are rejected, the next architecture is Fish S2; do not fall back to another XTTS sweep.

## Acceptance criteria
1. Chatterbox v2.4 benchmark can synthesize Arabic and English review samples from private Meso-only references.
2. Real historical Meso anchors are playable beside generated candidates and are clearly distinguished from generated audio.
3. Browser receives no private paths, filenames, transcripts, or mapping data.
4. A-E candidates remain blind and votes remain private.
5. No automatic winner or promotion path exists.
6. Production TTS remains `meso-v2` after deployment and certification.
7. User may reject every Chatterbox candidate; rejection leaves production unchanged.
8. If Chatterbox is rejected, Fish S2 is the documented next architecture rather than another XTTS iteration.

## Testing strategy
- Contract tests for API authentication, status shape, anchor/candidate media tokenization, vote validation, and private-data non-disclosure.
- Unit/contract tests for Chatterbox helper input/output schema and reference-count validation.
- KDT runtime preflight for model availability, GPU/runtime compatibility, private-path access, and one Arabic + one English smoke synthesis.
- Deployment certification proving public review route health and unchanged production profile.
- Human identity acceptance remains authoritative; machine similarity metrics may be diagnostic only and cannot promote a candidate.
