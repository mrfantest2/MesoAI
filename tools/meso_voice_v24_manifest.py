from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

MESO_ALIASES = {"Maissoun Moussa", "Maissoun", "Meso"}
LANES = ("ar-casual", "ar-warm", "en-casual")


def _path_parts(value: object) -> list[str]:
    return [part for part in str(value).replace("\\", "/").lower().split("/") if part]


def _forbidden_output(path: Path) -> bool:
    return any(part in {"htdocs", "www", "web", ".git"} for part in _path_parts(path))


def build_manifest(rows: list[dict[str, Any]]) -> dict[str, Any]:
    clean = [
        r for r in rows
        if str(r.get("speaker", "")) in MESO_ALIASES
        and str(r.get("lang", "")) in {"ar", "en"}
        and float(r.get("quality", 0.0)) >= 0.80
        and str(r.get("path", "")).strip()
    ]
    clean.sort(key=lambda r: float(r.get("quality", 0.0)), reverse=True)
    by_lang = {
        "ar": [r for r in clean if r.get("lang") == "ar"],
        "en": [r for r in clean if r.get("lang") == "en"],
    }
    if len(by_lang["ar"]) < 6 or len(by_lang["en"]) < 2:
        raise ValueError("insufficient verified Meso references for bilingual benchmark")

    lanes = []
    specs = (("ar-casual", "ar"), ("ar-warm", "ar"), ("en-casual", "en"))
    for lane_index, (lane_id, lang) in enumerate(specs):
        pool = by_lang[lang]
        anchor = pool[lane_index % len(pool)]
        profiles: dict[str, list[str]] = {}
        for idx, label in enumerate("ABCDE"):
            selected = (lane_index * 5 + idx + 1) % len(pool)
            profiles[label] = [str(pool[selected]["path"])]
        lanes.append({
            "id": lane_id,
            "language": lang,
            "anchor_id": f"anchor_{lane_id.replace('-', '_')}",
            "anchor_path": str(anchor["path"]),
            "profiles": profiles,
        })
    return {"version": "meso-v2.4", "lanes": lanes}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    out = Path(args.output)
    if _forbidden_output(out):
        raise SystemExit("refusing non-private output path")
    rows = json.loads(Path(args.input).read_text(encoding="utf-8-sig"))
    manifest = build_manifest(rows)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
