# Meso Voice v2.4 Chatterbox Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and deploy a private, review-only Chatterbox Multilingual V3 identity benchmark for Meso with real historical Meso anchors, blinded A-E candidates, Arabic/English lanes, and zero production promotion.

**Architecture:** MesoAI owns the authenticated review API/UI and the browser-safe media-token boundary. KDT owns MASTER-PC runtime orchestration, Chatterbox preflight, private reference preparation, synthesis execution, and deployment certification. All source mappings, historical anchors, reference WAVs, transcripts, and review outputs stay under `C:\MesoAI\private\voice-lab-v24\`; Git contains only code and contracts.

**Tech Stack:** PHP 8, browser JavaScript, Python 3, Chatterbox Multilingual V3, Docker/RunPod-compatible KDT runtime patterns, PowerShell, GitHub Actions on MASTER-PC, ffmpeg/ffprobe where already available.

**Spec:** `docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md`

## Global Constraints

- Production TTS remains `meso-v2` until an explicit user-approved promotion operation exists.
- Raw WhatsApp exports, transcripts, private source IDs, reference mappings, historical anchor files, generated review media, and local paths never enter Git or the public web root.
- Only confidently attributed Meso/Maissoun audio may be used as voice references or historical anchors.
- Mira/children/third-party voices are excluded.
- Real anchors must be clearly labeled historical reference audio; synthesized candidates must be clearly labeled generated audio.
- No automatic ranking, winner selection, profile creation, or promotion.
- Human identity judgment is authoritative; machine metrics are diagnostic only.
- If Chatterbox is rejected by the user, next architecture is Fish S2, not another XTTS sweep.

---

### Task 1: Add v2.4 browser/API contracts with no synthesis implementation

**Files:**
- Create: `web/api/voice-lab-v24.php`
- Create: `web/api/voice-lab-v24-audio.php`
- Modify: `web/voice-lab/voice-lab.js`
- Test: `tests/test_voice_v24_contract.py`

**Interfaces:**
- Consumes: authenticated chat session helpers already used by v2.3.
- Produces: `POST /meso/api/voice-lab-v24.php` actions `status`, `vote`, `synthesize`; tokenized anchor/candidate media through `/meso/api/voice-lab-v24-audio.php?id=<64hex>`.

- [ ] **Step 1: Write the failing contract test**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_voice_v24_is_review_only_and_private():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    js = (ROOT / "web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "meso-v2.4" in api
    assert "voice-lab-v24" in api
    assert "Real Meso reference" in js
    assert "generated candidate" in js.lower()
    assert "promote" not in api.lower()
    assert "profile.json" not in api
    assert "source_id" not in api
    assert "transcript" not in api
```

- [ ] **Step 2: Run the test and verify RED**

Run: `python -m pytest tests/test_voice_v24_contract.py -v`

Expected: FAIL because v2.4 API/files do not exist.

- [ ] **Step 3: Implement the minimal review-only API shell**

Create `voice-lab-v24.php` by following v2.3 authentication/error patterns, but use private root `voice-lab-v24`, return version `meso-v2.4`, and support `status`/`vote`. For `synthesize`, return `503 voice_sweep_unavailable` until Task 4 installs the runtime helper. Status must expose only `version`, `batch_count`, `labels`, and lane IDs.

Create `voice-lab-v24-audio.php` with the same token validation/expiry model as v2.3, but permit a media metadata field `kind` whose only values are `anchor` and `candidate`. Never return local paths.

Update `voice-lab.js` to point to v2.4 and render an explicit `Real Meso reference` player before A-E candidate controls, with synthesized candidates labeled `Generated candidate A` ... `Generated candidate E`.

- [ ] **Step 4: Run focused syntax/contracts**

Run:
```text
php -l web/api/voice-lab-v24.php
php -l web/api/voice-lab-v24-audio.php
node --check web/voice-lab/voice-lab.js
python -m pytest tests/test_voice_v24_contract.py -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/api/voice-lab-v24.php web/api/voice-lab-v24-audio.php web/voice-lab/voice-lab.js tests/test_voice_v24_contract.py
git commit -m "feat: add Meso Voice v2.4 review contracts"
```

### Task 2: Add private v2.4 manifest schema and anchor-token preparation

**Files:**
- Create: `tools/meso_voice_v24_manifest.py`
- Test: `tests/test_voice_v24_manifest.py`

