from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

MESO_ALIASES = {"Maissoun Moussa", "Maissoun", "Meso"}
LANES = ("ar-casual", "ar-warm", "en-casual")


def _forbidden_output(path: Path) -> bool:
    return any(part.lower() in {"htdocs", "www", "web", ".git"} for part in path.parts)


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
            first = (lane_index * 5 + idx + 1) % len(pool)
            count = 2 if lang == "ar" else 1
            profiles[label] = [str(pool[(first + j) % len(pool)]["path"]) for j in range(count)]
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
    out = Path(args.output).resolve()
    if _forbidden_output(out):
        raise SystemExit("refusing non-private output path")
    rows = json.loads(Path(args.input).read_text(encoding="utf-8"))
    manifest = build_manifest(rows)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
