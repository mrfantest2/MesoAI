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
