import pytest
from tools.meso_chatterbox_v24_client import validate_request


def test_v24_helper_rejects_non_blind_candidate_id():
    with pytest.raises(ValueError):
        validate_request({
            "text": "hello",
            "language": "en",
            "reference_paths": [r"C:\MesoAI\private\voice-lab-v24\refs\a.wav"],
            "output": r"C:\MesoAI\private\voice-lab-v24\ready\x.wav",
            "candidate_id": "MESO_REAL",
        })
