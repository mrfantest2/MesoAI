import pytest
from tools.meso_chatterbox_v24_client import validate_request


def test_helper_rejects_output_outside_private_ready():
    with pytest.raises(ValueError):
        validate_request({
            "text": "hello",
            "language": "en",
            "reference_paths": [r"C:\MesoAI\private\voice-lab-v24\refs\a.wav"],
            "output": r"C:\xampp\htdocs\meso\x.wav",
            "candidate_id": "A",
        })
