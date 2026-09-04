from tools.meso_chatterbox_v24_client import public_result, validate_request


def test_chatterbox_request_requires_private_refs():
    req = validate_request({
        "text": "مرحبا كيفك اليوم؟",
        "language": "ar",
        "reference_paths": [r"C:\MesoAI\private\voice-lab-v24\refs\a.wav"],
        "output": r"C:\MesoAI\private\voice-lab-v24\ready\x.wav",
        "candidate_id": "A",
    })
    assert req["language"] == "ar"


def test_public_result_has_no_private_path():
    body = public_result("ar", 2, "A", 12345)
    assert "path" not in str(body).lower()
    assert body["engine"] == "chatterbox"
    assert body["model"] == "multilingual-v3"
