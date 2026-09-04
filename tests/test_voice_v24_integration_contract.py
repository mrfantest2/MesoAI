from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_serves_anchor_and_candidate_without_paths():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "kind'=>'anchor'" in api or '"kind":"anchor"' in api
    assert "meso_chatterbox_v24_client.py" in api
    assert "audio_url" in api
    assert "reference_paths" not in api.split("meso_v24_json(200")[-1]
