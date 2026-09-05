from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_accepts_only_anchor_or_candidate_kind():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "['anchor','candidate']" in audio
