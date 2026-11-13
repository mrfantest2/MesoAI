# MesoAI for Majid and Mira — QA/recovery checkpoint (2026-11-13)

## Purpose and disclosure
MesoAI is an AI-assisted memorial and family-history experience informed by authentic, verified Maissoun records. It is not the real Maissoun, does not claim she is alive, and must not impersonate a parent or replace a trusted caregiver. Generated replies and generated voice must be identified as synthetic, distinct from verified original recordings.

## Source of truth
- Canonical repository: `mrfantest2/MesoAI`, baseline main SHA `1174a11bb06d101b7f5a44b41a3d523d2b949b4d`.
- Work in isolated feature branch, preserve originals and untracked private files.
- Public paths: `/meso/`, `/meso/chat/`, and separate memorial `/maisoonmemorial/`. All returned HTTP 200 when checked from MASTER-PC.
- Cloudflare `fantest.win` zone active; root hostname proxied.
- Current local XTTS helper is available under `C:\MesoAI\runtime`; existing code used an absent KDT path. Local port 8020 was **not listening**. No successful inference test or fidelity acceptance in this checkpoint.
- An Instagram profile URL was provided for research. The profile endpoint was reachable at check time, but no timeline/post corpus was verified or imported.

## Audience feature
- At each fresh page session and each new conversation, ask whether the speaker is `majid` (Captain Majid / كابتن ماجد) or `mira` (نور العين).
- Version-2 SQLite conversation schema stores audience per conversation, with an upgrade for existing version-1 databases.
- Historical conversations without an audience remain preserved as legacy records; they cannot be used as an already identified child chat.
- Chat generation context identifies the selected child and explicitly distinguishes AI from original Maissoun.
- Verified *conversation memory* retrieval is limited to the same audience. Legacy memories are not included in child recalls. Historical Persona evidence remains a separate, carefully labeled source.
- A shared login/invite is **not** per-child authentication; independent access segregation is still required for confidentiality between accounts.

## Evidence and audio gates
- Preserve all original WhatsApp archives, originals, speaker labeling, 11 prior audio/transcript pairs, and derived outputs locally. Do not commit/export them or publish original chat text.
- Previous XTTS v2.2/v2.3 fidelity comparisons were rejected; `meso-v2` remains the rollback baseline. Do not promote any candidate automatically.
- The source archive for each child's private messages has not been positively reconciled; remembered phrases and song lyrics are leads, **not** verified historical quotes.
- Prefer low-resource local tests and verified, consented speech references. Any future synthesized voice requires explicit synthetic-voice disclosure and blind human fidelity review. Never start an intensive model without checking PC load and scheduler constraints.

## Release gates (HOLD)
- [ ] PHP lint, SQLite v1-to-v2 migration, memory-isolation tests, JS syntax, responsive visual QA.
- [ ] Verify the deployed source identity, origin and rollback path before any production change.
- [ ] Test authenticated API (chat streaming, error codes, invite flow, new-identity selector), including separate browser sessions for Majid and Mira.
- [ ] Validate permission model and limit personal information exposure to children.
- [ ] Confirm XTTS or alternative speech works, meets quality expectations and is correctly labeled.
- [ ] Audit existing Android project, build an actual signed APK, test on emulator, OP7/S25, checksum, and publish only a verified download URL.
- [ ] Ensure automatic QA tasks are non-overlapping, with 20-minute execution limit and Codex quota preflight when invoking Codex. No heavy GPU work in this checkpoint.

No public deployment, voice promotion, new APK, or external WhatsApp/Instagram data export is authorized or claimed by this checkpoint.

## Recovery QA addendum — 2026-11-13 (safe isolated development)

### Authoritative revisions and branches
- Main: `1174a11bb06d101b7f5a44b41a3d523d2b949b4d` (November 2, loopback Ollama relay). Main did not include November 13 audience work.
- November 13 feature: `0c1d661a69220a8b7822ab2b0daa3fd5e6e10b81`; draft PR #24 remains open and unmerged.
- Original source checkout: `D:\Projects\MesoAI\MesoAI-source`, branch `feat/chat-mobile-lite-20260911`, HEAD `aa5b2b4`; clean at beginning and at final comparison.
- Validation worktree: `D:\Projects\MesoAI\worktrees\meso-qa-20261113`, derived from feature commit. All changes in this addendum are isolated from the original checkout and production.
- Previous streaming, Persona v2, and voice v2.2–v2.4 branches inspected for branch presence. The live Laragon implementation matches `recovery/laragon-native-xtts-20260921` for tested chat/auth/API paths, not the GitHub `main` version. Reconcile that branch before production integration.

