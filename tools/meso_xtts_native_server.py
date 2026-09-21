#!/usr/bin/env python3
"""Loopback-only native XTTS v2 service for MesoAI on MASTER-PC."""
from __future__ import annotations

import os
import tempfile
from pathlib import Path
from threading import Lock

import torch
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

MODEL_NAME = "tts_models/multilingual/multi-dataset/xtts_v2"
ALLOWED_ROOT = Path(r"C:\MesoAI\private\profile-v1\source\normalized").resolve()
MAX_TEXT = 1200
MAX_REFERENCES = 4

app = FastAPI(title="MesoAI Native XTTS", docs_url=None, redoc_url=None)
_model = None
_model_lock = Lock()
_synthesis_lock = Lock()


class SynthesisRequest(BaseModel):
    text: str = Field(min_length=1, max_length=MAX_TEXT)
    language: str
    speaker_wav: list[str] = Field(min_length=1, max_length=MAX_REFERENCES)


def _device() -> str:
    return "cuda" if torch.cuda.is_available() else "cpu"


def _resolve_reference(value: str) -> str:
    try:
        path = Path(value).resolve()
        path.relative_to(ALLOWED_ROOT)
    except (OSError, RuntimeError, ValueError):
        raise HTTPException(400, "invalid_reference")
    if path.suffix.lower() != ".wav" or not path.is_file() or path.stat().st_size <= 44:
        raise HTTPException(400, "invalid_reference")
    return str(path)


def _load_model():
    global _model
    if _model is not None:
        return _model
    with _model_lock:
        if _model is None:
            from TTS.api import TTS
            _model = TTS(MODEL_NAME, progress_bar=False).to(_device())
    return _model


@app.get("/health")
def health() -> dict:
    return {
        "ok": True,
        "engine": "xtts-v2",
        "device": _device(),
        "gpu": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        "model_loaded": _model is not None,
    }


@app.post("/synthesize")
def synthesize(request: SynthesisRequest) -> Response:
    text = request.text.strip()
    language = request.language.strip().lower()
    if not text or "\x00" in text:
        raise HTTPException(400, "invalid_text")
    if language not in {"en", "ar"}:
        raise HTTPException(400, "unsupported_language")
    refs = [_resolve_reference(item) for item in request.speaker_wav]

    fd, output = tempfile.mkstemp(prefix="meso-xtts-", suffix=".wav")
    os.close(fd)
    try:
        with _synthesis_lock:
            _load_model().tts_to_file(
                text=text,
                speaker_wav=refs,
                language=language,
                file_path=output,
                split_sentences=True,
            )
        audio = Path(output).read_bytes()
    finally:
        Path(output).unlink(missing_ok=True)
    if len(audio) < 44 or audio[:4] != b"RIFF" or audio[8:12] != b"WAVE":
        raise HTTPException(503, "invalid_wav")
    return Response(content=audio, media_type="audio/wav", headers={"Cache-Control": "no-store"})
