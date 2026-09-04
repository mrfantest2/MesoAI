#!/usr/bin/env python3
"""Private Meso Voice v2.3 multi-reference blind sweep client.

Public callers select only a batch index plus anonymous A-E label. The private
batch-to-reference mapping stays outside Git and outside the web root. Each
candidate uses 2-4 verified private references to stabilize identity.
"""
from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path

from meso_xtts_client import MAX_TEXT, fail, read_request, synthesize_wav, transcode_mp3

SWEEP_MAP = Path(r"C:\MesoAI\private\voice-lab-v23\sweep.json")
ALLOWED_LABELS = {'A', 'B', 'C', 'D', 'E'}
XTTS_ALLOWED_ROOT = "/data/voice/profiles/khalil"
CONTAINER = "khalil-xtts"
MAX_BATCHES = 20


def load_refs(batch: int, label: str) -> list[str]:
    if label not in ALLOWED_LABELS or batch < 0 or batch >= MAX_BATCHES:
        fail("invalid_sweep_selection")
    if not SWEEP_MAP.is_file():
        fail("voice_sweep_unavailable")
    try:
        data = json.loads(SWEEP_MAP.read_text(encoding="utf-8-sig"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        fail("voice_sweep_map_invalid")
    batches = data.get("batches") if isinstance(data, dict) else None
    if not isinstance(batches, list) or batch >= len(batches):
        fail("invalid_sweep_batch")
    entry = batches[batch]
    profiles = entry.get("profiles") if isinstance(entry, dict) else None
    refs = profiles.get(label) if isinstance(profiles, dict) else None
    if not isinstance(refs, list) or not (2 <= len(refs) <= 4):
        fail("voice_sweep_profile_invalid")
    clean: list[str] = []
    for value in refs:
        if not isinstance(value, str) or not value.strip():
            fail("voice_sweep_profile_invalid")
        clean.append(value.strip())
    if len(set(clean)) != len(clean):
        fail("voice_sweep_profile_invalid")

    docker = shutil.which("docker")
    if not docker:
        fail("docker_unavailable")
    code = f'''from pathlib import Path\nroot=Path({XTTS_ALLOWED_ROOT!r}).resolve()\nrefs={clean!r}\nfor value in refs:\n p=Path(value).resolve(); p.relative_to(root); assert p.is_file() and p.stat().st_size>4096\nprint(len(refs))'''
    completed = subprocess.run(
        [docker, "exec", CONTAINER, "python", "-c", code],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    if completed.returncode != 0:
        fail("voice_sweep_reference_unavailable")
    return clean


def main() -> int:
    request = read_request()
    text = str(request.get("text") or "").strip()
    language = str(request.get("language") or "en").strip().lower()
    label = str(request.get("label") or "").strip().upper()
    try:
        batch = int(request.get("batch"))
    except (TypeError, ValueError):
        fail("invalid_sweep_batch")
    output = Path(str(request.get("output") or "").strip())
    if not text or len(text) > min(MAX_TEXT, 500) or "\x00" in text:
        fail("invalid_text")
    if language not in {"en", "ar"}:
        fail("unsupported_language")
    if not str(output) or not output.is_absolute():
        fail("invalid_output")

    refs = load_refs(batch, label)
    wav = synthesize_wav(text, language, refs)
    audio = transcode_mp3(wav)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_bytes(audio)
    print(json.dumps({
        "ok": True,
        "engine": "xtts-v2",
        "sweep": "meso-v2.3",
        "batch": batch,
        "label": label,
        "format": "mp3",
        "references": len(refs),
        "bytes": len(audio),
    }, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
