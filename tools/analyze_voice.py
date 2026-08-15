#!/usr/bin/env python3
"""Analyze a MesoAI voice dataset using local ffprobe/ffmpeg only."""
from __future__ import annotations
import argparse, json, re, subprocess
from pathlib import Path


def run(cmd: list[str]) -> str:
    p = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    return (p.stdout or "") + (p.stderr or "")


def probe(path: Path) -> dict:
    raw = run(["ffprobe", "-v", "error", "-show_entries",
               "format=duration:stream=codec_name,sample_rate,channels",
               "-of", "json", str(path)])
    doc = json.loads(raw)
    stream = (doc.get("streams") or [{}])[0]
    duration = float((doc.get("format") or {}).get("duration") or 0)
    return {
        "duration_s": round(duration, 3),
        "codec": stream.get("codec_name"),
        "sample_rate": int(stream.get("sample_rate") or 0),
        "channels": int(stream.get("channels") or 0),
    }


def audio_metrics(path: Path) -> dict:
    text = run(["ffmpeg", "-hide_banner", "-nostats", "-i", str(path),
                "-af", "volumedetect,silencedetect=noise=-40dB:d=0.25", "-f", "null", "-"])
    mean = re.findall(r"mean_volume:\s*([-\d.]+) dB", text)
    peak = re.findall(r"max_volume:\s*([-\d.]+) dB", text)
    silence_starts = [float(v) for v in re.findall(r"silence_start:\s*([\d.]+)", text)]
    silence_ends = [(float(a), float(b)) for a,b in re.findall(r"silence_end:\s*([\d.]+)\s*\|\s*silence_duration:\s*([\d.]+)", text)]
    silence = sum(d for _, d in silence_ends)
    return {
        "mean_db": float(mean[-1]) if mean else None,
        "peak_db": float(peak[-1]) if peak else None,
        "silence_s": round(silence, 3),
        "silence_events": max(len(silence_starts), len(silence_ends)),
    }


def score(m: dict) -> float:
    d = m["duration_s"]
    duration_score = 1.0 if 6 <= d <= 24 else 0.75 if 2.5 <= d <= 60 else 0.2
    silence_ratio = min(1.0, (m.get("silence_s") or 0) / max(d, 0.001))
    silence_score = max(0.0, 1.0 - silence_ratio / 0.55)
    mean = m.get("mean_db")
    loud_score = 1.0 if mean is not None and -32 <= mean <= -12 else 0.6 if mean is not None else 0.3
    peak = m.get("peak_db")
    clipping_score = 0.1 if peak is not None and peak > -0.15 else 1.0
    return round(100 * (0.35 * duration_score + 0.30 * silence_score + 0.20 * loud_score + 0.15 * clipping_score), 2)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("dataset", type=Path)
    args = ap.parse_args()
    dataset = args.dataset.resolve()
    doc = json.loads((dataset / "manifest.json").read_text(encoding="utf-8"))
    samples = doc.get("samples", [])
    results = []
    for item in samples:
        path = dataset / item["role"] / item["filename"]
        if not path.exists():
            continue
        m = {**item, **probe(path), **audio_metrics(path)}
        m["silence_ratio"] = round((m.get("silence_s") or 0) / max(m["duration_s"], 0.001), 4)
        m["quality_score"] = score(m)
        results.append(m)
    results.sort(key=lambda x: (x["role"] != "target", -x["quality_score"]))
    out = dataset / "analysis.json"
    out.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"analyzed={len(results)} output={out}")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
