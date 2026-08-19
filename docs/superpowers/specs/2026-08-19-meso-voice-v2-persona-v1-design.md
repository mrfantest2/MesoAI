# Meso Voice v2 + Persona v1 Design

## Scope

This phase upgrades MesoAI in two isolated areas:

1. **Voice v2**: support a curated multi-reference Meso XTTS profile while preserving the existing XTTS-v2/CUDA engine, authenticated direct MP3 transport, and Android/Brave byte-range playback contract.
2. **Persona v1**: enable a conservative, source-grounded Meso persona mode in chat without inventing memories, quotations, biography, beliefs, relationships, or source facts that have not been explicitly added to the private persona evidence store.

Raw Instagram videos and derived voice-reference audio remain private and are never committed to GitHub.

## Voice v2 Architecture

The browser-facing audio path remains:

`chat reply -> /meso/api/tts.php -> local Python bridge -> khalil-xtts -> MP3 -> /meso/api/tts-audio.php -> browser`

The speaker-reference layer changes from one fixed `meso_ref_01.wav` to a versioned private manifest. The Python bridge reads only container-visible paths from `/data/voice/profiles/khalil/meso-v2/profile.json`, validates every reference remains below the service-approved root `/data/voice/profiles/khalil`, caps references to four, and sends those paths to XTTS as `speaker_wav`.

If the v2 profile is unavailable or invalid, the bridge falls back to the current reviewed Meso A reference `/data/voice/profiles/khalil/meso/refs/meso_ref_01.wav`; it never falls back to the generic Khalil speaker profile.

The deploy lane preserves all current v6 direct-media checks. It additionally validates the v2 manifest when present and reports whether production is using `meso-v2` or fallback `meso-a`.

## Persona v1 Architecture

Persona data is private and stored under:

`C:\MesoAI\private\persona-v1\profile.json`

The repository contains only a safe seed schema and style constraints; no raw media, audio, transcripts, or private facts are committed.

`web/includes/persona.php` is responsible for:

- loading the private profile;
- validating schema/version;
- building a bounded persona instruction block;
- returning a stable status object for UI/API use;
- never treating future generated conversation memory as historical evidence.

The private profile has four sections:

- `identity`: version and disclosure label;
- `style`: conservative delivery guidance inferred from supplied material;
- `sources`: source IDs/filenames and evidence status, with no fabricated transcript;
- `constraints`: explicit rules preventing invented memories, quotes, beliefs, biography, relationships, dates, or claims of being the real person.

`chat.php` enables Persona v1 by default for authorized private chat. It appends the validated Persona v1 instruction block to the existing system instructions. The model must identify itself as MesoAI when identity is relevant and must not claim to literally be Maissoun/Meso.

The API response includes:

- `persona: "meso-v1"` when active;
- `persona_sources`: count of registered historical sources;
- `persona_grounding: "style-only"` until transcript/fact evidence is added later.

## UI

The chat sidebar changes from `Persona OFF` to `Persona MESO v1` and keeps `Memory OFF`.

The composer/status copy states that Persona is source-grounded and memory remains off. Assistant metadata may include `persona · meso-v1` but does not expose private source paths.

## Source Material Included in This Phase

Five user-supplied Instagram video files are registered as source records:

- `maissoun_moussa_2951780468521252214.mp4`
- `maissoun_moussa_2951772246519486664.mp4`
- `maissoun_moussa_3358116075616966393.mp4`
- `maissoun_moussa_3398090151831079697.mp4`
- `maissoun_moussa_3546349116353945389.mp4`

Four direct-to-camera clips were selected locally as Voice v2 candidates. Their derived WAV files remain outside GitHub. The noisy shop/location clip is registered for future persona transcription but is not selected as a primary voice reference.

## Safety and Grounding Rules

Persona v1 may imitate broad conversational delivery such as directness, warmth, expressiveness, concise-to-medium answers, Arabic-first phrasing when appropriate, and natural Arabic/English code-switching.

Persona v1 must not:

- claim generated text is an authentic quote;
- claim to remember events not present in verified evidence;
- invent family, relationship, medical, political, religious, financial, or biographical facts;
- state that it is the real Maissoun/Meso;
- merge AI-generated chat memory into the historical persona source store;
- expose source file paths or private infrastructure details.

## Testing

Static CI must assert:

- Persona v1 files and markers exist;
- generic Khalil voice profile is never used by the Meso bridge;
- v2 profile path and fallback Meso A path are both present;
- direct MP3 media route and service-worker v6 remain intact;
- PHP syntax checks pass.

MASTER-PC preflight must verify:

- Persona private seed is staged outside the web root;
- `chat.php` returns `persona=meso-v1` using the local Ollama provider;
- voice bridge reports either `meso-v2` when a valid v2 private profile exists or `meso-a` fallback otherwise;
- XTTS remains healthy on CUDA;
- no raw persona/video source is copied into `C:\xampp\htdocs\meso`.
