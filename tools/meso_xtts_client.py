#!/usr/bin/env python3
"""Local-only MesoAI -> existing khalil-xtts bridge.

Reads a small JSON request from stdin and writes one validated WAV to --output.
No network endpoint is exposed and no voice references leave MASTER-PC.
"""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

XTTS_BASE = "http://127.0.0.1:8020"
CONTAINER = "khalil-xtts"
MAX_TEXT = 1200
MAX_WAV = 32 * 1024 * 1024
MAX_REQUEST_BYTES = 16 * 1024


def fail(message: str) -> "NoReturn":
    print(message, file=sys.stderr)
    raise SystemExit(2)


def read_request() -> dict:
    raw = sys.stdin.buffer.read(MAX_REQUEST_BYTES + 1)
    if not raw or len(raw) > MAX_REQUEST_BYTES:
        fail("invalid_json")

    try:
        if raw.startswith((b"\xff\xfe", b"\xfe\xff")):
            decoded = raw.decode("utf-16")
        elif raw.startswith(b"\xef\xbb\xbf"):
            decoded = raw.decode("utf-8-sig")
        elif b"\x00" in raw:
            # Windows/.NET redirected stdin can arrive as BOM-less UTF-16LE.
            decoded = raw.decode("utf-16-le")
        else:
            decoded = raw.decode("utf-8")
        request = json.loads(decoded)
    except (UnicodeDecodeError, json.JSONDecodeError):
        fail("invalid_json")

    if not isinstance(request, dict):
        fail("invalid_request")
    return request


def canonical_references() -> list[str]:
    docker = shutil.which("docker")
    if not docker:
        fail("docker_unavailable")
    code = (
        "import json; "
        "p=json.load(open('/data/voice/profiles/khalil/profile.json','r',encoding='utf-8')); "
        "print(json.dumps([x.get('path') for x in (p.get('references') or []) if x.get('path')]))"
    )
    completed = subprocess.run(
        [docker, "exec", CONTAINER, "python", "-c", code],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    if completed.returncode != 0:
        fail("xtts_profile_unavailable")
    try:
        refs = json.loads(completed.stdout.strip())
    except json.JSONDecodeError:
        fail("xtts_profile_invalid")
    if not isinstance(refs, list) or not 1 <= len(refs) <= 5 or not all(isinstance(x, str) and x for x in refs):
        fail("xtts_profile_invalid")
    return refs


def synthesize(text: str, language: str) -> bytes:
    payload = json.dumps(
        {"text": text, "language": language, "speaker_wav": canonical_references()},
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")
    request = urllib.request.Request(
        XTTS_BASE + "/synthesize",
        data=payload,
        headers={"Content-Type": "application/json", "Accept": "audio/wav"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=300) as response:
            if response.status != 200:
                fail("xtts_http_error")
            content_type = str(response.headers.get("Content-Type", "")).lower()
            if "audio/wav" not in content_type:
                fail("xtts_invalid_content_type")
            audio = response.read(MAX_WAV + 1)
    except (urllib.error.URLError, TimeoutError, OSError):
        fail("xtts_request_failed")
    if not (44 <= len(audio) <= MAX_WAV):
        fail("xtts_invalid_wav_size")
    if audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
        fail("xtts_invalid_wav_header")
    return audio


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    request = read_request()
    text = str(request.get("text") or "").strip()
    language = str(request.get("language") or "en").strip().lower()
    if not text or len(text) > MAX_TEXT or "\x00" in text:
        fail("invalid_text")
    if language not in {"en", "ar"}:
        fail("unsupported_language")

    audio = synthesize(text, language)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(audio)
    print(json.dumps({"ok": True, "engine": "xtts-v2", "bytes": len(audio)}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