**Interfaces:**
- Consumes: private analysis summary/reference pool prepared on MASTER-PC; input JSON contains only local paths and quality metadata.
- Produces: `C:\MesoAI\private\voice-lab-v24\manifest.json` with opaque candidate/anchor IDs, lanes, and private reference paths. No file is written under repo/web root.

- [ ] **Step 1: Write failing tests for attribution/privacy constraints**

```python
import json
from pathlib import Path
from tools.meso_voice_v24_manifest import build_manifest


def test_manifest_rejects_non_meso_and_keeps_paths_private(tmp_path: Path):
    rows = [
        {"speaker": "Maissoun Moussa", "path": r"C:\private\meso1.wav", "lang": "ar", "quality": 0.95},
        {"speaker": "Mira", "path": r"C:\private\mira.wav", "lang": "ar", "quality": 0.99},
    ]
    manifest = build_manifest(rows)
    refs = json.dumps(manifest, ensure_ascii=False)
    assert "meso1.wav" in refs
    assert "mira.wav" not in refs
    assert all(lane["anchor_id"].startswith("anchor_") for lane in manifest["lanes"])
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest tests/test_voice_v24_manifest.py -v`

Expected: FAIL because module does not exist.

- [ ] **Step 3: Implement deterministic private manifest builder**

Implement `build_manifest(rows: list[dict]) -> dict` with these rules:
- accept speaker aliases only from `{"Maissoun Moussa", "Maissoun", "Meso"}`
- require existing language value `ar` or `en`
- require quality >= `0.80`
- create lanes `ar-casual`, `ar-warm`, `en-casual`
- choose one anchor per lane from the highest-quality suitable clip
- choose five candidate strategies A-E from different top-quality Meso refs without exposing labels-to-path mapping outside the manifest
- emit `version: meso-v2.4`

CLI arguments:
```text
--input <private-analysis-json>
--output C:\MesoAI\private\voice-lab-v24\manifest.json
```

The script must refuse any output path under a directory segment named `htdocs`, `www`, `web`, or `.git`.

- [ ] **Step 4: Run tests**

Run: `python -m pytest tests/test_voice_v24_manifest.py -v`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/meso_voice_v24_manifest.py tests/test_voice_v24_manifest.py
git commit -m "feat: build private Meso Voice v2.4 manifest"
```

### Task 3: Add Chatterbox helper contract to MesoAI

**Files:**
- Create: `tools/meso_chatterbox_v24_client.py`
- Test: `tests/test_meso_chatterbox_v24_client.py`

**Interfaces:**
- Consumes stdin JSON `{text, language, reference_paths, output, candidate_id}`.
- Produces stdout JSON `{ok, engine, model, language, references, candidate_id, output_bytes}`; never prints local reference paths.
- Calls a local KDT-managed Chatterbox HTTP runtime endpoint from environment `MESO_CHATTERBOX_URL`, default `http://127.0.0.1:8295`.

- [ ] **Step 1: Write failing input/output/privacy tests**

```python
from tools.meso_chatterbox_v24_client import validate_request, public_result


def test_chatterbox_request_requires_meso_private_refs():
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
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest tests/test_meso_chatterbox_v24_client.py -v`

Expected: FAIL because module does not exist.

- [ ] **Step 3: Implement minimal helper**

Validate:
- language in `ar,en`
- 1-4 reference paths
- every reference path resolves under `C:\MesoAI\private\voice-lab-v24\`
- output resolves under `C:\MesoAI\private\voice-lab-v24\ready\`
- candidate ID matches `A-E`
- text length 1-600 chars

POST JSON to `${MESO_CHATTERBOX_URL}/synthesize`; require HTTP 200 and a valid WAV/MP3 output file > 1024 bytes. Emit only browser-safe metadata.

- [ ] **Step 4: Run focused tests**

Run: `python -m pytest tests/test_meso_chatterbox_v24_client.py -v`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/meso_chatterbox_v24_client.py tests/test_meso_chatterbox_v24_client.py
git commit -m "feat: add Chatterbox v2.4 client contract"
```

### Task 4: Build the KDT Chatterbox Multilingual V3 review runtime

