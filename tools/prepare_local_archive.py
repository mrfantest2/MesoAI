#!/usr/bin/env python3
"""Build the private MesoAI reference profile from a 1-to-1 WhatsApp export.

The source archive is never modified. Chat text is used only to map audio attachments
to sender metadata and is not copied into the private dataset.
"""
from __future__ import annotations
import argparse, hashlib, json, re, subprocess, zipfile
from datetime import datetime, timezone
from pathlib import Path

AUDIO_EXT = {".opus",".ogg",".wav",".mp3",".m4a",".aac",".flac"}
ATTACH_RE = re.compile(r"^\u200e?\[(.*?)\]\s*([^:]+):\s*.*?<attached:\s*([^>]+)>", re.M)

def sha256_file(path: Path) -> str:
    h=hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda:f.read(1024*1024),b""): h.update(chunk)
    return h.hexdigest()

def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def clean_sender(v: str) -> str:
    return v.strip().replace("\u200e","").replace("\u202a","").replace("\u202c","")

def run(cmd: list[str]) -> str:
    p=subprocess.run(cmd,capture_output=True,text=True,encoding="utf-8",errors="replace")
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or "command failed")[-2000:])
    return (p.stdout or "")+(p.stderr or "")

def probe(path: Path) -> tuple[float,int,int]:
    raw=run(["ffprobe","-v","error","-show_entries","format=duration:stream=sample_rate,channels","-of","json",str(path)])
    d=json.loads(raw); s=(d.get("streams") or [{}])[0]
    return float((d.get("format") or {}).get("duration") or 0), int(s.get("sample_rate") or 0), int(s.get("channels") or 0)

def metrics(path: Path, duration: float) -> dict:
    p=subprocess.run(["ffmpeg","-hide_banner","-nostats","-i",str(path),
                      "-af","volumedetect,silencedetect=noise=-40dB:d=0.25",
                      "-f","null","-"],capture_output=True,text=True,encoding="utf-8",errors="replace")
    text=(p.stdout or "")+(p.stderr or "")
    mean=re.findall(r"mean_volume:\s*([-\d.]+) dB",text)
    peak=re.findall(r"max_volume:\s*([-\d.]+) dB",text)
    sil=[float(x) for x in re.findall(r"silence_duration:\s*([\d.]+)",text)]
    silence=sum(sil)
    mean_db=float(mean[-1]) if mean else None
    peak_db=float(peak[-1]) if peak else None
    silence_ratio=min(1.0,silence/max(duration,0.001))
    dscore=1.0 if 6 <= duration <= 24 else 0.82 if 3 <= duration <= 45 else 0.35
    sscore=max(0.0,1.0-silence_ratio/0.55)
    lscore=1.0 if mean_db is not None and -32 <= mean_db <= -12 else 0.65 if mean_db is not None else 0.25
    cscore=0.15 if peak_db is not None and peak_db > -0.15 else 1.0
    score=100*(.36*dscore+.29*sscore+.20*lscore+.15*cscore)
    return {"mean_db":mean_db,"peak_db":peak_db,"silence_ratio":round(silence_ratio,4),"quality_score":round(score,2)}

def normalize(src: Path,dst: Path)->None:
    dst.parent.mkdir(parents=True,exist_ok=True)
    run(["ffmpeg","-nostdin","-hide_banner","-loglevel","error","-y","-i",str(src),
         "-vn","-ac","1","-ar","24000","-c:a","pcm_s16le",str(dst)])

