#!/usr/bin/env python3
"""Select conservative high-quality target references from analysis.json."""
from __future__ import annotations
import argparse, json
from pathlib import Path


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("analysis", type=Path)
    ap.add_argument("--count", type=int, default=20)
    ap.add_argument("--min-score", type=float, default=70.0)
    args = ap.parse_args()
    rows = json.loads(args.analysis.read_text(encoding="utf-8"))
    eligible = [r for r in rows if r.get("role") == "target"
                and 3.0 <= float(r.get("duration_s") or 0) <= 45.0
                and float(r.get("silence_ratio") or 1) <= 0.55
                and float(r.get("quality_score") or 0) >= args.min_score]
    eligible.sort(key=lambda r: (-float(r["quality_score"]), -float(r["duration_s"])))
    chosen = eligible[:args.count]
    out = args.analysis.with_name("references.json")
    out.write_text(json.dumps(chosen, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"eligible={len(eligible)} selected={len(chosen)} output={out}")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
