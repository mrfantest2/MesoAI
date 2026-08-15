#!/usr/bin/env python3
"""Build a local MesoAI voice profile from normalized references.

This tool never uploads audio. It validates hashes from normalized_manifest.json
and writes profiles/meso/profile.json (or another explicit output path).
"""
from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--normalized-manifest", required=True, type=Path)
    ap.add_argument("--reference-root", required=True, type=Path)
    ap.add_argument("--output", required=True, type=Path)
    ap.add_argument("--consent-record", type=Path)
    args = ap.parse_args()

    src = json.loads(args.normalized_manifest.read_text(encoding="utf-8"))
    refs = src.get("references", []) if isinstance(src, dict) else []
    if not refs:
        raise SystemExit("normalized manifest has no references")

    verified = []
    for ref in refs:
        path = args.reference_root / str(ref["derived_path"])
        if not path.is_file():
            raise FileNotFoundError(path)
        actual = sha256(path)
        expected = str(ref.get("derived_sha256", ""))
        if actual != expected:
            raise SystemExit(f"hash mismatch: {path.name}")
        verified.append({
            "path": str(path.resolve()),
            "sha256": actual,
            "speaker_verified_by": ref.get("speaker_verified_by", "whatsapp_sender_metadata"),
            "sender": ref.get("sender"),
        })

    consent = {"status": "not_recorded", "synthesis_allowed": False}
    if args.consent_record and args.consent_record.is_file():
        raw = json.loads(args.consent_record.read_text(encoding="utf-8"))
        if isinstance(raw, dict):
            consent = raw

    profile = {
        "profile": "meso",
        "version": 1,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "authority": "whatsapp_sender_metadata_plus_acoustic_quality_review",
        "provider_upload": False,
        "reference_count": len(verified),
        "references": verified,
        "consent": consent,
        "synthesis_allowed": bool(consent.get("synthesis_allowed") is True),
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    tmp = args.output.with_suffix(args.output.suffix + ".tmp")
    tmp.write_text(json.dumps(profile, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(args.output)
    print(json.dumps({"ok": True, "profile": str(args.output), "reference_count": len(verified), "synthesis_allowed": profile["synthesis_allowed"]}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
