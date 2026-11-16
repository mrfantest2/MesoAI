"""Run existing zero-dependency CP027 contract tests."""
from pathlib import Path
import runpy

root = Path(__file__).resolve().parent
cases = [
    ("test_voice_v24_media_auth_contract.py", "test_v24_audio_endpoint_requires_chat_auth"),
    ("test_voice_v24_auth_contract.py", "test_v24_api_requires_existing_chat_json_auth"),
]
for filename, function in cases:
    scope = runpy.run_path(str(root / filename))
    scope[function]()
    print(f"PASS {filename}::{function}")
print(f"CP027_CONTRACT_PASS={len(cases)}")
