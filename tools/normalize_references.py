#!/usr/bin/env python3
"""Normalize selected private MesoAI voice references without mutating originals.

Input manifest rows must contain at least:
  path, sender, selected
Only rows selected=true and sender matching --target-sender are processed.
Outputs are mono 24 kHz PCM16 WAV plus a SHA256-backed derived manifest.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
from pathlib import Path


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def run_ffmpeg(src: Path, dst: Path) -> None:
    dst.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        "ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y",
        "-i", str(src), "-vn", "-ac", "1", "-ar", "24000",
        "-c:a", "pcm_s16le", str(dst),
    ]
    subprocess.run(cmd, check=True)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", required=True, type=Path)
    ap.add_argument("--source-root", required=True, type=Path)
    ap.add_argument("--output-root", required=True, type=Path)
    ap.add_argument("--target-sender", default="Maissoun Moussa")
    ap.add_argument("--limit", type=int, default=20)
    args = ap.parse_args()

    rows = json.loads(args.manifest.read_text(encoding="utf-8"))
    if isinstance(rows, dict):
        rows = rows.get("items", [])
    if not isinstance(rows, list):
        raise SystemExit("manifest must contain a list or {items:[...]}")

    selected = [
        r for r in rows
        if isinstance(r, dict)
        and bool(r.get("selected"))
        and str(r.get("sender", "")) == args.target_sender
        and r.get("path")
    ][: max(1, args.limit)]

    if not selected:
        raise SystemExit("no selected target references found")

    out_rows = []
    for idx, row in enumerate(selected, 1):
        rel = Path(str(row["path"]))
        src = (args.source_root / rel).resolve()
        if not src.is_file():
            raise FileNotFoundError(src)
        dst = args.output_root / f"meso_ref_{idx:02d}.wav"
        run_ffmpeg(src, dst)
        out_rows.append({
            "index": idx,
            "source_path": rel.as_posix(),
            "source_sha256": sha256(src),
            "derived_path": dst.name,
            "derived_sha256": sha256(dst),
            "sample_rate": 24000,
            "channels": 1,
            "codec": "pcm_s16le",
            "speaker_verified_by": "whatsapp_sender_metadata",
            "sender": args.target_sender,
        })

    derived = {
        "profile": "meso",
        "target_sender": args.target_sender,
        "reference_count": len(out_rows),
        "originals_mutated": False,
        "references": out_rows,
    }
    manifest_out = args.output_root / "normalized_manifest.json"
    manifest_out.write_text(json.dumps(derived, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"ok": True, "reference_count": len(out_rows), "manifest": str(manifest_out)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
