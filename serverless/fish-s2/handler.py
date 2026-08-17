#!/usr/bin/env python3
"""MesoAI Fish S2 Pro RunPod Serverless queue worker.

Privacy properties:
- The worker image contains only public code and model runtime dependencies.
- The Maissoun reference WAV/transcript arrive only inside an authenticated
  RunPod job request from the trusted MASTER-PC bridge.
- Reference bytes are passed directly to the local Fish API in MessagePack;
  they are never written to the container filesystem.
- Fish reference memory caching is explicitly disabled for private requests.
"""

from __future__ import annotations

import base64
import hashlib
import os
import subprocess
import threading
import time
from pathlib import Path
from typing import Any

import ormsgpack
import requests
import runpod

FISH_ROOT = Path("/app/fish-speech")
MODEL_ID = os.environ.get("MESO_MODEL_ID", "fishaudio/s2-pro")
CACHE_ROOT = Path("/runpod-volume/huggingface-cache/hub")
LOCAL_MODEL_LINK = FISH_ROOT / "checkpoints" / "s2-pro"
API_URL = "http://127.0.0.1:8080"
MAX_TEXT_CHARS = 1200
MAX_REF_TEXT_CHARS = 4000
MAX_REF_AUDIO_BYTES = 16 * 1024 * 1024
MAX_WAV_BYTES = 32 * 1024 * 1024
MIN_VRAM_MIB = 23000

_api_lock = threading.Lock()
_api_process: subprocess.Popen[str] | None = None


def _resolve_cached_model() -> Path:
    if "/" not in MODEL_ID:
        raise RuntimeError("invalid_model_id")
    org, name = MODEL_ID.split("/", 1)
    model_root = CACHE_ROOT / f"models--{org}--{name}"
    refs_main = model_root / "refs" / "main"
    snapshots = model_root / "snapshots"

    candidates: list[Path] = []
    if refs_main.is_file():
        snapshot_id = refs_main.read_text(encoding="utf-8").strip()
        if snapshot_id:
            candidates.append(snapshots / snapshot_id)
    if snapshots.is_dir():
        candidates.extend(sorted((p for p in snapshots.iterdir() if p.is_dir()), reverse=True))

    for candidate in candidates:
        if (candidate / "codec.pth").is_file():
            return candidate.resolve()
    raise RuntimeError("cached_model_unavailable")


def _gpu_vram_mib() -> tuple[str, int]:
    proc = subprocess.run(
        [
            "nvidia-smi",
            "--query-gpu=name,memory.total",
            "--format=csv,noheader,nounits",
        ],
        text=True,
        capture_output=True,
        timeout=15,
        check=True,
    )
    line = proc.stdout.splitlines()[0].strip()
    name, raw_vram = [part.strip() for part in line.rsplit(",", 1)]
    return name, int(raw_vram)


def _health_ok(timeout: float = 5.0) -> bool:
    try:
        response = requests.get(f"{API_URL}/v1/health", timeout=timeout)
        return response.status_code == 200 and response.json().get("status") == "ok"
    except Exception:
        return False


def _ensure_fish_api() -> tuple[str, int, Path]:
    global _api_process
    with _api_lock:
        gpu_name, vram_mib = _gpu_vram_mib()
        if vram_mib < MIN_VRAM_MIB:
            raise RuntimeError("insufficient_vram")

        model_path = _resolve_cached_model()
        LOCAL_MODEL_LINK.parent.mkdir(parents=True, exist_ok=True)
        if LOCAL_MODEL_LINK.is_symlink() or LOCAL_MODEL_LINK.exists():
            if LOCAL_MODEL_LINK.is_symlink() and LOCAL_MODEL_LINK.resolve() == model_path:
                pass
            else:
                raise RuntimeError("model_link_conflict")
        else:
            LOCAL_MODEL_LINK.symlink_to(model_path, target_is_directory=True)

        if _api_process is not None and _api_process.poll() is None and _health_ok():
            return gpu_name, vram_mib, model_path

        if _api_process is not None and _api_process.poll() is None:
            _api_process.terminate()
            try:
                _api_process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                _api_process.kill()

        env = os.environ.copy()
        env["HF_HUB_OFFLINE"] = "1"
        env["TRANSFORMERS_OFFLINE"] = "1"
        env["HF_HOME"] = "/runpod-volume/huggingface-cache"
        _api_process = subprocess.Popen(
            [
                str(FISH_ROOT / ".venv" / "bin" / "python"),
                "tools/api_server.py",
                "--llama-checkpoint-path",
                "checkpoints/s2-pro",
                "--decoder-checkpoint-path",
                "checkpoints/s2-pro/codec.pth",
                "--listen",
                "127.0.0.1:8080",
                "--workers",
                "1",
            ],
            cwd=str(FISH_ROOT),
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.STDOUT,
            text=True,
        )

        deadline = time.time() + 180
        while time.time() < deadline:
            if _api_process.poll() is not None:
                raise RuntimeError("fish_api_exited")
            if _health_ok():
                return gpu_name, vram_mib, model_path
            time.sleep(2)
        raise RuntimeError("fish_api_timeout")


