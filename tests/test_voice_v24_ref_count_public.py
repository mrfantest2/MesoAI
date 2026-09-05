from tools.meso_chatterbox_v24_client import public_result


def test_v24_public_metadata_may_report_only_reference_count():
    result=public_result("en",3,"C",8192)
    assert result["references"]==3
    assert "reference_paths" not in result
