#!/usr/bin/env python3
"""Private local transcription helper for Fish Audio S2 reference conditioning.

This tool never uploads audio. It transcribes one local reference file and writes
UTF-8 text/JSON beside the private MesoAI runtime on MASTER-PC.
"""
from __future__ import annotations

import argparse
import importlib.metadata
import json
from pathlib import Path
from datetime import datetime, timezone

from faster_whisper import WhisperModel


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("audio", type=Path)
    ap.add_argument("--out-dir", type=Path, required=True)
    ap.add_argument("--model", default="large-v3-turbo")
    ap.add_argument("--device", default="cpu")
    ap.add_argument("--compute-type", default="int8")
    args = ap.parse_args()

    audio = args.audio.resolve()
    if not audio.is_file():
        raise SystemExit(f"reference audio not found: {audio}")

    out_dir = args.out_dir.resolve()
    out_dir.mkdir(parents=True, exist_ok=True)

    model = WhisperModel(args.model, device=args.device, compute_type=args.compute_type)
    segments, info = model.transcribe(
        str(audio),
        language="ar",
        beam_size=5,
        vad_filter=True,
        word_timestamps=True,
        condition_on_previous_text=False,
    )

    segs = []
    words = []
    text_parts = []
    for seg in segments:
        text = (seg.text or "").strip()
        if text:
            text_parts.append(text)
        segs.append({"start": seg.start, "end": seg.end, "text": text})
        for word in seg.words or []:
            words.append({
                "start": word.start,
                "end": word.end,
                "word": (word.word or "").strip(),
                "probability": word.probability,
            })

    transcript = " ".join(text_parts).strip()
    if not transcript:
        raise SystemExit("Whisper returned an empty Arabic transcript")

    (out_dir / "reference.txt").write_text(transcript + "\n", encoding="utf-8")
    try:
        fw_version = importlib.metadata.version("faster-whisper")
    except importlib.metadata.PackageNotFoundError:
        fw_version = None
    payload = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "audio": audio.name,
        "model": args.model,
        "device": args.device,
        "compute_type": args.compute_type,
        "faster_whisper_version": fw_version,
        "language": getattr(info, "language", "ar"),
        "language_probability": getattr(info, "language_probability", None),
        "duration": getattr(info, "duration", None),
        "text": transcript,
        "segments": segs,
        "words": words,
    }
    (out_dir / "reference-transcript.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"MESO_FISH_REFERENCE_TRANSCRIPT_OK={out_dir / 'reference.txt'}")
    print(f"MESO_FISH_REFERENCE_TRANSCRIPT_CHARS={len(transcript)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