def main()->int:
    ap=argparse.ArgumentParser()
    ap.add_argument("archive",type=Path)
    ap.add_argument("private_root",type=Path)
    ap.add_argument("--target",default="Maissoun Moussa")
    ap.add_argument("--negative",default="Jamal Bro")
    ap.add_argument("--count",type=int,default=20)
    ap.add_argument("--min-score",type=float,default=70)
    ap.add_argument("--authorized-at",default="")
    args=ap.parse_args()

    archive=args.archive.resolve()
    root=args.private_root.resolve()
    raw=root/"dataset"
    refs=root/"profiles"/"meso"/"references"
    raw.mkdir(parents=True,exist_ok=True); refs.mkdir(parents=True,exist_ok=True)
    for role in ("target","negative","other"): (raw/role).mkdir(exist_ok=True)

    samples=[]
    with zipfile.ZipFile(archive) as zf:
        chat=zf.read("_chat.txt").decode("utf-8")
        names=set(zf.namelist())
        for m in ATTACH_RE.finditer(chat):
            ts,sender,name=m.groups(); sender=clean_sender(sender); name=name.strip()
            if name not in names or Path(name).suffix.lower() not in AUDIO_EXT: continue
            role="target" if sender==args.target else "negative" if sender==args.negative else "other"
            data=zf.read(name)
            dst=raw/role/Path(name).name
            if not dst.exists() or sha256_file(dst)!=sha256_bytes(data): dst.write_bytes(data)
            dur,sr,ch=probe(dst)
            row={"timestamp":ts,"sender":sender,"role":role,"filename":dst.name,
                 "sha256":sha256_file(dst),"bytes":dst.stat().st_size,
                 "duration_s":round(dur,3),"sample_rate":sr,"channels":ch}
            row.update(metrics(dst,dur)); samples.append(row)

    targets=[x for x in samples if x["role"]=="target" and 3 <= x["duration_s"] <= 45
             and x["silence_ratio"] <= .55 and x["quality_score"] >= args.min_score]
    targets.sort(key=lambda x:(-x["quality_score"],-x["duration_s"]))
    chosen=[]; per_day={}
    for x in targets:
        day=str(x["timestamp"]).split(",")[0].strip()
        if per_day.get(day,0)>=2: continue
        chosen.append(x); per_day[day]=per_day.get(day,0)+1
        if len(chosen)>=args.count: break
    if len(chosen)<args.count:
        used={x["sha256"] for x in chosen}
        for x in targets:
            if x["sha256"] in used: continue
            chosen.append(x)
            if len(chosen)>=args.count: break
    if not chosen:
        raise SystemExit("no eligible Maissoun references")

    for old in refs.glob("meso_ref_*.wav"): old.unlink()
    profile_refs=[]
    for i,x in enumerate(chosen,1):
        src=raw/"target"/x["filename"]; dst=refs/f"meso_ref_{i:02d}.wav"
        normalize(src,dst)
        profile_refs.append({"path":str(dst.resolve()),"sha256":sha256_file(dst),
                             "source_sha256":x["sha256"],"source_filename":x["filename"],
                             "sender":args.target,"speaker_verified":True,
                             "speaker_verified_by":"whatsapp_sender_metadata",
                             "quality_score":x["quality_score"],"duration_s":x["duration_s"]})

    authorized_at=args.authorized_at.strip()
    consent={"authorized":bool(authorized_at),"synthesis_allowed":bool(authorized_at),
             "scope":"local_private_voice_clone","recorded_at":authorized_at or None}
    (root/"consent.json").write_text(json.dumps(consent,ensure_ascii=False,indent=2),encoding="utf-8")
    profile={"profile":"meso","version":1,"created_at":datetime.now(timezone.utc).isoformat(),
             "authority":"whatsapp_sender_metadata_plus_acoustic_quality_review",
             "provider_upload":False,"reference_count":len(profile_refs),
             "references":profile_refs,"consent":consent,"synthesis_allowed":consent["synthesis_allowed"]}
    profile_path=root/"profiles"/"meso"/"profile.json"
    profile_path.write_text(json.dumps(profile,ensure_ascii=False,indent=2),encoding="utf-8")
    (root/"analysis.json").write_text(json.dumps(samples,ensure_ascii=False,indent=2),encoding="utf-8")
    summary={"ok":True,"archive_sha256":sha256_file(archive),"audio_count":len(samples),
             "target_count":sum(x["role"]=="target" for x in samples),
             "negative_count":sum(x["role"]=="negative" for x in samples),
             "eligible_target_count":len(targets),"selected_references":len(chosen),
             "profile":str(profile_path),"synthesis_allowed":consent["synthesis_allowed"]}
    print(json.dumps(summary,ensure_ascii=False))
    return 0

if __name__=="__main__":
    raise SystemExit(main())
