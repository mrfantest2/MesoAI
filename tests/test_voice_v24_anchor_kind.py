from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_media_metadata_can_distinguish_anchor_candidate():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "anchor" in audio and "candidate" in audio