**Files in `mrfantest2/Khalil-Digital-Twin`:**
- Modify: `.github/chatterbox-image/Dockerfile`
- Modify: `.github/chatterbox-image/requirements.txt`
- Modify: `.github/chatterbox-image/handler.py`
- Create: `.github/workflows/meso-v24-chatterbox-preflight.yml`
- Create: `tools/meso_v24_chatterbox_preflight.ps1`
- Test: `backend/tests/test_meso_v24_chatterbox_contract.py`

**Interfaces:**
- Consumes HTTP `POST /synthesize` with text/language/reference audio and private output binding available only on MASTER-PC/local runtime.
- Produces review audio and safe response metadata; runtime listens only on localhost/controlled container network.

- [ ] **Step 1: Write failing KDT contract test**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_meso_v24_chatterbox_runtime_contract():
    handler = (ROOT / ".github/chatterbox-image/handler.py").read_text(encoding="utf-8")
    workflow = (ROOT / ".github/workflows/meso-v24-chatterbox-preflight.yml").read_text(encoding="utf-8")
    assert "ar" in handler and "en" in handler
    assert "/synthesize" in handler
    assert "MASTER-PC" in workflow
    assert "ACTIVE_PROFILE=meso-v2" in workflow
    assert "meso-v2.4/profile.json" in workflow
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest backend/tests/test_meso_v24_chatterbox_contract.py -v`

Expected: FAIL because workflow/contract does not yet exist.

- [ ] **Step 3: Upgrade the isolated Chatterbox image/handler**

Keep the existing container as the starting point. Add/upgrade Chatterbox Multilingual V3 dependency according to the installed package's supported API. Handler requirements:
- initialize model once per process
- accept `ar` and `en`
- accept 1-4 verified local reference files
- synthesize one output per request
- never log raw transcripts, reference paths, or audio bytes
- return `{ok, engine:"chatterbox", model:"multilingual-v3", language, references, bytes}`
- bind runtime to localhost/control network only

- [ ] **Step 4: Add MASTER-PC preflight**

`tools/meso_v24_chatterbox_preflight.ps1` must verify:
- host `MASTER-PC`
- LocalSystem/admin fence for private paths
- Docker health/API compatibility
- v2.4 private manifest exists
- no `meso-v2.4/profile.json`
- production `meso-v2` profile still exists
- Chatterbox runtime starts
- one Arabic and one English smoke synthesis succeed
- outputs are private and then deleted

Print exact banners:
```text
MESO_V24_CHATTERBOX_PREFLIGHT=PASS
MESO_V24_AR_SMOKE=PASS
MESO_V24_EN_SMOKE=PASS
MESO_V24_PRODUCTION_LOCK=PASS ACTIVE_PROFILE=meso-v2 V24_PROMOTED=0
```

Workflow `meso-v24-chatterbox-preflight.yml` runs the script on `[self-hosted, Windows, X64]` and must not create paid RunPod resources.

- [ ] **Step 5: Run KDT focused tests**

Run:
```text
python -m pytest backend/tests/test_meso_v24_chatterbox_contract.py -v
```

Expected: PASS.

- [ ] **Step 6: Commit KDT runtime changes**

```bash
git add .github/chatterbox-image .github/workflows/meso-v24-chatterbox-preflight.yml tools/meso_v24_chatterbox_preflight.ps1 backend/tests/test_meso_v24_chatterbox_contract.py
git commit -m "feat: add Meso v2.4 Chatterbox review runtime"
```

### Task 5: Wire v2.4 API to Chatterbox helper and historical anchors

**Files:**
- Modify: `web/api/voice-lab-v24.php`
- Modify: `web/api/voice-lab-v24-audio.php`
- Modify: `tools/meso_chatterbox_v24_client.py`
- Test: `tests/test_voice_v24_integration_contract.py`

**Interfaces:**
- Consumes private `manifest.json`, historical anchor path, and KDT Chatterbox helper.
- Produces browser-safe anchor/candidate media tokens in `C:\MesoAI\private\voice-lab-v24\ready\` with one-hour expiry.

- [ ] **Step 1: Write failing integration contract**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_serves_anchor_and_candidate_without_paths():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "kind'=>'anchor'" in api or '"kind":"anchor"' in api
    assert "meso_chatterbox_v24_client.py" in api
    assert "audio_url" in api
    assert "reference_paths" not in api.split("meso_v24_json(200")[-1]
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest tests/test_voice_v24_integration_contract.py -v`

Expected: FAIL because synthesis is still stubbed.

