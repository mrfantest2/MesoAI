from pathlib import Path
from tools.meso_voice_v24_manifest import _forbidden_output


def test_manifest_refuses_web_and_git_outputs():
    assert _forbidden_output(Path(r"C:\xampp\htdocs\meso\manifest.json"))
    assert _forbidden_output(Path(r"C:\repo\.git\manifest.json"))
