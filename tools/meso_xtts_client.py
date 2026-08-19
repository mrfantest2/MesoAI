#!/usr/bin/env python3
"""Local-only MesoAI -> Meso voice over the existing XTTS service."""
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
MESO_V2_PROFILE = "/data/voice/profiles/khalil/meso-v2/profile.json"
MESO_A_REFERENCE = "/data/voice/profiles/khalil/meso/refs/meso_ref_01.wav"
XTTS_ALLOWED_ROOT = "/data/voice/profiles/khalil"
MAX_MESO_REFERENCES = 4
FFMPEG_FIXED = Path(r"C:\ffmpeg\bin\ffmpeg.exe")
MAX_TEXT = 1200
MAX_WAV = 32 * 1024 * 1024
MAX_MP3 = 8 * 1024 * 1024
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
            decoded = raw.decode("utf-16-le")
        else:
            decoded = raw.decode("utf-8")
        request = json.loads(decoded)
    except (UnicodeDecodeError, json.JSONDecodeError):
        fail("invalid_json")
    if not isinstance(request, dict):
        fail("invalid_request")
    return request


def meso_references() -> tuple[list[str], str]:
    docker = shutil.which("docker")
    if not docker:
        fail("docker_unavailable")
    code = f'''import json
from pathlib import Path
root=Path({XTTS_ALLOWED_ROOT!r}).resolve()
v2=Path({MESO_V2_PROFILE!r}).resolve()
fallback=Path({MESO_A_REFERENCE!r}).resolve()
def valid(p):
    try:
        p=p.resolve(); p.relative_to(root)
        return p.is_file() and p.stat().st_size > 0
    except Exception:
        return False
refs=[]
profile='meso-a'
if v2.is_file():
    try:
        data=json.loads(v2.read_text(encoding='utf-8'))
        raw=data.get('references') if isinstance(data,dict) else None
        if isinstance(raw,list):
            for item in raw[:{MAX_MESO_REFERENCES}]:
                value=item.get('path') if isinstance(item,dict) else item
                if isinstance(value,str) and value.strip():
                    p=Path(value.strip())
                    if valid(p): refs.append(str(p.resolve()))
        if 2 <= len(refs) <= {MAX_MESO_REFERENCES}: profile='meso-v2'
        else: refs=[]
    except Exception:
        refs=[]
if not refs:
    if not valid(fallback): raise SystemExit(3)
    refs=[str(fallback)]
print(json.dumps({{'profile':profile,'references':refs}}))'''
    completed = subprocess.run(
        [docker, "exec", CONTAINER, "python", "-c", code],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    if completed.returncode != 0:
        fail("meso_reference_unavailable")
    try:
        result = json.loads(completed.stdout.strip())
        refs = result["references"]
        profile = result["profile"]
    except (json.JSONDecodeError, KeyError, TypeError):
        fail("meso_reference_invalid")
    if profile not in {"meso-a", "meso-v2"} or not isinstance(refs, list) or not (1 <= len(refs) <= MAX_MESO_REFERENCES):
        fail("meso_reference_invalid")
    return [str(v) for v in refs], profile


def synthesize_wav(text: str, language: str, refs: list[str]) -> bytes:
    payload = json.dumps(
        {"text": text, "language": language, "speaker_wav": refs},
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
            if "audio/wav" not in str(response.headers.get("Content-Type", "")).lower():
                fail("xtts_invalid_content_type")
            audio = response.read(MAX_WAV + 1)
    except (urllib.error.URLError, TimeoutError, OSError):
        fail("xtts_request_failed")
    if not (44 <= len(audio) <= MAX_WAV) or audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
        fail("xtts_invalid_wav")
    return audio


def find_ffmpeg() -> str:
    if FFMPEG_FIXED.is_file():
        return str(FFMPEG_FIXED)
    discovered = shutil.which("ffmpeg") or shutil.which("ffmpeg.exe")
    if discovered:
        return discovered
    fail("ffmpeg_unavailable")


def transcode_mp3(wav: bytes) -> bytes:
    completed = subprocess.run(
        [find_ffmpeg(), "-hide_banner", "-loglevel", "error", "-f", "wav", "-i", "pipe:0", "-vn", "-ac", "1", "-ar", "24000", "-codec:a", "libmp3lame", "-b:a", "64k", "-f", "mp3", "pipe:1"],
        input=wav,
        capture_output=True,
        timeout=90,
        check=False,
    )
    if completed.returncode != 0:
        fail("mp3_transcode_failed")
    audio = completed.stdout
    if not (1024 <= len(audio) <= MAX_MP3):
        fail("invalid_mp3_size")
    has_id3 = audio.startswith(b"ID3")
    has_frame = len(audio) >= 2 and audio[0] == 0xFF and (audio[1] & 0xE0) == 0xE0
    if not (has_id3 or has_frame):
        fail("invalid_mp3_header")
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
    refs, profile = meso_references()
    wav = synthesize_wav(text, language, refs)
    audio = transcode_mp3(wav)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(audio)
    print(json.dumps({"ok": True, "engine": "xtts-v2", "profile": profile, "format": "mp3", "references": len(refs), "bytes": len(audio)}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
