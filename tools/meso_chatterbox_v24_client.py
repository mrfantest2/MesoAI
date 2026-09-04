from __future__ import annotations

import json
import os
import sys
import urllib.request
from pathlib import Path
from typing import Any

PRIVATE_ROOT = Path(r"C:\MesoAI\private\voice-lab-v24")
READY_ROOT = PRIVATE_ROOT / "ready"


def _under(path: Path, root: Path) -> bool:
    try:
        path.resolve().relative_to(root.resolve())
        return True
    except Exception:
        return False


def validate_request(body: dict[str, Any]) -> dict[str, Any]:
    text = str(body.get("text", "")).strip()
    language = str(body.get("language", "")).strip().lower()
    refs = [str(x) for x in body.get("reference_paths", [])]
    output = Path(str(body.get("output", "")))
    candidate_id = str(body.get("candidate_id", "")).strip().upper()
    if not (1 <= len(text) <= 600):
        raise ValueError("invalid text")
    if language not in {"ar", "en"}:
        raise ValueError("invalid language")
    if not (1 <= len(refs) <= 4):
        raise ValueError("invalid references")
    if candidate_id not in set("ABCDE"):
        raise ValueError("invalid candidate")
    ref_paths = [Path(p) for p in refs]
    if any(not _under(p, PRIVATE_ROOT) for p in ref_paths):
        raise ValueError("reference outside private root")
    if not _under(output, READY_ROOT):
        raise ValueError("output outside ready root")
    return {"text": text, "language": language, "reference_paths": refs, "output": str(output), "candidate_id": candidate_id}


def public_result(language: str, references: int, candidate_id: str, output_bytes: int) -> dict[str, Any]:
    return {"ok": True, "engine": "chatterbox", "model": "multilingual-v3", "language": language, "references": references, "candidate_id": candidate_id, "output_bytes": output_bytes}


def synthesize(req: dict[str, Any]) -> dict[str, Any]:
    endpoint = os.environ.get("MESO_CHATTERBOX_URL", "http://127.0.0.1:8295").rstrip("/") + "/synthesize"
    out = Path(req["output"])
    out.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps({
        "text": req["text"],
        "language": req["language"],
        "reference_paths": req["reference_paths"],
        "output_path": req["output"],
    }, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(endpoint, data=payload, headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(request, timeout=300) as response:
        if response.status != 200:
            raise RuntimeError("chatterbox request failed")
        meta = json.loads(response.read().decode("utf-8"))
    if not out.is_file() or out.stat().st_size < 1024:
        raise RuntimeError("invalid synthesis output")
    if not isinstance(meta, dict) or not meta.get("ok"):
        raise RuntimeError("invalid chatterbox response")
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
