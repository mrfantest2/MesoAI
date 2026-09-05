from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_plan_explicitly_forbids_private_manifest_web_copy():
    plan=(ROOT/"docs/superpowers/plans/2026-09-05-meso-voice-v24-chatterbox-identity.md").read_text(encoding="utf-8")
    assert "never copies private manifest/audio into web root" in plan
