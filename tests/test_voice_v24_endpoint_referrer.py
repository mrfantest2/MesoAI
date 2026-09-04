from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_endpoints_disable_referrer_leakage():
    for rel in ("web/api/voice-lab-v24.php","web/api/voice-lab-v24-audio.php"):
        assert "Referrer-Policy: no-referrer" in (ROOT/rel).read_text(encoding="utf-8")