### Production read-only verification on MASTER-PC
- `GET /meso/`: HTTP 200; title `MesoAI Voice Lab`.
- `GET /meso/chat/` (no cookie): HTTP 200 with Set-Cookie. **Critical authentication defect still in production**: anonymous direct navigation auto-provisions a signed shared session.
- `GET /meso/api/status.php`: HTTP 200.
- Anonymous `GET /meso/api/persona-status.php`, `conversations.php`, `messages.php`: HTTP 403 without cookie. These checks alone are insufficient because chat page grants the cookie.
- `GET /meso/api/tts.php`: HTTP 405 (POST-only), not proof of voice operation.
- `GET /meso/chat/chat.js` and `/meso/sw.js`: HTTP 200.
- No authenticated production message was submitted; live streaming, actual model reply, mobile viewport, session expiry and TTS playback remain unverified. Synthetic requests and media never touched family data.

### Audience and authentication corrections in isolated source
- Replaced shared auto-authorization with separate `majid` and `mira` high-entropy invitation hash configuration stored only under the non-web private root. The configuration is NOT yet provisioned for real family users; no actual invitation credential is committed.
- Sessions are HttpOnly, SameSite Strict, optionally Secure on HTTPS, HMAC-signed to a validated role and an account digest. Changing account digest invalidates old session cookies; legacy unsigned/unscoped cookies are rejected.
- Added fail-closed rate limit for sign-in attempts; no query-string invite tokens.
- Conversation creation, list, read, rename, archive, delete, streaming, message retrieval, and memory API enforce server-side audience ownership.
- Legacy conversations remain preserved, unassigned and unavailable to either child by default.
- Voice Lab does not auto-create a session; AI-generated reply-audio UI explicitly identifies synthetic speech.
- Identity picker remains separate from account authentication; changing the selected visible identity cannot change server privileges.

### Reproducible tests (synthetic only)
- PHP 8.3.33, PDO SQLite, mbstring and curl present on MASTER-PC.
- PHP syntax: initially 12 relevant source/test files PASS; edited authorization and voice-lab files subsequently linted PASS.
- `php tests/memory_v1_contract.php`: exit 0, PASS including schema 1→2 and audience isolation.
- `php tests/chat_v2_regenerate_contract.php`: exit 0, PASS.
- `php tests/audience_auth_contract.php`: exit 0, PASS including anonymous denial, role-bound cookie, tamper, expiry, token rotation/revocation, memory isolation and rate limiting.
- `node --check web/chat/chat.js` and `audience.js`: exit 0.
- Temporary isolated PHP HTTP server with fabricated role invites, private test root, synthetic conversations and no configured provider: **23 of 23 assertions PASS**, covering anonymous login denial, per-account login, both conversation creations, cross-role list/read/memory/archive/stream denial, provider-unavailable fail closed, and logout. This was not a production functional test.
- No expensive GPU inference, model training or Android build launched.

### Voice evidence (metadata only)
- `127.0.0.1:8020` XTTS: no listener. Original runtime Python and XTTS helper exist at `C:\MesoAI\runtime`. GPU reported idle during preflight (0% utilization, ~1.5 GiB VRAM used); no new synthesis performed.
- Fish S2 private prep metadata reports `ready=true` with 2 segments in a private reference-transcript artifact; this is **not** voice fidelity acceptance.
- Voice v2.2: 5 review batches in metadata. v2.3: 187 analysis rows, 88 safe-pool candidates, 36 reference entries; does not establish high similarity.
- Chatterbox v2.4: 3 review lanes, 15 generated WAV cached, 13 prepared WAV candidates. No new verified quality score or independent acceptance.
- Previous rejected XTTS candidates remain rejected; no model promoted. Original recordings and generated media are never interchangeable.

### Android findings
- Checked original MesoAI repository, `D:\Projects\MesoAI`, `D:\MesoAI\manual-deploy`, `D:\APKTransfers`, `D:\Builds`, selected Downloads and Laragon web root. No verified MesoAI APK found.
- `C:\MesoAI\private\android-chat` holds an existing Meso chat release keystore and private signing material. These files were not read, modified or used.
- Android SDK is installed on MASTER-PC. `D:\Projects\MesoAI\Khalil-Digital-Twin\android` is a different application (`com.fantest.khalilai`), not evidence of a standalone MesoAI Android package.
- No original dedicated MesoAI Gradle project, package/version, signing match or installation history was verified. APK build and public download remain BLOCKED. Do not create a replacement app.

### Release / rollback
- **Production HOLD**. Do not promote voice, merge PRs, deploy Laragon files, publish APK or update children's sessions until authentic account invitations are provisioned privately, source drift is reconciled and mobile/voice/Android QA is accepted.
- No production mutation was executed. Rollback of this feature is to discard/revert the isolated candidate branch or remove the isolated worktree after confirming it is clean; never reset the original checkout.
- The existing hourly automation was updated to audit active feature branches, fall back to MASTER-PC on DNS failure, distinguish availability from application correctness, and compare previous evidence. No duplicate task created.
- Remaining highest-priority milestone: review and integrate secure role-bound authorization with the exact deployed Laragon recovery source, then configure separate real family invites in private storage and run independent security acceptance before any release.