- [ ] **Step 3: Implement anchor + candidate actions**

`status` returns lanes and whether each lane has an anchor.

Add action `anchor` requiring lane ID. It copies the selected historical Meso anchor to a random tokenized file in `ready`, writes metadata `{kind:"anchor", lane, created_at}`, and returns only `/meso/api/voice-lab-v24-audio.php?id=<token>`.

`synthesize` requires lane and label A-E, loads the corresponding private reference list, invokes `meso_chatterbox_v24_client.py`, validates file size and helper metadata, then publishes tokenized media metadata `{kind:"candidate", lane, label, references, created_at}`.

- [ ] **Step 4: Run syntax and integration contracts**

Run:
```text
php -l web/api/voice-lab-v24.php
php -l web/api/voice-lab-v24-audio.php
python -m pytest tests/test_voice_v24_contract.py tests/test_voice_v24_integration_contract.py tests/test_meso_chatterbox_v24_client.py -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/api/voice-lab-v24.php web/api/voice-lab-v24-audio.php tools/meso_chatterbox_v24_client.py tests/test_voice_v24_integration_contract.py
git commit -m "feat: wire Meso v2.4 identity benchmark to Chatterbox"
```

### Task 6: Stage runtime helper and review files during MesoAI deployment

**Files:**
- Modify: `deploy/deploy_to_xampp.ps1`
- Test: `tests/test_voice_v24_deploy_contract.py`

**Interfaces:**
- Consumes MesoAI source tree.
- Produces XAMPP review files plus `C:\ProgramData\KhalilDigitalTwin\meso\chatterbox-bridge\meso_chatterbox_v24_client.py`; never copies private manifest/audio into web root.

- [ ] **Step 1: Write failing deployment contract**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_deploy_stages_v24_without_private_data():
    deploy = (ROOT / "deploy/deploy_to_xampp.ps1").read_text(encoding="utf-8")
    assert "meso_chatterbox_v24_client.py" in deploy
    assert "voice-lab-v24.php" in deploy
    assert "voice-lab-v24-audio.php" in deploy
    assert "voice-lab-v24\\manifest.json" not in deploy
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest tests/test_voice_v24_deploy_contract.py -v`

Expected: FAIL until staging is added.

- [ ] **Step 3: Add minimal staging**

Stage the helper into the existing protected runtime area and deploy v2.4 web/API files through the normal web copy. Do not create or copy private v2.4 directories.

- [ ] **Step 4: Run deploy contract plus existing static suite**

Run:
```text
python -m pytest tests/test_voice_v24_deploy_contract.py -v
python -m pytest tests -q
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add deploy/deploy_to_xampp.ps1 tests/test_voice_v24_deploy_contract.py
git commit -m "deploy: stage Meso Voice v2.4 review helper"
```

### Task 7: Build the private benchmark on MASTER-PC

**Files in KDT:**
- Create: `.github/workflows/meso-v24-build-private-benchmark.yml`
- Create: `tools/meso_v24_build_private_benchmark.ps1`
- Test: `backend/tests/test_meso_v24_private_benchmark_contract.py`

**Interfaces:**
- Consumes existing Mira Meso-only staged notes and v2.3 analysis data on MASTER-PC.
- Produces `C:\MesoAI\private\voice-lab-v24\manifest.json`, normalized private refs, anchors, and empty private `ready`/vote areas.

- [ ] **Step 1: Write failing workflow contract**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_private_benchmark_build_has_strict_speaker_and_no_promotion():
    script = (ROOT / "tools/meso_v24_build_private_benchmark.ps1").read_text(encoding="utf-8")
    assert "Maissoun" in script
    assert "voice-lab-v24" in script
    assert "meso-v2.4/profile.json" in script
    assert "promote" not in script.lower()
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest backend/tests/test_meso_v24_private_benchmark_contract.py -v`

Expected: FAIL because builder does not exist.

- [ ] **Step 3: Implement private benchmark builder**

Builder must:
- fence to MASTER-PC LocalSystem/admin
- locate the existing Meso-only Mira voice-note staging and v2.3 analysis summary
- reject any record not attributed to Meso/Maissoun
- call the MesoAI `meso_voice_v24_manifest.py` from an exact checked-out MesoAI commit
- normalize selected refs/anchors to WAV under `C:\MesoAI\private\voice-lab-v24\refs` and `anchors`
- verify every path stays private
- verify A-E mapping is private
- verify no v2.4 production profile exists

