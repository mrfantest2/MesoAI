from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_repo_contains_no_generated_review_audio_artifacts():
    assert not any((ROOT/"web").rglob("*meso-v24*.wav"))
    assert not any((ROOT/"web").rglob("*meso-v24*.mp3"))
