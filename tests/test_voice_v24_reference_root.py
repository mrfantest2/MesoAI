from pathlib import Path
from tools.meso_chatterbox_v24_client import PRIVATE_ROOT, READY_ROOT


def test_chatterbox_helper_private_roots_are_fixed():
    assert str(PRIVATE_ROOT) == r"C:\MesoAI\private\voice-lab-v24"
    assert str(READY_ROOT).endswith("ready")