Print:
```text
MESO_V24_PRIVATE_BUILD=PASS
MESO_V24_ANCHORS=PASS AR_CASUAL=1 AR_WARM=1 EN_CASUAL=1
MESO_V24_CANDIDATES=PASS LABELS=5
MESO_V24_NO_PROMOTION=PASS ACTIVE_PROFILE=meso-v2 V24_PROMOTED=0
```

- [ ] **Step 4: Run contract test**

Run: `python -m pytest backend/tests/test_meso_v24_private_benchmark_contract.py -v`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/meso-v24-build-private-benchmark.yml tools/meso_v24_build_private_benchmark.ps1 backend/tests/test_meso_v24_private_benchmark_contract.py
git commit -m "feat: build private Meso v2.4 identity benchmark"
```

### Task 8: Merge, deploy exact SHA, and certify production isolation

**Files in KDT:**
- Create: `.github/workflows/mesoai-deploy-v24-chatterbox.yml`
- Test: `backend/tests/test_meso_v24_deploy_gate.py`

**Interfaces:**
- Consumes exact merged MesoAI SHA and prepared private v2.4 benchmark/runtime.
- Produces deployed review route and certification logs; production remains `meso-v2`.

- [ ] **Step 1: Write failing deploy-gate contract**

```python
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_v24_deploy_gate_pins_sha_and_protects_production():
    wf = (ROOT / ".github/workflows/mesoai-deploy-v24-chatterbox.yml").read_text(encoding="utf-8")
    assert "MESOAI_SHA" in wf
    assert "ACTIVE_PROFILE=meso-v2" in wf
    assert "V24_PROMOTED=0" in wf
    assert "voice-lab-v24" in wf
```

- [ ] **Step 2: Run and verify RED**

Run: `python -m pytest backend/tests/test_meso_v24_deploy_gate.py -v`

Expected: FAIL because workflow does not exist.

- [ ] **Step 3: Implement exact-SHA deployment/certification**

Workflow must:
- accept or embed one exact MesoAI commit SHA, never floating `main`
- fence to MASTER-PC LocalSystem/admin
- run Chatterbox preflight and both language smoke tests
- verify private benchmark exists
- deploy through `deploy_to_xampp.ps1`
- verify deployed file hashes against exact source
- authenticate locally and test v2.4 `status`, `anchor`, one Arabic candidate, one English candidate, and `reject` vote
- verify public Voice Lab route returns v2.4 assets
- verify no private paths/source filenames appear in HTTP responses
- verify production TTS reports `meso-v2`
- verify `/data/voice/profiles/khalil/meso-v2.4/profile.json` does not exist

Required final banners:
```text
MESO_V24_DEPLOY=PASS
MESO_V24_PUBLIC_REVIEW=PASS
MESO_V24_PRIVACY=PASS
MESO_V24_PRODUCTION_LOCK=PASS ACTIVE_PROFILE=meso-v2 V24_PROMOTED=0
```

- [ ] **Step 4: Run KDT focused/full tests**

Run:
```text
python -m pytest backend/tests/test_meso_v24_deploy_gate.py backend/tests/test_meso_v24_chatterbox_contract.py backend/tests/test_meso_v24_private_benchmark_contract.py -v
```

Expected: PASS.

- [ ] **Step 5: Commit and execute exact deployment**

```bash
git add .github/workflows/mesoai-deploy-v24-chatterbox.yml backend/tests/test_meso_v24_deploy_gate.py
git commit -m "deploy: certify Meso Voice v2.4 Chatterbox review"
```

After MesoAI and KDT pull requests are green and merged, trigger the private benchmark build, Chatterbox preflight, and exact-SHA deployment in that order. Do not proceed to any production promotion.

## Self-review

- Spec coverage: privacy boundary, Meso-only speaker attribution, real anchors, Arabic/English lanes, blinded A-E candidates, rejection, production lock, Chatterbox-first/Fish-next architecture are all mapped to tasks.
- Placeholder scan: no TBD/TODO/implement-later placeholders remain.
- Type/interface consistency: v2.4 uses lane IDs `ar-casual`, `ar-warm`, `en-casual`; candidate IDs are A-E; helper input/output contracts are consistent across Tasks 3-5; production lock is consistently `meso-v2` / `V24_PROMOTED=0`.
