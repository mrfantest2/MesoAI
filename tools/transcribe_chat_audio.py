#!/usr/bin/env python3
"""Private local microphone transcription helper for MesoAI chat.

This process receives only a local temporary audio path, transcribes it with
faster-whisper on MASTER-PC, prints one JSON object, and never performs network
uploads or writes conversation history.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("audio", type=Path)
    parser.add_argument("--model", default=os.getenv("MESO_CHAT_STT_MODEL", "small"))
    parser.add_argument(
        "--model-root",
        type=Path,
        default=Path(os.getenv("MESO_CHAT_STT_MODEL_ROOT", r"C:\MesoAI\private\models\faster-whisper")),
    )
    args = parser.parse_args()

    audio = args.audio.resolve(strict=True)
    private_tmp = Path(os.getenv("MESO_CHAT_STT_TMP", r"C:\MesoAI\private\chat-stt\tmp")).resolve()
    try:
        audio.relative_to(private_tmp)
    except ValueError as exc:
        raise SystemExit("audio path escaped Meso private chat STT root") from exc

    from faster_whisper import WhisperModel

    args.model_root.mkdir(parents=True, exist_ok=True)
    model = WhisperModel(
        args.model.strip() or "small",
        device="cpu",
        compute_type="int8",
        download_root=str(args.model_root),
    )
    segments, info = model.transcribe(
        str(audio),
        beam_size=5,
        vad_filter=True,
        multilingual=True,
        condition_on_previous_text=False,
    )
    text = " ".join(seg.text.strip() for seg in segments if seg.text.strip()).strip()
    if not text:
        print(json.dumps({"ok": False, "error": "no_speech_detected"}))
        return 2

    print(
        json.dumps(
            {
                "ok": True,
                "transcript": text,
                "language": getattr(info, "language", None),
                "language_probability": round(float(getattr(info, "language_probability", 0.0) or 0.0), 4),
                "duration_seconds": round(float(getattr(info, "duration", 0.0) or 0.0), 3),
                "model": f"faster-whisper/{args.model.strip() or 'small'}",
                "provider_upload": False,
            },
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
