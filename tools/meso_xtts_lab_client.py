#!/usr/bin/env python3
"""Private blind Meso Voice lab client.

Public callers select only anonymous labels A-D. The label-to-reference mapping
lives outside Git and outside the web root on MASTER-PC.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

from meso_xtts_client import MAX_TEXT, fail, read_request, synthesize_wav, transcode_mp3

PROFILE_MAP = Path(r"C:\MesoAI\private\voice-lab\profile-map.json")
ALLOWED_LABELS = {'A', 'B', 'C', 'D'}
XTTS_ALLOWED_ROOT = "/data/voice/profiles/khalil"
CONTAINER = "khalil-xtts"
MAX_REFS = 4


def load_refs(label: str) -> list[str]:
    if label not in ALLOWED_LABELS:
        fail("invalid_lab_label")
    if not PROFILE_MAP.is_file():
        fail("voice_lab_unavailable")
    try:
        data = json.loads(PROFILE_MAP.read_text(encoding="utf-8-sig"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        fail("voice_lab_map_invalid")
    profiles = data.get("profiles") if isinstance(data, dict) else None
    entry = profiles.get(label) if isinstance(profiles, dict) else None
    refs = entry.get("refs") if isinstance(entry, dict) else None
    if not isinstance(refs, list) or not (1 <= len(refs) <= MAX_REFS):
        fail("voice_lab_profile_invalid")
    clean = [str(v).strip() for v in refs if isinstance(v, str) and str(v).strip()]
    if len(clean) != len(refs):
        fail("voice_lab_profile_invalid")

    docker = shutil.which("docker")
    if not docker:
        fail("docker_unavailable")
    payload = json.dumps(clean, ensure_ascii=False)
    code = f'''import json\nfrom pathlib import Path\nroot=Path({XTTS_ALLOWED_ROOT!r}).resolve()\nrefs=json.loads({payload!r})\nfor value in refs:\n p=Path(value).resolve(); p.relative_to(root); assert p.is_file() and p.stat().st_size>4096\nprint(json.dumps(refs))'''
    completed = subprocess.run(
        [docker, "exec", CONTAINER, "python", "-c", code],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    if completed.returncode != 0:
        fail("voice_lab_reference_unavailable")
    return clean


def main() -> int:
    request = read_request()
    text = str(request.get("text") or "").strip()
    language = str(request.get("language") or "en").strip().lower()
    label = str(request.get("label") or "").strip().upper()
    output = Path(str(request.get("output") or "").strip())
    if not text or len(text) > min(MAX_TEXT, 500) or "\x00" in text:
        fail("invalid_text")
    if language not in {"en", "ar"}:
        fail("unsupported_language")
    if not str(output) or not output.is_absolute():
        fail("invalid_output")
    refs = load_refs(label)
    wav = synthesize_wav(text, language, refs)
    audio = transcode_mp3(wav)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_bytes(audio)
    print(json.dumps({
        "ok": True,
        "engine": "xtts-v2",
        "lab": label,
        "format": "mp3",
        "references": len(refs),
        "bytes": len(audio),
    }, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
