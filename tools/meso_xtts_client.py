#!/usr/bin/env python3
"""Local-only MesoAI -> reviewed Meso voice over the existing XTTS service.

Reads a small JSON request from stdin, synthesizes with the reviewed Meso A
reference on the existing local XTTS service, then transcodes the returned PCM
WAV to a browser-safe MP3 before writing --output. No network endpoint is
exposed and no voice references leave MASTER-PC.
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
MESO_REFERENCE = "/data/voice/profiles/khalil/meso/refs/meso_ref_01.wav"
XTTS_ALLOWED_ROOT = "/data/voice/profiles/khalil"
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


def meso_references() -> list[str]:
    docker = shutil.which("docker")
    if not docker:
        fail("docker_unavailable")
    code = (
        "import json; from pathlib import Path; "
        f"p=Path({MESO_REFERENCE!r}).resolve(); "
        f"root=Path({XTTS_ALLOWED_ROOT!r}).resolve(); "
        "p.relative_to(root); "
        "assert p.is_file() and p.stat().st_size > 0; "
        "print(json.dumps([str(p)]))"
    )
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
        refs = json.loads(completed.stdout.strip())
    except json.JSONDecodeError:
        fail("meso_reference_invalid")
    if refs != [MESO_REFERENCE]:
        fail("meso_reference_invalid")
    return refs


def synthesize_wav(text: str, language: str) -> bytes:
    payload = json.dumps(
        {"text": text, "language": language, "speaker_wav": meso_references()},
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


def find_ffmpeg() -> str:
    if FFMPEG_FIXED.is_file():
        return str(FFMPEG_FIXED)
    discovered = shutil.which("ffmpeg") or shutil.which("ffmpeg.exe")
    if discovered:
        return discovered
    fail("ffmpeg_unavailable")


def transcode_mp3(wav: bytes) -> bytes:
    completed = subprocess.run(
        [
            find_ffmpeg(),
            "-hide_banner",
            "-loglevel",
            "error",
            "-f",
            "wav",
            "-i",
            "pipe:0",
            "-vn",
            "-ac",
            "1",
            "-ar",
            "24000",
            "-codec:a",
            "libmp3lame",
            "-b:a",
            "64k",
            "-f",
            "mp3",
            "pipe:1",
        ],
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

    wav = synthesize_wav(text, language)
    audio = transcode_mp3(wav)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(audio)
    print(json.dumps({"ok": True, "engine": "xtts-v2", "profile": "meso-a", "format": "mp3", "bytes": len(audio)}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
