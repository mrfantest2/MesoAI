#!/usr/bin/env bash
set -euo pipefail

# Starts a temporary authenticated Fish Audio S2 Pro API for MesoAI.
# Private reference material must already be staged under PRIVATE_ROOT by the
# trusted MASTER-PC orchestration bridge. No private input is downloaded here.

FISH_COMMIT="${FISH_COMMIT:-e5e292632cb11e7a27b2b7487f58f612bc101e13}"
HF_MODEL="${HF_MODEL:-fishaudio/s2-pro}"
WORKDIR="${WORKDIR:-/workspace/fish-s2-live}"
PRIVATE_ROOT="${PRIVATE_ROOT:-/workspace/meso-live-private}"
REFERENCE_AUDIO="${REFERENCE_AUDIO:-$PRIVATE_ROOT/reference.wav}"
REFERENCE_TEXT_FILE="${REFERENCE_TEXT_FILE:-$PRIVATE_ROOT/reference.txt}"
API_KEY_FILE="${API_KEY_FILE:-$PRIVATE_ROOT/api-key.txt}"
REFERENCE_ID="${REFERENCE_ID:-meso}"
LISTEN="${LISTEN:-0.0.0.0:8080}"
UV_EXTRA="${UV_EXTRA:-cu126}"
MIN_VRAM_MIB="${MIN_VRAM_MIB:-23000}"
MIN_FREE_GIB="${MIN_FREE_GIB:-30}"

if [[ "${MESO_FISH_LICENSE_ACCEPTED:-0}" != "1" ]]; then
  echo "Fish Audio Research License acceptance is required." >&2
  exit 10
fi
if [[ "$(uname -s 2>/dev/null || true)" != "Linux" ]]; then
  echo "Fish S2 live API requires Linux." >&2
  exit 11
fi
if [[ ! "$REFERENCE_ID" =~ ^[A-Za-z0-9_-]{1,64}$ ]]; then
  echo "Invalid reference ID." >&2
  exit 12
fi
for cmd in git python3 nvidia-smi sha256sum df curl; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing command: $cmd" >&2; exit 13; }
done
for f in "$REFERENCE_AUDIO" "$REFERENCE_TEXT_FILE" "$API_KEY_FILE"; do
  [[ -f "$f" ]] || { echo "Missing required private live-service input." >&2; exit 14; }
