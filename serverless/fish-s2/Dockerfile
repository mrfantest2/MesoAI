FROM nvidia/cuda:12.6.3-cudnn-runtime-ubuntu24.04

ARG FISH_COMMIT=e5e292632cb11e7a27b2b7487f58f612bc101e13
ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    MESO_MODEL_ID=fishaudio/s2-pro

LABEL org.opencontainers.image.source="https://github.com/mrfantest2/MesoAI" \
      org.opencontainers.image.description="MesoAI private Fish S2 Pro RunPod Serverless worker"

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      ffmpeg \
      git \
      libsox-dev \
      portaudio19-dev \
      python3 \
      python3-pip \
      python3-venv \
      build-essential \
      pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN python3 -m pip install --break-system-packages --no-cache-dir uv

WORKDIR /app
RUN git clone --filter=blob:none https://github.com/fishaudio/fish-speech.git fish-speech \
    && cd fish-speech \
    && git fetch --depth 1 origin "$FISH_COMMIT" \
    && git checkout --detach "$FISH_COMMIT" \
    && test "$(git rev-parse HEAD)" = "$FISH_COMMIT"

WORKDIR /app/fish-speech
RUN uv sync --python 3.12 --extra cu126 \
    && uv pip install --python .venv/bin/python --no-cache \
       "runpod>=1.7,<2" requests ormsgpack \
    && mkdir -p checkpoints/s2-pro \
    && uv run hf download fishaudio/s2-pro --local-dir checkpoints/s2-pro \
    && test -s checkpoints/s2-pro/codec.pth

# All model/network access is disabled at worker runtime. The public S2-Pro
# model has already been baked into the immutable image above. Private voice
# references are never copied into this image and arrive only per job.
ENV HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1 \
    HF_HOME=/root/.cache/huggingface

WORKDIR /app/meso-serverless
COPY handler.py /app/meso-serverless/handler.py

CMD ["/app/fish-speech/.venv/bin/python", "-u", "/app/meso-serverless/handler.py"]
