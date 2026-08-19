# Meso Voice v2 + Persona v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable a conservative source-grounded Meso Persona v1 and add private multi-reference Voice v2 support without regressing the current XTTS/CUDA/direct-MP3 Android playback path.

**Architecture:** Persona v1 is loaded from a private JSON profile and injected into chat system instructions through a focused PHP include. Voice v2 is represented by a private container-side manifest containing up to four Meso-only reference WAV paths, with reviewed Meso A as the only fallback. Raw videos and derived references never enter GitHub or the public web root.

**Tech Stack:** PHP 8, browser JavaScript, Python 3, XTTS-v2, Docker, FFmpeg, Ollama/OpenAI provider abstraction, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-19-meso-voice-v2-persona-v1-design.md`

## Global Constraints

- Raw Instagram videos and derived voice audio must never be committed to GitHub.
- Persona v1 historical evidence and future AI memory remain separate.
- Persona v1 must not invent memories, quotes, beliefs, biography, or relationships.
- Meso voice must never fall back to the generic Khalil speaker profile.
- Existing XTTS-v2/CUDA runtime and authenticated direct MP3 + HTTP 206 range playback remain unchanged.
- Service-worker cache remains at least `meso-app-shell-v6` unless changed by a later browser-facing asset update.

---

### Task 1: Persona profile loader and private seed

**Files:**
- Create: `web/includes/persona.php`
- Create: `deploy/persona-v1.seed.json`
- Modify: `deploy/deploy_to_xampp.ps1`
- Test: `.github/workflows/meso-xtts-route-preflight.yml`

**Interfaces:**
- Produces: `meso_persona_status(): array` and `meso_persona_instructions(): string`.
- Private profile path: `C:\MesoAI\private\persona-v1\profile.json`.

- [ ] **Step 1: Write failing static CI assertions** requiring `persona.php`, the private seed path, `meso_persona_instructions`, `meso-v1`, and a prohibition on invented memories/quotes.
- [ ] **Step 2: Run PR CI and verify it fails** because persona files/markers do not exist.
- [ ] **Step 3: Implement `persona.php`** with schema validation, bounded strings, safe default-off behavior if profile is malformed, and a stable status structure.
- [ ] **Step 4: Add `persona-v1.seed.json`** registering the five source filenames as untranscribed historical source records and conservative style guidance only.
- [ ] **Step 5: Stage the seed privately during deployment** only when no existing persona profile exists; never overwrite a richer future profile.
- [ ] **Step 6: Re-run static CI and PHP syntax checks** and require PASS.

### Task 2: Enable Persona v1 in chat

**Files:**
- Modify: `web/api/chat.php`
- Modify: `web/chat/chat.js`
- Modify: `web/chat/index.php`
- Test: `.github/workflows/meso-xtts-route-preflight.yml`

**Interfaces:**
- Consumes: `meso_persona_status()` and `meso_persona_instructions()`.
- Produces response fields: `persona`, `persona_sources`, `persona_grounding`.

- [ ] **Step 1: Add failing CI assertions** requiring persona loader inclusion, response metadata, UI `MESO v1`, `Memory OFF`, and no legacy `Persona off` copy.
- [ ] **Step 2: Verify CI fails** on the current chat implementation.
- [ ] **Step 3: Update chat system instructions** to append Persona v1 only when the validated private profile is enabled; keep explicit non-impersonation and no-fabrication constraints.
- [ ] **Step 4: Return persona metadata** without exposing source paths.
- [ ] **Step 5: Update chat UI/status copy** so Persona shows `MESO v1` while Memory remains OFF.
- [ ] **Step 6: Re-run PHP/static CI** and require PASS.

### Task 3: Add Voice v2 multi-reference manifest support

**Files:**
- Modify: `tools/meso_xtts_client.py`
- Modify: `deploy/deploy_to_xampp.ps1`
- Test: `.github/workflows/meso-xtts-route-preflight.yml`

**Interfaces:**
- Reads optional container profile `/data/voice/profiles/khalil/meso-v2/profile.json`.
- Returns bridge metadata `profile: "meso-v2"` when v2 is valid or `profile: "meso-a"` on reviewed fallback.

- [ ] **Step 1: Add failing CI assertions** requiring `meso-v2/profile.json`, maximum four references, Meso A fallback, and absence of `/data/voice/profiles/khalil/profile.json`.
- [ ] **Step 2: Verify CI fails** before implementation.
- [ ] **Step 3: Implement manifest loading/validation** inside the Docker container using only resolved files under `/data/voice/profiles/khalil`.
- [ ] **Step 4: Preserve reviewed Meso A fallback** when v2 profile is absent/invalid.
- [ ] **Step 5: Allow `tts.php`/browser profile validation** to accept `meso-v2` and `meso-a` while still rejecting generic/unknown profiles.
- [ ] **Step 6: Re-run compile/static CI** and require PASS.

### Task 4: MASTER-PC live preflight and production deployment

**Files:**
- Modify: `mrfantest2/Khalil-Digital-Twin/.github/workflows/mesoai-deploy-bridge.yml` in a separate KDT PR after MesoAI merge.

**Interfaces:**
- Production web root: `C:\xampp\htdocs\meso`.
- Private persona path: `C:\MesoAI\private\persona-v1\profile.json`.
- XTTS local health: `http://127.0.0.1:8020/health`.

- [ ] **Step 1: Run exact MesoAI branch on MASTER-PC** and validate Persona seed staging plus local Ollama chat response metadata.
- [ ] **Step 2: Verify current voice fallback** remains `meso-a` if Voice v2 references are not yet physically staged on MASTER-PC.
- [ ] **Step 3: Merge MesoAI PR only after CI + MASTER-PC preflight pass.**
- [ ] **Step 4: Deploy exact merged MesoAI commit** via MASTER-PC runner.
- [ ] **Step 5: Validate public assets, Persona metadata, XTTS health, and direct MP3/206 route.**
- [ ] **Step 6: Harden permanent KDT deploy gate** so future deployments enforce Persona v1 privacy and Meso-only voice-profile rules.

### Task 5: Private Voice v2 reference activation

**Files:**
- Private only, not GitHub: `C:\MesoAI\private\voice-v2\...` and Docker volume `/data/voice/profiles/khalil/meso-v2/...`.

**Interfaces:**
- Prepared local pack contains four PCM16 mono 24 kHz WAV references plus a manifest.

- [ ] **Step 1: Transfer the prepared private Voice v2 pack to MASTER-PC without publishing it in GitHub.**
- [ ] **Step 2: Verify SHA-256 values against the local pack manifest.**
- [ ] **Step 3: Stage four references and `profile.json` atomically into the protected XTTS Docker data volume.**
- [ ] **Step 4: Run a real Arabic and English XTTS synthesis and require bridge metadata `profile=meso-v2`.**
- [ ] **Step 5: Compare Voice v2 against Meso A in the existing private Voice Lab before making v2 the stable default.**
