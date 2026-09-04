from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_uses_separate_private_namespace_from_v23():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "voice-lab-v24" in api
    assert "voice-lab-v23\\\\ready" not in api
