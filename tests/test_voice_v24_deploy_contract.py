from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_deploy_stages_v24_without_private_data():
    deploy = (ROOT / "deploy/deploy_to_xampp.ps1").read_text(encoding="utf-8")
    assert "meso_chatterbox_v24_client.py" in deploy
    assert "voice-lab-v24.php" in deploy
    assert "voice-lab-v24-audio.php" in deploy
    assert "voice-lab-v24\\manifest.json" not in deploy
