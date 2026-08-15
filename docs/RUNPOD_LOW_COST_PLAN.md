# RunPod low-cost voice evaluation plan

Date: 2026-08-15

This document records the intended temporary GPU configuration for Fish Audio S2 evaluation. It contains no credentials or private voice data.

## Cost target

Use one temporary RunPod Pod for both authorized projects, sequentially:

1. MesoAI evaluation
2. Khalil AI evaluation
3. destroy the Pod immediately after both output sets are downloaded and verified

The public Fish source/model/runtime cache may be reused between the two jobs. Project-private reference audio, transcripts, target text, and generated outputs must stay in separate project roots and each private remote root must be deleted after its verified download.

## Provider configuration

Preferred configuration:

- provider: RunPod
- product: Pods
- cloud: Secure Cloud
- billing: on-demand / per-second
- GPU: NVIDIA RTX A5000, 24 GB VRAM
- target price: approximately USD 0.27/hour, subject to live RunPod availability/pricing
- GPU count: 1
- template: official RunPod PyTorch template
- container disk: temporary only; size sufficient for Fish S2 runtime/model and both short evaluations
- persistent/network volume: none
- public SSH: enabled through the official template
- auto-pay: disabled for this experiment
- savings plan: none

Secure Cloud is preferred despite Community Cloud being cheaper because the workloads contain sensitive private voice references.

## Cost-efficiency rules

- Run the zero-data GPU preflight before transferring any private file.
- Download/install Fish S2 once, then reuse only that public shared cache for the second project.
- Never share a private project root between MesoAI and Khalil AI.
- Do not create a network volume merely for caching; the experiment is too short for that to be useful.
- Do not leave the Pod stopped with persistent storage.
- Do not use Deploy When Available without a narrow active-time window, because billing begins automatically when capacity appears.
- Destroy the Pod after both projects are complete and outputs are verified locally.

## Expected spend

At roughly USD 0.27/hour, even a three-hour combined MesoAI + Khalil AI session would be about USD 0.81 in GPU compute, plus a very small temporary-storage charge. The account may hold a larger prepaid credit balance; that is not the expected job cost.

## Mandatory gates before private transfer

1. Explicit Fish Audio Research License acceptance record exists locally for the intended allowed use.
2. RunPod account is funded.
3. Dedicated SSH key is added to the account.
4. Secure Cloud Pod is launched.
5. Provider SSH host-key fingerprint is obtained independently and pinned.
6. `fish_s2_remote_preflight.ps1` confirms Linux, GPU identity, >=23,000 MiB VRAM, disk, Python, and Git without transferring private data.
7. Only then may `fish_s2_run.ps1` perform the project-private handoff.

## Isolation layout on the temporary Pod

Public reusable cache:

`/workspace/fish-s2-shared`

MesoAI private job root:

`/workspace/meso-private`

Khalil AI private job root:

`/workspace/khalil-private`

The two private roots must never overlap with each other or with the shared public cache.
