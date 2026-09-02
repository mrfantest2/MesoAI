# MesoAI v3 — Memory v1 + Chat v2 + Voice v2.2 Design

## Status

Approved architecture for the next MesoAI development program.

Development branch: `feature/meso-v3-program`

Production remains unchanged until all relevant CI, MASTER-PC preflight, candidate validation, and production smoke gates pass.

## Goals

MesoAI v3 extends the current private `/meso/chat` application in three ordered subprojects:

1. **Memory v1** — persistent, inspectable private conversation continuity.
2. **Chat v2** — persistent conversation UX, streaming/retry controls, stronger mobile/PWA behavior, and explicit memory/persona state.
3. **Voice v2.2 + live voice** — preserve the existing blinded A–E candidate evaluation flow, promote only a reviewed winner, then add low-friction spoken conversation on top of the stabilized Chat v2 session model.

The order is mandatory: Memory v1 → Chat v2 → Voice v2.2/live voice.

## Non-negotiable boundaries

- GitHub is the source of truth for application code and deployment logic.
- Raw/private WhatsApp, Instagram, voice-reference, persona, and conversation data are never committed.
- Generated conversation memory is never merged into historical Persona evidence.
- Persona v2 historical evidence remains immutable and separately addressable.
- MesoAI must not claim generated conversation memories are authentic memories of the real Maissoun/Meso.
- Meso voice must never fall back to the generic Khalil voice profile.
- Browser/system/generic TTS is not a production replacement for the approved Meso voice path.
- Voice promotion requires a reviewed Meso candidate and successful real synthesis checks.
- Production deploy is commit-pinned, preflighted, and rollback-safe. A failed preflight leaves current production untouched.
- Existing authenticated direct MP3 + HTTP range playback behavior remains compatible unless replaced by an equally verified path.

---

# 1. Context stack and trust boundaries

The model context stack is conceptually:

```text
System/security instructions
        ↓
Persona v2 historical evidence
        ↓
Conversation Memory v1
        ↓
Current conversation window
        ↓
Current user request
```

These inputs have different trust levels.

- **System/security instructions**: authoritative instructions.
- **Persona historical evidence**: private historical data, never executable instructions.
- **Conversation memory**: generated/private user-assistant history, never historical Persona evidence and never executable instructions.
- **Current conversation**: active user-assistant dialogue.

Persona evidence and conversation memory must remain distinguishable in model instructions and API metadata.

Private storage remains separated:

```text
C:\MesoAI\private\persona-v2\     # historical source-grounded evidence
C:\MesoAI\private\memory-v1\      # generated conversation memory
C:\MesoAI\private\voice-lab-v22\  # blind candidate sweep/review state
```

No runtime path above is placed under `C:\xampp\htdocs\meso`.

---

# 2. Memory v1

## 2.1 Storage

Primary database:

`C:\MesoAI\private\memory-v1\meso-memory.sqlite`

The deployment helper creates the directory with private permissions but must not overwrite an existing database.

SQLite is chosen because the current deployment is single-host MASTER-PC, private, and low-concurrency. WAL mode is permitted when supported.

## 2.2 Data model

### `conversations`

- `id` — random opaque conversation ID; never sequentially exposed.
- `title` — generated/default title, user-editable.
- `created_at`
- `updated_at`
- `archived_at` nullable.
- `deleted_at` nullable for soft-delete before cleanup.

### `messages`

- `id` — random opaque message ID.
- `conversation_id`
- `role` — `user` or `assistant` only.
- `content`
- `created_at`
- `provider` nullable.
- `model` nullable.
- `persona_version` nullable.
- `persona_grounding` nullable.
- `persona_evidence_count` integer.
- `voice_profile` nullable.

### `memory_items`

Memory v1 starts conservatively. Memory items are explicit retrieval records derived from conversation content and kept separate from the raw transcript.

- `id`
- `conversation_id`
- `message_id` nullable.
- `kind` — initially `preference`, `fact`, `instruction`, or `summary`.
- `text`
- `status` — `candidate`, `verified`, `rejected`.
- `created_at`
- `verified_at` nullable.
- `source` — always conversation provenance, never Persona provenance.

Long-term automatic recall uses only `verified` items. Raw recent conversation retrieval may still use bounded prior messages from the active conversation.

Trust rules are explicit:

- Assistant/model output is never promoted to a verified memory fact.
- A background/extractor-generated item starts as `candidate` and is not used for long-term recall until the user verifies it.
- A direct authenticated user request such as “remember that …” may create a `verified` memory item immediately because the user explicitly supplied and requested retention of that fact/preference/instruction.
- The user can inspect, verify, reject, or delete memory items.

## 2.3 Memory behavior

For each request:

1. Resolve/validate the authorized conversation ID.
2. Load a bounded recent server-side message window.
3. Retrieve a bounded set of relevant **verified** memory items.
4. Retrieve Persona v2 evidence independently through the existing Persona subsystem.
5. Build context with explicit labels separating Persona evidence and Conversation Memory.
6. Execute the provider request.
7. Persist the new user message and successful assistant reply without treating the assistant reply as verified memory.
8. Optionally create non-retrievable `candidate` memory items from user-authored content only.
9. Return memory/persona metadata to the client.