def _clean_text(value: Any, max_chars: int, code: str) -> str:
    clean = " ".join(str(value or "").replace("\x00", " ").split()).strip()
    if not clean or len(clean) > max_chars:
        raise RuntimeError(code)
    return clean


def _decode_reference(data: dict[str, Any]) -> tuple[bytes, str]:
    encoded = str(data.get("reference_audio_b64") or "")
    if not encoded or len(encoded) > (MAX_REF_AUDIO_BYTES * 2):
        raise RuntimeError("invalid_reference_audio")
    try:
        audio = base64.b64decode(encoded, validate=True)
    except Exception as exc:
        raise RuntimeError("invalid_reference_audio") from exc
    if len(audio) < 44 or len(audio) > MAX_REF_AUDIO_BYTES:
        raise RuntimeError("invalid_reference_audio")
    if audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
        raise RuntimeError("reference_not_wav")

    expected = str(data.get("reference_sha256") or "").lower().strip()
    actual = hashlib.sha256(audio).hexdigest()
    if expected and (len(expected) != 64 or expected != actual):
        raise RuntimeError("reference_sha256_mismatch")

    ref_text = _clean_text(data.get("reference_text"), MAX_REF_TEXT_CHARS, "invalid_reference_text")
    return audio, ref_text


def _synthesize(data: dict[str, Any]) -> dict[str, Any]:
    text = _clean_text(data.get("text"), MAX_TEXT_CHARS, "invalid_text")
    reference_audio, reference_text = _decode_reference(data)
    gpu_name, vram_mib, _ = _ensure_fish_api()

    payload = {
        "text": text,
        "references": [{"audio": reference_audio, "text": reference_text}],
        "reference_id": None,
        "format": "wav",
        "latency": "normal",
        "max_new_tokens": 1024,
        "chunk_length": 200,
        "top_p": 0.85,
        "repetition_penalty": 1.1,
        "temperature": 0.85,
        "streaming": False,
        "use_memory_cache": "off",
        "seed": 42,
    }

    try:
        response = requests.post(
            f"{API_URL}/v1/tts",
            params={"format": "msgpack"},
            data=ormsgpack.packb(payload),
            headers={"Content-Type": "application/msgpack", "Accept": "audio/wav"},
            timeout=110,
        )
        if response.status_code != 200:
            raise RuntimeError("fish_tts_failed")
        wav = response.content
        if len(wav) < 44 or len(wav) > MAX_WAV_BYTES:
            raise RuntimeError("invalid_wav")
        if wav[:4] != b"RIFF" or wav[8:12] != b"WAVE":
            raise RuntimeError("invalid_wav")
        return {
            "ok": True,
            "engine": "fish-s2-pro",
            "audio_format": "wav",
            "audio_wav_b64": base64.b64encode(wav).decode("ascii"),
            "audio_bytes": len(wav),
            "audio_sha256": hashlib.sha256(wav).hexdigest(),
            "gpu": gpu_name,
            "vram_mib": vram_mib,
            "private_reference_persisted": False,
            "reference_memory_cache": False,
        }
    finally:
        # Do not retain a Python-level reference to private request bytes.
        reference_audio = b""
        reference_text = ""


def handler(job: dict[str, Any]) -> dict[str, Any]:
    data = job.get("input")
    if not isinstance(data, dict):
        return {"ok": False, "error": "invalid_input"}
    try:
        if data.get("mode") == "health":
            gpu_name, vram_mib, model_path = _ensure_fish_api()
            return {
                "ok": True,
                "engine": "fish-s2-pro",
                "model_id": MODEL_ID,
                "model_cached": str(model_path).startswith(str(CACHE_ROOT)),
                "gpu": gpu_name,
                "vram_mib": vram_mib,
                "private_data_used": False,
            }
        return _synthesize(data)
    except RuntimeError as exc:
        return {"ok": False, "error": str(exc)}
    except Exception:
        return {"ok": False, "error": "unexpected_error"}


if __name__ == "__main__":
    runpod.serverless.start({"handler": handler})
