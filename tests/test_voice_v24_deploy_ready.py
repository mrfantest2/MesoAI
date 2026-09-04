from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_browser_uses_v24_identity_lab():
    page = (ROOT / "web/voice-lab/index.php").read_text(encoding="utf-8")
    js = (ROOT / "web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "Voice v2.4" in page
    assert "Real Meso reference" in page
    assert "Generated candidate" in page
    assert "/meso/api/voice-lab-v24.php" in js
    assert "/meso/api/voice-lab-v24-audio.php" in js
    assert "meso-v2.4" in js
    assert "action:'anchor'" in js or 'action: "anchor"' in js


def test_v24_api_is_live_review_only_not_stubbed():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "meso_chatterbox_v24_client.py" in api
    assert "kind'=>'anchor'" in api or '"kind":"anchor"' in api
    assert "kind'=>'candidate'" in api or '"kind":"candidate"' in api
    assert "audio_url" in api
    assert "proc_open" in api
    assert "profile.json" not in api
    assert "promote" not in api.lower()


def test_v24_client_maps_private_windows_paths_to_container_mount():
    helper = (ROOT / "tools/meso_chatterbox_v24_client.py").read_text(encoding="utf-8")
    assert "/data/meso-v24" in helper
    assert "reference_paths" in helper
    assert "output_path" in helper
    assert "http://127.0.0.1:8295" in helper


def test_deploy_stages_v24_client_outside_webroot():
    deploy = (ROOT / "deploy/deploy_to_xampp.ps1").read_text(encoding="utf-8")
    assert "meso_chatterbox_v24_client.py" in deploy
    assert "chatterbox-v24" in deploy
    assert "MESO_V24_CHATTERBOX_CLIENT_STAGED=true" in deploy
