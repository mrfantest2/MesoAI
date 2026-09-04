import pytest
from tools.meso_chatterbox_v24_client import validate_request


def test_helper_rejects_reference_outside_v24_private_root():
    with pytest.raises(ValueError):
        validate_request({"text":"hi","language":"en","reference_paths":[r"C:\temp\other.wav"],"output":r"C:\MesoAI\private\voice-lab-v24\ready\x.wav","candidate_id":"A"})
