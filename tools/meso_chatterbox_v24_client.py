from __future__ import annotations

import json
import ntpath
import os
import sys
import urllib.request
from pathlib import Path
from typing import Any

PRIVATE_ROOT_WIN = r"C:\MesoAI\private\voice-lab-v24"
READY_ROOT_WIN = PRIVATE_ROOT_WIN + r"\ready"
PRIVATE_ROOT = Path(PRIVATE_ROOT_WIN)
READY_ROOT = Path(READY_ROOT_WIN)
RUNTIME_ROOT = "/data/meso-v24"


def _norm_windows(value: str) -> str:
    value = ntpath.normpath(str(value).strip())
    if not value or not ntpath.isabs(value):
        raise ValueError("absolute Windows path required")
    return value


def _under_windows(value: str, root: str) -> bool:
    try:
        path = ntpath.normcase(_norm_windows(value))
        base = ntpath.normcase(_norm_windows(root))
        return ntpath.commonpath([path, base]) == base
    except (ValueError, OSError):
        return False


def _to_runtime_path(value: str) -> str:
    if not _under_windows(value, PRIVATE_ROOT_WIN):
        raise ValueError("path outside private root")
    rel = ntpath.relpath(_norm_windows(value), _norm_windows(PRIVATE_ROOT_WIN))
    if rel == ".":
        return RUNTIME_ROOT
    if rel == ".." or rel.startswith(".." + ntpath.sep):
        raise ValueError("path outside private root")
    return RUNTIME_ROOT + "/" + rel.replace("\\", "/")


def validate_request(body: dict[str, Any]) -> dict[str, Any]:
    text = str(body.get("text", "")).strip()
    language = str(body.get("language", "")).strip().lower()
    refs = [str(x) for x in body.get("reference_paths", [])]
    output = str(body.get("output", "")).strip()
    candidate_id = str(body.get("candidate_id", "")).strip().upper()
    if not (1 <= len(text) <= 600) or "\x00" in text:
        raise ValueError("invalid text")
    if language not in {"ar", "en"}:
        raise ValueError("invalid language")
    if not (1 <= len(refs) <= 4):
        raise ValueError("invalid references")
    if candidate_id not in set("ABCDE"):
        raise ValueError("invalid candidate")
    if any(not _under_windows(p, PRIVATE_ROOT_WIN) for p in refs):
        raise ValueError("reference outside private root")
    if not _under_windows(output, READY_ROOT_WIN):
        raise ValueError("output outside ready root")
    return {
        "text": text,
        "language": language,
        "reference_paths": [_norm_windows(p) for p in refs],
        "output": _norm_windows(output),
        "candidate_id": candidate_id,
    }


def public_result(language: str, references: int, candidate_id: str, output_bytes: int) -> dict[str, Any]:
    return {
        "ok": True,
        "engine": "chatterbox",
        "model": "multilingual-v3",
        "language": language,
        "references": references,
        "candidate_id": candidate_id,
        "output_bytes": output_bytes,
    }


def synthesize(req: dict[str, Any]) -> dict[str, Any]:
    endpoint = os.environ.get("MESO_CHATTERBOX_URL", "http://127.0.0.1:8295").rstrip("/") + "/synthesize"
    out = Path(req["output"])
    out.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(
        {
            "text": req["text"],
            "language": req["language"],
            "reference_paths": [_to_runtime_path(p) for p in req["reference_paths"]],
            "output_path": _to_runtime_path(req["output"]),
            "candidate_id": req["candidate_id"],
        },
        ensure_ascii=False,
    ).encode("utf-8")
    request = urllib.request.Request(endpoint, data=payload, headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(request, timeout=300) as response:
        if response.status != 200:
            raise RuntimeError("chatterbox request failed")
        meta = json.loads(response.read().decode("utf-8"))
    if not out.is_file() or out.stat().st_size < 1024:
        raise RuntimeError("invalid synthesis output")
    if not isinstance(meta, dict) or meta.get("ok") is not True:
        raise RuntimeError("invalid chatterbox response")
    if meta.get("engine") != "chatterbox" or meta.get("model") != "multilingual-v3":
        raise RuntimeError("unexpected chatterbox runtime")
    return public_result(req["language"], len(req["reference_paths"]), req["candidate_id"], out.stat().st_size)


def main() -> int:
    try:
        body = json.loads(sys.stdin.read())
        req = validate_request(body)
        result = synthesize(req)
        sys.stdout.write(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception:
        sys.stdout.write(json.dumps({"ok": False, "error": "synthesis_failed"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
