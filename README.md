# MesoAI

MesoAI is an isolated, voice-first project for building a high-fidelity local voice profile before any memory or persona work is introduced.

## Phase 1: voice fidelity only

Current source archive inspection (not committed to this repository):
- 192 voice notes mapped from WhatsApp sender metadata
- 156 Maissoun voice candidates
- 36 Jamal negative/reference samples
- 0 unlabeled audio attachments
- Maissoun source duration: ~55.6 minutes
- 148 Maissoun clips fall in the broad 2.5-60 second reference window
- 101 Maissoun clips fall in the preferred 6-24 second window

## Safety / privacy boundaries

- Raw WhatsApp archives, chat text, and audio are never committed.
- Raw/private data is never placed under the public XAMPP web root.
- Original source files are immutable; processing happens on derived copies.
- MesoAI is isolated from Khalil Digital Twin data, volumes, profiles, and runtime.
- No memory/personality inference is part of Phase 1.

## Layout

- `web/` — public MesoAI page for `https://fantest.win/meso/`
- `tools/` — sender-aware dataset import and audio analysis utilities
- `config/` — example local configuration
- `deploy/` — Windows/XAMPP deployment helper
- `data/` — documentation only; private data is ignored by Git

## Initial workflow

1. Import only audio from a WhatsApp export, labeling by sender metadata.
2. Preserve Jamal samples as negative speaker material.
3. Analyze duration, loudness, clipping, silence, and channel properties.
4. Rank clean Maissoun references.
5. Compare multiple reference sets/engines.
6. Only after voice fidelity is accepted, proceed to speech style and later memories.