done
chmod 600 "$API_KEY_FILE" || true
API_KEY="$(tr -d '\r\n' < "$API_KEY_FILE")"
if [[ ${#API_KEY} -lt 24 ]]; then
  echo "Invalid private API key." >&2
  exit 15
fi
REFERENCE_TEXT="$(tr '\n' ' ' < "$REFERENCE_TEXT_FILE" | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//')"
[[ -n "$REFERENCE_TEXT" && ${#REFERENCE_TEXT} -le 4000 ]] || { echo "Invalid reference transcript." >&2; exit 16; }

VRAM_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]')"
GPU_NAME="$(nvidia-smi --query-gpu=name --format=csv,noheader | head -n1 | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
if [[ -z "$VRAM_MIB" || ! "$VRAM_MIB" =~ ^[0-9]+$ || "$VRAM_MIB" -lt "$MIN_VRAM_MIB" ]]; then
  echo "Fish S2 live API requires >=${MIN_VRAM_MIB} MiB VRAM." >&2
  exit 17
fi

mkdir -p "$(dirname "$WORKDIR")" "$PRIVATE_ROOT"
FREE_KIB="$(df -Pk "$(dirname "$WORKDIR")" | awk 'NR==2 {print $4}')"
MIN_FREE_KIB="$((MIN_FREE_GIB * 1024 * 1024))"
if [[ -z "$FREE_KIB" || ! "$FREE_KIB" =~ ^[0-9]+$ || "$FREE_KIB" -lt "$MIN_FREE_KIB" ]]; then
  echo "Insufficient disk for Fish S2 live service." >&2
  exit 18
fi

missing_packages=()
command -v ffmpeg >/dev/null 2>&1 || missing_packages+=(ffmpeg)
[[ -f /usr/include/portaudio.h ]] || missing_packages+=(portaudio19-dev)
[[ -f /usr/include/sox.h ]] || missing_packages+=(libsox-dev)
if (( ${#missing_packages[@]} > 0 )); then
  if [[ "$(id -u)" == "0" && -x "$(command -v apt-get || true)" ]]; then
    echo "Installing missing public Fish runtime dependencies: ${missing_packages[*]}"
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing_packages[@]}"
  else
    echo "Missing Fish runtime dependencies." >&2
    exit 19
  fi
fi

mkdir -p "$WORKDIR"
cd "$WORKDIR"
if [[ ! -d fish-speech/.git ]]; then
  git clone --filter=blob:none https://github.com/fishaudio/fish-speech.git fish-speech
fi
cd fish-speech
git fetch --depth 1 origin "$FISH_COMMIT"
git checkout --detach "$FISH_COMMIT"
ACTUAL_COMMIT="$(git rev-parse HEAD)"
[[ "$ACTUAL_COMMIT" == "$FISH_COMMIT" ]] || { echo "Fish source pin mismatch." >&2; exit 20; }

if ! command -v uv >/dev/null 2>&1; then
  python3 -m pip install --upgrade uv
fi
uv sync --python 3.12 --extra "$UV_EXTRA"
mkdir -p checkpoints/s2-pro
uv run hf download "$HF_MODEL" --local-dir checkpoints/s2-pro
[[ -s checkpoints/s2-pro/codec.pth ]] || { echo "Fish decoder checkpoint missing." >&2; exit 21; }

# The pinned Fish ReferenceLoader resolves references/<id> and expects each
# audio file to have a same-name .lab transcript. Keep those bytes physically
# under PRIVATE_ROOT and expose only a symlink from the public/model workspace.
PRIVATE_REF_DIR="$PRIVATE_ROOT/reference/$REFERENCE_ID"
mkdir -p "$PRIVATE_REF_DIR"
ln -sfn "$REFERENCE_AUDIO" "$PRIVATE_REF_DIR/sample.wav"
ln -sfn "$REFERENCE_TEXT_FILE" "$PRIVATE_REF_DIR/sample.lab"
mkdir -p references
rm -rf -- "references/$REFERENCE_ID"
ln -s "$PRIVATE_REF_DIR" "references/$REFERENCE_ID"

SERVER_LOG="$PRIVATE_ROOT/fish-api.log"
PID_FILE="$PRIVATE_ROOT/fish-api.pid"
if [[ -f "$PID_FILE" ]]; then
  old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ "$old_pid" =~ ^[0-9]+$ ]] && kill -0 "$old_pid" 2>/dev/null; then
    kill "$old_pid" || true
    sleep 2
  fi
fi

# Deliberately omit --compile for the first live preflight. The batch path has
# already proven this pinned model on the same GPU class; reliability is more
# important than compilation latency during the initial live integration.
nohup uv run python tools/api_server.py \
  --llama-checkpoint-path checkpoints/s2-pro \
  --decoder-checkpoint-path checkpoints/s2-pro/codec.pth \
  --listen "$LISTEN" \
  --api-key "$API_KEY" \
  --workers 1 \
  >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
printf '%s\n' "$SERVER_PID" > "$PID_FILE"
chmod 600 "$PID_FILE" "$SERVER_LOG" || true

ready=0
for _ in $(seq 1 120); do
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "Fish API process exited before health check passed." >&2
    exit 22
  fi
  if curl --fail --silent --show-error --max-time 5 \
      -H "Authorization: Bearer $API_KEY" \
      "http://127.0.0.1:8080/v1/health" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
[[ "$ready" == "1" ]] || { echo "Fish API health check timed out." >&2; exit 23; }

echo "MESO_FISH_LIVE_HEALTH=true"
echo "MESO_FISH_LIVE_GPU=$GPU_NAME"
echo "MESO_FISH_LIVE_VRAM_MIB=$VRAM_MIB"
echo "MESO_FISH_LIVE_REFERENCE_ID=$REFERENCE_ID"
echo "MESO_FISH_LIVE_FISH_COMMIT=$ACTUAL_COMMIT"
echo "MESO_FISH_LIVE_PRIVATE_REFERENCE_STAGED=true"