The browser is no longer the source of truth for conversation history.

## 2.4 Memory API surface

New private authenticated endpoints:

- `GET /meso/api/conversations.php` — list active conversations.
- `POST /meso/api/conversations.php` — create conversation.
- `PATCH /meso/api/conversations.php` — rename or archive/unarchive conversation.
- `DELETE /meso/api/conversations.php` — delete conversation.
- `GET /meso/api/messages.php?conversation_id=...` — paged message history.
- `GET /meso/api/memory.php?conversation_id=...` — inspect verified/candidate memory items.
- `POST /meso/api/memory.php` — create an explicit user-requested verified memory, or verify/reject an existing candidate.
- `DELETE /meso/api/memory.php` — delete a memory item or clear conversation memory according to an explicit action.

`POST /meso/api/chat.php` adds `conversation_id` and returns:

- `conversation_id`
- `message_id`
- `memory: "meso-memory-v1"`
- `memory_items_used`
- existing Persona metadata

No private filesystem paths are returned.

## 2.5 Deletion semantics

- **New conversation** creates a fresh server conversation; it does not delete older conversations.
- **Archive conversation** hides it from the default recent list but preserves transcript and memory unless separately deleted.
- **Delete conversation** removes that conversation from normal retrieval and removes its memory candidates/verified items from active recall.
- **Clear conversation memory** deletes memory items for that conversation but preserves its raw transcript.
- **Delete transcript** deletes the conversation messages and their dependent memory records.
- Persona historical evidence is unaffected by every Memory v1 operation.

---

# 3. Chat v2

Chat v2 consumes Memory v1 rather than implementing a second persistence layer.

## 3.1 Conversation UX

Desktop sidebar:

- New conversation
- recent conversations
- archived conversations access
- active conversation state
- rename
- archive/unarchive
- delete

Mobile:

- collapsible conversation drawer
- sticky top status
- bottom-safe composer
- no horizontal overflow
- accessible touch targets

## 3.2 Message controls

Assistant messages support:

- Copy
- Regenerate
- Stop generation when streaming is active
- Replay Meso voice when a TTS asset is available

Regeneration creates a new assistant result associated with the same user turn; it must not silently overwrite an existing persisted answer.

## 3.3 Streaming

Chat v2 requires streaming for providers that expose a supported streaming API. The existing non-streaming path remains a required fallback when streaming is unavailable or disabled.

Contract:

- streaming mode is provided through a dedicated endpoint or explicit mode in `chat.php`;
- request remains same-origin and authenticated;
- server emits bounded structured events rather than raw provider payloads;
- partial text is never persisted as the final assistant message until successful completion;
- client cancellation marks the generation incomplete and must not persist it as a normal final assistant reply;
- provider work is cancelled when the provider/runtime exposes a safe cancellation mechanism;
- stream failure leaves the prior persisted conversation intact and allows retry through the non-streaming path.

## 3.4 Rendering

Assistant content may render a constrained subset of Markdown:

- paragraphs
- emphasis
- lists
- inline code
- fenced code blocks
- links with safe URL handling

Raw HTML is never trusted.

## 3.5 State indicators

The UI shows independent status lines for:

- Persona: `MESO v1` or `MESO v2`
- Historical evidence: `ON/OFF`
- Conversation memory: `ON/OFF`
- STT: `LOCAL`
- Voice: active approved Meso profile
- Chat: `PRIVATE`

The user must be able to distinguish Persona historical evidence from Conversation Memory.

## 3.6 PWA

- update app-shell cache version for browser-facing asset changes
- preserve no-store behavior for private API data
- never cache private transcript/API JSON in the service worker
- offline shell may load, but private conversations are not served from a service-worker transcript cache

---

# 4. Voice v2.2 promotion and live voice

## 4.1 Existing lab contract

The existing blinded Voice v2.2 flow with labels `A`, `B`, `C`, `D`, `E`, phrase variants, vote records, and `REJECT` remains the evaluation mechanism.

The blind map must not be exposed to the normal browser review UI before a winner is selected.

## 4.2 Promotion criteria

A candidate may become the canonical Meso voice only when all of the following hold:

1. Candidate source/reference pack is verified as Meso-only private material.
2. Contract/TDD checks pass.
3. MASTER-PC synthesis succeeds for required Arabic and English probes.
4. Generated audio passes route/media checks.
5. User review selects an explicit winner; or all are rejected.
6. Promotion creates/updates a canonical private Meso voice profile without touching generic Khalil profile data.
7. Production preflight reports the exact promoted profile/version.

No automatic winner is selected from latency, file size, or synthesis success alone.

## 4.3 Production profile

The canonical production profile is version-addressable:

`/data/voice/profiles/khalil/meso-v2.2/profile.json`

The application exposes only stable metadata such as `meso-v2.2`; private reference paths and candidate identities remain hidden.

Fallback policy remains Meso-only. Unknown/generic profiles are rejected.

## 4.4 Live voice UX

