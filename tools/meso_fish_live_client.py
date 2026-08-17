#!/usr/bin/env python3
"""Private MASTER-PC client for the temporary MesoAI Fish S2 live service.

The browser never talks to RunPod directly. This helper reads a private config
from C:\\MesoAI\\private, accepts only reply text on stdin, sends an authenticated
MessagePack request to Fish Speech, and writes a WAV to a caller-supplied path.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

import msgpack

DEFAULT_CONFIG = Path(r"C:\MesoAI\private\fish-live\config.json")
MAX_RESPONSE_BYTES = 32 * 1024 * 1024


class LiveTtsError(RuntimeError):
    pass


def _load_config(path: Path) -> dict:
    try:
        cfg = json.loads(path.read_text(encoding="utf-8-sig"))
    except Exception as exc:
        raise LiveTtsError("live_config_unavailable") from exc
    if not isinstance(cfg, dict):
        raise LiveTtsError("live_config_invalid")

    endpoint = str(cfg.get("endpoint_url", "")).strip()
    api_key = str(cfg.get("api_key", "")).strip()
    reference_id = str(cfg.get("reference_id", "")).strip()
    expires_at = int(cfg.get("expires_at_epoch", 0) or 0)
    max_chars = int(cfg.get("max_chars", 1200) or 1200)

    parts = urllib.parse.urlparse(endpoint)
    if parts.scheme != "https" or not parts.hostname or not parts.hostname.lower().endswith(".proxy.runpod.net"):
        raise LiveTtsError("live_endpoint_invalid")
    if parts.path.rstrip("/") != "/v1/tts" or parts.username or parts.password or parts.query or parts.fragment:
        raise LiveTtsError("live_endpoint_invalid")
    if not api_key or len(api_key) < 24:
        raise LiveTtsError("live_api_key_invalid")
    if not reference_id or not all(ch.isalnum() or ch in "_-" for ch in reference_id):
        raise LiveTtsError("live_reference_invalid")
    if expires_at <= int(time.time()):
        raise LiveTtsError("live_service_expired")
    if max_chars < 1 or max_chars > 4000:
        raise LiveTtsError("live_config_invalid")

    cfg["endpoint_url"] = endpoint
    cfg["api_key"] = api_key
    cfg["reference_id"] = reference_id
    cfg["expires_at_epoch"] = expires_at
    cfg["max_chars"] = max_chars
    return cfg


def _request(req: urllib.request.Request, timeout: int) -> bytes:
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            status = int(getattr(response, "status", 0) or 0)
            if status != 200:
                raise LiveTtsError("fish_http_error")
            content_length = response.headers.get("Content-Length")
            if content_length and int(content_length) > MAX_RESPONSE_BYTES:
                raise LiveTtsError("fish_response_too_large")
            data = response.read(MAX_RESPONSE_BYTES + 1)
    except urllib.error.HTTPError as exc:
        raise LiveTtsError(f"fish_http_{exc.code}") from exc
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise LiveTtsError("fish_unavailable") from exc
    if len(data) > MAX_RESPONSE_BYTES:
        raise LiveTtsError("fish_response_too_large")
    return data


def health(cfg: dict) -> None:
    endpoint = urllib.parse.urlparse(cfg["endpoint_url"])
    health_url = urllib.parse.urlunparse((endpoint.scheme, endpoint.netloc, "/v1/health", "", "", ""))
    req = urllib.request.Request(
        health_url,
        method="GET",
        headers={"Authorization": f"Bearer {cfg['api_key']}", "Accept": "application/json"},
    )
    data = _request(req, timeout=20)
    if not data:
        raise LiveTtsError("fish_health_empty")
    print("MESO_FISH_LIVE_HEALTH=true")


def synthesize(cfg: dict, text: str, output: Path) -> None:
    clean = " ".join(text.replace("\x00", " ").split()).strip()
    if not clean:
        raise LiveTtsError("invalid_text")
    if len(clean) > int(cfg["max_chars"]):
        raise LiveTtsError("text_too_long")

    payload = {
        "text": clean,
        "references": [],
        "reference_id": cfg["reference_id"],
        "format": "wav",
        "latency": "normal",
        "max_new_tokens": 1024,
        "chunk_length": 200,
        "top_p": 0.85,
        "repetition_penalty": 1.1,
        "temperature": 0.85,
        "streaming": False,
        "use_memory_cache": "on",
        "seed": 42,
    }
    body = msgpack.packb(payload, use_bin_type=True)
    url = cfg["endpoint_url"] + "?format=msgpack"
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {cfg['api_key']}",
            "Content-Type": "application/msgpack",
            "Accept": "audio/wav,application/octet-stream",
            "User-Agent": "MesoAI-MASTER-PC-Fish-Bridge/1.0",
        },
    )
    wav = _request(req, timeout=95)
    if len(wav) < 44 or wav[:4] != b"RIFF" or wav[8:12] != b"WAVE":
        raise LiveTtsError("fish_invalid_wav")

    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".part")
    tmp.write_bytes(wav)
    os.replace(tmp, output)
    print(f"MESO_FISH_LIVE_WAV_BYTES={len(wav)}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--health", action="store_true")
    args = parser.parse_args()

    try:
        cfg = _load_config(args.config)
        if args.health:
            health(cfg)
            return 0
        if args.output is None:
            raise LiveTtsError("output_required")
        try:
            request = json.load(sys.stdin)
        except Exception as exc:
            raise LiveTtsError("invalid_stdin_json") from exc
        if not isinstance(request, dict):
            raise LiveTtsError("invalid_stdin_json")
        synthesize(cfg, str(request.get("text", "")), args.output)
        return 0
    except LiveTtsError as exc:
        # Return only a short machine-safe error code; never echo reply text,
        # private paths, reference data, endpoint secrets, or response bodies.
        print(f"MESO_FISH_LIVE_ERROR={exc}", file=sys.stderr)
        return 2
    except Exception:
        print("MESO_FISH_LIVE_ERROR=unexpected_error", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
