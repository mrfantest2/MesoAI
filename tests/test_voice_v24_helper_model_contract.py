from tools.meso_chatterbox_v24_client import public_result


def test_helper_reports_chatterbox_multilingual_v3():
    result = public_result("en", 1, "A", 2048)
    assert result["engine"] == "chatterbox"
    assert result["model"] == "multilingual-v3"
