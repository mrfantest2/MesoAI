#!/usr/bin/env python3
"""Private MASTER-PC client for MesoAI Fish S2 live voice.

The browser never talks to RunPod directly. This helper reads a private config
from C:\\MesoAI\\private, accepts only reply text on stdin, and writes a verified
WAV to a caller-supplied path.

Two fail-closed transports are supported:
- direct-proxy: the original temporary Pod Fish HTTP API.
- runpod-serverless: queue-based RunPod Serverless. The authorized Maissoun
  reference WAV/transcript are read only from MASTER-PC and sent per request;
  the worker is instructed not to persist or memory-cache them.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
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
MAX_RESPONSE_BYTES = 44 * 1024 * 1024
MAX_REFERENCE_BYTES = 8 * 1024 * 1024
EXPECTED_REFERENCE_SHA256 = "e7170ed139962f3945d990f3b9a793e85c8c9e7af7c1f59c18dbef8df08c95b8"


class LiveTtsError(RuntimeError):
    pass


def _load_config(path: Path) -> dict:
    try:
        cfg = json.loads(path.read_text(encoding="utf-8-sig"))
    except Exception as exc:
        raise LiveTtsError("live_config_unavailable") from exc
    if not isinstance(cfg, dict):
        raise LiveTtsError("live_config_invalid")

    transport = str(cfg.get("transport", "direct-proxy") or "direct-proxy").strip().lower()
    api_key = str(cfg.get("api_key", "")).strip()
    expires_at = int(cfg.get("expires_at_epoch", 0) or 0)
    max_chars = int(cfg.get("max_chars", 1200) or 1200)
    if transport not in {"direct-proxy", "runpod-serverless"}:
        raise LiveTtsError("live_transport_invalid")
    if not api_key or len(api_key) < 24:
        raise LiveTtsError("live_api_key_invalid")
    if expires_at and expires_at <= int(time.time()):
        raise LiveTtsError("live_service_expired")
    if max_chars < 1 or max_chars > 4000:
        raise LiveTtsError("live_config_invalid")

    cfg["transport"] = transport
    cfg["api_key"] = api_key
    cfg["expires_at_epoch"] = expires_at
    cfg["max_chars"] = max_chars

    if transport == "direct-proxy":
        endpoint = str(cfg.get("endpoint_url", "")).strip()
        reference_id = str(cfg.get("reference_id", "")).strip()
        parts = urllib.parse.urlparse(endpoint)
        if parts.scheme != "https" or not parts.hostname or not parts.hostname.lower().endswith(".proxy.runpod.net"):
            raise LiveTtsError("live_endpoint_invalid")
        if parts.path.rstrip("/") != "/v1/tts" or parts.username or parts.password or parts.query or parts.fragment:
            raise LiveTtsError("live_endpoint_invalid")
        if not reference_id or not all(ch.isalnum() or ch in "_-" for ch in reference_id):
            raise LiveTtsError("live_reference_invalid")
        cfg["endpoint_url"] = endpoint
        cfg["reference_id"] = reference_id
        return cfg

    endpoint_id = str(cfg.get("endpoint_id", "")).strip()
    if not endpoint_id or len(endpoint_id) > 80 or not all(ch.isalnum() or ch in "_-" for ch in endpoint_id):
        raise LiveTtsError("live_endpoint_invalid")
    reference_audio = Path(str(cfg.get("reference_audio_path", "")))
    reference_text = Path(str(cfg.get("reference_text_path", "")))
    expected_sha = str(cfg.get("reference_sha256", EXPECTED_REFERENCE_SHA256)).lower().strip()
    if expected_sha != EXPECTED_REFERENCE_SHA256:
        raise LiveTtsError("live_reference_invalid")
    if not reference_audio.is_file() or not reference_text.is_file():
        raise LiveTtsError("live_reference_unavailable")
    cfg["endpoint_id"] = endpoint_id
    cfg["reference_audio_path"] = str(reference_audio)
    cfg["reference_text_path"] = str(reference_text)
    cfg["reference_sha256"] = expected_sha
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


def _serverless_request(cfg: dict, payload: dict, wait_ms: int = 180000) -> dict:
    endpoint_id = urllib.parse.quote(cfg["endpoint_id"], safe="")
    url = f"https://api.runpod.ai/v2/{endpoint_id}/runsync?wait={wait_ms}"
    body = json.dumps({"input": payload}, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    if len(body) > 19 * 1024 * 1024:
        raise LiveTtsError("serverless_payload_too_large")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {cfg['api_key']}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "MesoAI-MASTER-PC-Serverless-Fish-Bridge/1.0",
        },
    )
    raw = _request(req, timeout=max(200, int(wait_ms / 1000) + 20))
    try:
        response = json.loads(raw.decode("utf-8"))
    except Exception as exc:
        raise LiveTtsError("serverless_invalid_response") from exc
    if not isinstance(response, dict):
        raise LiveTtsError("serverless_invalid_response")
    status = str(response.get("status", "")).upper()
    output = response.get("output")
    if status != "COMPLETED" or not isinstance(output, dict):
        raise LiveTtsError("serverless_job_failed")
    if output.get("ok") is not True:
        raise LiveTtsError("serverless_fish_failed")
    return output


def health(cfg: dict) -> None:
    if cfg["transport"] == "runpod-serverless":
        output = _serverless_request(cfg, {"mode": "health"}, wait_ms=180000)
        if str(output.get("engine")) != "fish-s2-pro":
            raise LiveTtsError("fish_health_invalid")
        if int(output.get("vram_mib", 0) or 0) < 23000:
            raise LiveTtsError("fish_vram_invalid")
        if output.get("private_data_used") is not False:
            raise LiveTtsError("fish_health_privacy_invalid")
        print("MESO_FISH_LIVE_HEALTH=true")
        print("MESO_FISH_LIVE_TRANSPORT=runpod-serverless")
        return

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
    print("MESO_FISH_LIVE_TRANSPORT=direct-proxy")


def _verified_serverless_reference(cfg: dict) -> tuple[str, str]:
    audio_path = Path(cfg["reference_audio_path"])
    text_path = Path(cfg["reference_text_path"])
    audio = audio_path.read_bytes()
    if len(audio) < 44 or len(audio) > MAX_REFERENCE_BYTES:
        raise LiveTtsError("live_reference_invalid")
    if audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
        raise LiveTtsError("live_reference_invalid")
    actual = hashlib.sha256(audio).hexdigest()
    if actual != cfg["reference_sha256"]:
        raise LiveTtsError("live_reference_sha256_mismatch")
    ref_text = " ".join(text_path.read_text(encoding="utf-8-sig").replace("\x00", " ").split()).strip()
    if not ref_text or len(ref_text) > 4000:
        raise LiveTtsError("live_reference_invalid")
    return base64.b64encode(audio).decode("ascii"), ref_text


def _validate_and_write_wav(wav: bytes, output: Path, expected_sha: str | None = None) -> None:
    if len(wav) < 44 or len(wav) > 32 * 1024 * 1024:
        raise LiveTtsError("fish_invalid_wav")
    if wav[:4] != b"RIFF" or wav[8:12] != b"WAVE":
        raise LiveTtsError("fish_invalid_wav")
    actual = hashlib.sha256(wav).hexdigest()
    if expected_sha and expected_sha.lower() != actual:
        raise LiveTtsError("fish_wav_sha256_mismatch")
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".part")
    tmp.write_bytes(wav)
    os.replace(tmp, output)
    print(f"MESO_FISH_LIVE_WAV_BYTES={len(wav)}")
    print(f"MESO_FISH_LIVE_WAV_SHA256={actual}")


def synthesize(cfg: dict, text: str, output: Path) -> None:
    clean = " ".join(text.replace("\x00", " ").split()).strip()
    if not clean:
        raise LiveTtsError("invalid_text")
    if len(clean) > int(cfg["max_chars"]):
        raise LiveTtsError("text_too_long")

    if cfg["transport"] == "runpod-serverless":
        reference_audio_b64, reference_text = _verified_serverless_reference(cfg)
        result = _serverless_request(
            cfg,
            {
                "text": clean,
                "reference_audio_b64": reference_audio_b64,
                "reference_text": reference_text,
                "reference_sha256": cfg["reference_sha256"],
            },
            wait_ms=240000,
        )
        try:
            wav = base64.b64decode(str(result.get("audio_wav_b64", "")), validate=True)
        except Exception as exc:
            raise LiveTtsError("fish_invalid_wav") from exc
        if result.get("private_reference_persisted") is not False or result.get("reference_memory_cache") is not False:
            raise LiveTtsError("fish_privacy_invariant_failed")
        _validate_and_write_wav(wav, output, str(result.get("audio_sha256", "")))
        print("MESO_FISH_LIVE_TRANSPORT=runpod-serverless")
        return

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
    _validate_and_write_wav(wav, output)
    print("MESO_FISH_LIVE_TRANSPORT=direct-proxy")


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
