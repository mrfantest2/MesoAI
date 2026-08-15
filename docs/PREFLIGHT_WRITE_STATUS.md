# MesoAI preflight-write status

Date: 2026-08-15

## Frozen verified baseline

The currently verified live baseline is commit:

`3e6ca68c27886f2112e2eca9394cbd092293104f`

Recovery branch:

`freeze/meso-chat-2026-08-15`

That baseline contains the isolated private `/meso/chat/` text preflight using local Ollama, with memory/persona/cloned voice disabled and anonymous chat access blocked.

## Active development branch

All new work after the freeze is isolated on:

`preflight-write/meso-next`

This branch is development/preflight only. It must not be deployed to the live `/meso` target until its static checks and MASTER-PC preflight pass and deployment is explicitly selected.

## Voice fidelity track

Human review rejected XTTS-v2 and Chatterbox Multilingual V3 for speaker fidelity. Fish Audio S2 Pro is the next evaluation target.

Fish S2 execution is intentionally gated:

- no model/code download until Fish Audio Research License acceptance is explicitly recorded for an allowed non-commercial/research scope;
- no private reference audio or transcript is committed to Git;
- remote GPU transfer uses SSH with an expected SHA-256 host-key fingerprint;
- uploaded input hashes are verified before inference;
- generated output hashes are verified again after download;
- the remote private working directory is deleted after the attempt;
- destroying the temporary GPU instance remains a separate provider-side action.

Current Fish source pin used by the preflight runner:

`e5e292632cb11e7a27b2b7487f58f612bc101e13`

## Chat track

The next branch keeps the same behavior boundary:

- text only;
- no MesoAI memory retrieval;
- no Maissoun persona simulation;
- no KDT database or KDT `/v1/chat` calls;
- no server-side conversation archive;
- browser-page context only.

Additional hardening on the preflight branch removes inline JavaScript, tightens browser security headers, and renders all chat text through DOM text nodes rather than HTML insertion.

## Privacy invariant

Raw WhatsApp content, private voice references, generated clone samples, provider credentials, invite/session secrets, and license-acceptance evidence remain outside this public repository.