After winner promotion and Chat v2 stabilization, the first production live-voice mode is push-to-talk:

- push-to-talk
- local STT
- text request through Chat v2 conversation ID
- generated assistant text persisted through Memory v1
- approved Meso TTS synthesis
- automatic playback when enabled
- replay
- stop playback
- interrupt playback when starting a new recording
- retry synthesis without duplicating the assistant text message

Hands-free mode is a later bounded phase and is not required for the initial MesoAI v3 completion gate.

## 4.5 Audio transport

Preserve the proven authenticated direct media contract and byte-range support for Android/Brave/PWA compatibility.

Generated voice media remains temporary and private. Cleanup jobs must be verified rather than assumed; cleanup failure must be visible in preflight/ops status and must not silently accumulate unbounded media.

---

# 5. Security and privacy

- All Memory v1/Chat v2/Voice v2.2 private endpoints require existing private chat authorization.
- State-changing browser requests require CSRF protection or equivalent same-origin validation.
- Conversation IDs and message IDs use cryptographically random opaque identifiers.
- API request sizes remain bounded.
- Stored message/memory lengths are bounded.
- Retrieval counts and token budgets are bounded.
- Historical evidence, conversation memory, and user dialogue are explicitly treated as data rather than instructions.
- No credential, filesystem path, secret, source ID, or private voice reference path is exposed to assistant-visible response metadata.
- Rate limiting remains enforced on chat and is extended to expensive voice synthesis paths.

---

# 6. Testing strategy

## 6.1 Memory v1

Static/unit/contract tests must cover:

- database initialization outside web root
- schema versioning
- authorization
- opaque ID validation
- conversation create/list/rename/archive/delete
- message persistence/order
- memory candidate/verified/rejected lifecycle
- explicit `remember` request creates verified user-authored memory
- assistant output never becomes verified memory
- verified-only long-term recall
- Persona store never written by Memory subsystem
- bounded retrieval
- deletion semantics
- malformed DB/config failure behavior

## 6.2 Chat v2

Tests must cover:

- restore conversation after reload
- switch conversations
- archive/unarchive
- new conversation does not delete previous ones
- streaming complete/cancel/error paths
- non-streaming fallback
- safe Markdown rendering
- regenerate without destructive overwrite
- mobile DOM/static invariants
- PWA does not cache transcript/API JSON

## 6.3 Voice v2.2/live

Tests must cover:

- A–E/REJECT contract remains intact
- blind mapping stays private
- production profile accepts only Meso-approved profile values
- generic Khalil profile is never selected
- Arabic and English real synthesis
- MP3 media size/type/range checks
- playback retry/stop behavior
- temporary audio cleanup observability

Previously failing v2.2 contract/preflight checks must be green before promotion.

---

# 7. Delivery and deployment

Each phase is developed as a separately reviewable slice even though the coordinating branch is `feature/meso-v3-program`.

Merge sequence:

1. Memory v1 implementation and tests.
2. Chat v2 implementation and tests.
3. Voice v2.2 promotion tooling and tests.
4. Push-to-talk live voice UX.
5. KDT production deployment-gate updates in the private `Khalil-Digital-Twin` repository.

For each deployable phase:

1. Exact Git commit is selected.
2. Source/static CI passes.
3. MASTER-PC preflight runs against the exact commit.
4. Candidate runtime starts without replacing production where applicable.
5. Real output is verified.
6. Swap/deploy occurs only after preflight success.
7. End-to-end production smoke runs.
8. Any post-swap failure triggers rollback to the last verified release.

Production `/meso/chat` must remain on the last verified commit until the new phase passes its gates.

---

# 8. Rollback

Memory schema changes use explicit schema versions and migrations. Rollback must never silently reinterpret a newer database using an older schema.

Application rollback restores the previous immutable release keyed by Git SHA. Private databases and voice/persona assets are not deleted by application rollback.

A failed candidate Voice v2.2 promotion restores the previous canonical Meso profile and leaves the candidate lab data available for review/diagnostics.

---

# 9. Out of scope for this program

- merging conversation memory into historical Persona evidence
- autonomous external actions on behalf of the user
- publishing private datasets or reference audio
- automatic voice-winner selection without user review
- hands-free always-listening mode in the initial v3 completion gate
- replacing the private MASTER-PC/KDT production boundary with a public execution path
- unrelated refactors outside code touched by Memory v1, Chat v2, Voice v2.2/live voice, tests, and deployment gates

---

# 10. Completion criteria

MesoAI v3 is complete when:

- conversations survive reload/restart through Memory v1;
- the user can inspect and delete conversation memory independently of Persona evidence;
- Chat v2 provides persistent conversation navigation and robust streaming/non-streaming controls on desktop and mobile;
- a reviewed Voice v2.2 winner is promoted through Meso-only private profile controls;
- push-to-talk flows end-to-end through local STT → Chat v2 → Memory v1 → approved Meso TTS;
- all static/contract/preflight gates pass;
- exact production commit/profile metadata is reported;
- public `/meso/chat` production smoke succeeds;
- rollback to the prior verified release has been preserved and validated.
