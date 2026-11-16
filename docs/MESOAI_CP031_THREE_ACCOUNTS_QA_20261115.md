# MesoAI CP031 — Three-account migration and security QA

**Date:** 2026-11-15
**Scope:** Isolated staging only; no production changes.
**Original repository:** mrfantest2/MesoAI
**Base:** `a72003d4794db55be546d125ac669dbd961e21c7`
**Active production:** `C:\laragon\www\meso`; deployed authentication defaults to `C:\MesoAI\private`.

## Changes
- Preserve the six staged Majid/Mira/Jamal UI/authentication source updates without modifying their original worktree.
- Upgrade the memory schema to version 3, supporting fresh installs and deterministic v1/v2 migrations.
- Rebuild the SQLite `conversations` table transactionally, retaining ownership, timestamps, messages, memory references and user-defined indexes/triggers.
- Fail closed on corrupt or inconsistent foreign-key relationships, and preserve the old schema on transactional rollback.
- Separate Jamal's conversational context from child-specific language and unverified family roles.
- Expand synthetic login and account-isolation HTTP tests from two accounts to all three.

## Tests completed
- Synthetic HTTP suite: **43/43 PASS**; includes anonymous rejection, independent account sign-in, six cross-account denial directions for messages, memory, archive and streaming.
- Synthetic migration v1→v3, restrictive v2→v3, compatible v2→v3, fresh v3: **PASS**.
- Corrupt foreign-key negative test and transaction rollback: **PASS**.
- Data preservation, indexes, foreign keys and idempotence: **PASS**.
- Authentication and legacy memory PHP contract suites: **PASS**.
- Model-selection contract: **8 cases PASS**.
- Modified PHP and JS syntax and `git diff --check`: **PASS**.

## Consistent private database rehearsal (no deployment)
Two source databases were independently backed up using SQLite's online backup API into a private folder outside the repository. Both *copies* were migrated; originals were opened read-only.

| Source | Original schema | Conversations | Messages | Migration | Integrity |
|---|---:|---:|---:|---|---|
| Production-configured C: private root | 1 | 70 | 267 | v3 PASS | quick_check and foreign_key_check PASS |
| Separate D: private root | 1 | 36 | 100 | v3 PASS | quick_check and foreign_key_check PASS |

**Important:** These archives are different. Do not substitute or merge them without explicit provenance review. The active deployed `meso_private_root()` specifies the C: private root.

## Production restrictions and remaining release gates
- Production PHP and its schema are still the older versions; no deployment performed.
- Current `chat-accounts.json` credential-hash configuration is not present in the two checked private roots. Three independent approved credentials must be provisioned before switching the live authentication implementation.
- Real live user login and end-to-end browser UI remain unverified.
- The six modified source files are preserved in the original local staging worktree.
- Do not import WhatsApp material or voice recordings into public GitHub.
- No voice-model promotion or GPU inference is part of this checkpoint.

## Safe release/rollback procedure (not executed)
1. Confirm no concurrent production writer and choose the *actually deployed* C: private root.
2. Back up the production source and create a **consistent SQLite online backup**, including current WAL data; test restore privately.
3. Provision independent strong credentials using a private operator-controlled channel and store only credential digests in the non-web root; do not echo secrets.
4. Confirm that all three account holders have an approved secure access path, and test account recovery/revocation.
5. Re-run synthetic tests and the production-db backup migration rehearsal against the exact candidate SHA.
6. Deploy only after explicit authorization and a bounded maintenance/rollback plan; migrate the live DB exactly once.
7. Check schema version, row counts, foreign keys and all three authenticated browser flows; reject cross-account access.
8. On a critical failure, use the verified backup to restore both code and DB consistently before reopening write traffic.
9. Report actual live results; never equate synthetic QA with production acceptance.

**Decision:** Staging migration/security QA PASS. **Production HOLD** pending credential provisioning, owner-authorized release and live browser acceptance.
