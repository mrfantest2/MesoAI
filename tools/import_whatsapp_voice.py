#!/usr/bin/env python3
"""Extract only voice attachments from a WhatsApp export and label them by sender.

The source archive is read-only. Chat text is used only to map attachment -> sender and
is not copied into the output dataset.
"""
from __future__ import annotations
import argparse, hashlib, json, re, zipfile
from pathlib import Path

AUDIO_EXT = {".opus", ".ogg", ".wav", ".mp3", ".m4a", ".aac", ".flac"}
ATTACH_RE = re.compile(r"^\u200e?\[(.*?)\]\s*([^:]+):\s*.*?<attached:\s*([^>]+)>", re.M)


def clean_sender(value: str) -> str:
    return value.strip().replace("\u200e", "").replace("\u202a", "").replace("\u202c", "")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("archive", type=Path)
    ap.add_argument("output", type=Path)
    ap.add_argument("--target", default="Maissoun Moussa")
    ap.add_argument("--negative", action="append", default=["Jamal Bro"])
    args = ap.parse_args()

    archive = args.archive.resolve()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    (output / "target").mkdir(exist_ok=True)
    (output / "negative").mkdir(exist_ok=True)
    (output / "other").mkdir(exist_ok=True)

    archive_hash = hashlib.sha256(archive.read_bytes()).hexdigest()
    manifest = []

    with zipfile.ZipFile(archive) as zf:
        chat = zf.read("_chat.txt").decode("utf-8")
        names = set(zf.namelist())
        for match in ATTACH_RE.finditer(chat):
            timestamp, sender, attachment = match.groups()
            sender = clean_sender(sender)
            attachment = attachment.strip()
            if attachment not in names or Path(attachment).suffix.lower() not in AUDIO_EXT:
                continue
            role = "target" if sender == args.target else "negative" if sender in args.negative else "other"
            data = zf.read(attachment)
            dest = output / role / Path(attachment).name
            dest.write_bytes(data)
            manifest.append({
                "sender": sender,
                "role": role,
                "timestamp": timestamp,
                "filename": dest.name,
                "bytes": len(data),
                "sha256": sha256_bytes(data),
            })

    payload = {
        "source_archive": archive.name,
        "source_sha256": archive_hash,
        "target_sender": args.target,
        "negative_senders": args.negative,
        "audio_count": len(manifest),
        "samples": manifest,
    }
    (output / "manifest.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({
        "audio_count": len(manifest),
        "target": sum(x["role"] == "target" for x in manifest),
        "negative": sum(x["role"] == "negative" for x in manifest),
        "other": sum(x["role"] == "other" for x in manifest),
        "source_sha256": archive_hash,
    }, indent=2))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
