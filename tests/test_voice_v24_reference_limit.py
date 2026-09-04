import pytest
from tools.meso_chatterbox_v24_client import validate_request


def test_helper_rejects_more_than_four_references():
    with pytest.raises(ValueError):
        validate_request({
            "text": "hello",
            "language": "en",
            "reference_paths": [rf"C:\MesoAI\private\voice-lab-v24\refs\{i}.wav" for i in range(5)],
            "output": r"C:\MesoAI\private\voice-lab-v24\ready\x.wav",
            "candidate_id": "A",
        })
