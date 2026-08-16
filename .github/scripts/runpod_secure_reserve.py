#!/usr/bin/env python3
"""Reserve one compliant RunPod Secure Cloud GPU without transferring private data."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

GRAPHQL_URL = "https://api.runpod.io/graphql"
REST_PODS_URL = "https://rest.runpod.io/v1/pods"
POD_NAME_PREFIX = "meso-secure-preflight"
DEFAULT_IMAGE = "runpod/pytorch:2.1.0-py3.10-cuda11.8.0-devel-ubuntu22.04"


def fail(message: str, code: int = 1) -> "NoReturn":
    print(f"::error::{message}", file=sys.stderr)
    raise SystemExit(code)


def request_json(
    url: str,
    *,
    method: str = "GET",
    headers: dict[str, str] | None = None,
    payload: dict[str, Any] | None = None,
    timeout: int = 45,
) -> Any:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            raw = response.read()
            if not raw:
                return None
            return json.loads(raw.decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        # Never include the request URL because the GraphQL API key is a query parameter.
        raise RuntimeError(f"HTTP {exc.code}: {body[:1200]}") from None
    except urllib.error.URLError as exc:
        raise RuntimeError(f"network error: {exc.reason}") from None


def delete_pod(api_key: str, pod_id: str) -> None:
    req = urllib.request.Request(
        f"{REST_PODS_URL}/{urllib.parse.quote(pod_id, safe='')}",
        headers={"Authorization": f"Bearer {api_key}"},
        method="DELETE",
    )
    try:
        with urllib.request.urlopen(req, timeout=30):
            print(f"Rolled back non-compliant Pod {pod_id}.")
    except Exception:
        print(
            f"::warning::Failed to roll back Pod {pod_id}; terminate it manually immediately.",
            file=sys.stderr,
        )


def graphql(api_key: str, query: str) -> dict[str, Any]:
    url = f"{GRAPHQL_URL}?api_key={urllib.parse.quote(api_key, safe='')}"
    result = request_json(
        url,
        method="POST",
        headers={"Content-Type": "application/json"},
        payload={"query": query},
    )
    if not isinstance(result, dict):
        fail("RunPod GraphQL returned an invalid response.", 10)
    if result.get("errors"):
        fail("RunPod GraphQL rejected the GPU inventory query.", 11)
    return result


def live_gpu_inventory(api_key: str) -> list[dict[str, Any]]:
    query = """
    query {
      gpuTypes {
        id
        displayName
        memoryInGb
        secureCloud
        lowestPrice(input: { gpuCount: 1, secureCloud: true }) {
          stockStatus
          uninterruptablePrice
          availableGpuCounts
        }
      }
    }
    """
    result = graphql(api_key, query)
    rows = result.get("data", {}).get("gpuTypes", [])
    if not isinstance(rows, list):
        fail("RunPod GPU inventory was missing.", 12)
    return rows


def as_float(value: Any) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def eligible_gpus(
    rows: list[dict[str, Any]], min_vram_gb: int, max_hourly_usd: float
) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    for row in rows:
        gpu_id = str(row.get("id") or "")
        if not gpu_id.startswith("NVIDIA "):
            continue
        if row.get("secureCloud") is not True:
            continue

        try:
            memory_gb = int(row.get("memoryInGb"))
        except (TypeError, ValueError):
            continue
        if memory_gb < min_vram_gb:
            continue

        price_info = row.get("lowestPrice") or {}
        price = as_float(price_info.get("uninterruptablePrice"))
        stock = str(price_info.get("stockStatus") or "None")
        counts = price_info.get("availableGpuCounts") or []
        if price is None or price > max_hourly_usd:
            continue
        if stock.lower() == "none" or 1 not in counts:
            continue

        candidates.append(
            {
                "id": gpu_id,
                "displayName": row.get("displayName") or gpu_id,
                "memoryInGb": memory_gb,
                "price": price,
                "stock": stock,
            }
        )

    # Honor the repository plan's RTX A5000 preference when compliant; otherwise cheapest.
    candidates.sort(
        key=lambda item: (
            0 if item["id"] == "NVIDIA RTX A5000" else 1,
            item["price"],
            -item["memoryInGb"],
            item["id"],
        )
    )
    return candidates


def list_existing_pods(api_key: str) -> list[dict[str, Any]]:
    try:
        result = request_json(
            f"{REST_PODS_URL}?includeMachine=true",
            headers={"Authorization": f"Bearer {api_key}"},
        )
    except RuntimeError as exc:
        print(f"::warning::Could not inspect existing Pods: {exc}", file=sys.stderr)
        return []
    return result if isinstance(result, list) else []


def matching_existing_pod(
    api_key: str,
    inventory: list[dict[str, Any]],
    min_vram_gb: int,
    max_hourly_usd: float,
) -> dict[str, Any] | None:
    memory_by_id = {}
    secure_by_id = {}
    for row in inventory:
        gpu_id = str(row.get("id") or "")
        try:
            memory_by_id[gpu_id] = int(row.get("memoryInGb"))
        except (TypeError, ValueError):
            pass
        secure_by_id[gpu_id] = row.get("secureCloud") is True

    for pod in list_existing_pods(api_key):
        if not str(pod.get("name") or "").startswith(POD_NAME_PREFIX):
            continue
        if str(pod.get("desiredStatus") or "").upper() != "RUNNING":
            continue
        gpu = pod.get("gpu") or {}
        gpu_id = str(gpu.get("id") or (pod.get("machine") or {}).get("gpuTypeId") or "")
        machine = pod.get("machine") or {}
        price = as_float(pod.get("costPerHr"))
        if price is None:
            price = as_float(pod.get("adjustedCostPerHr"))
        if (
            secure_by_id.get(gpu_id)
            and machine.get("secureCloud") is True
            and memory_by_id.get(gpu_id, 0) >= min_vram_gb
            and price is not None
            and price <= max_hourly_usd
        ):
            return {
                "id": pod.get("id"),
                "gpu_id": gpu_id,
                "gpu_name": gpu.get("displayName") or gpu_id,
                "memory_gb": memory_by_id[gpu_id],
                "price": price,
                "reused": True,
            }
    return None


def create_pod(api_key: str, candidate: dict[str, Any], run_id: str) -> dict[str, Any]:
    payload = {
        "cloudType": "SECURE",
        "computeType": "GPU",
        "containerDiskInGb": 50,
        "gpuCount": 1,
        "gpuTypeIds": [candidate["id"]],
        "gpuTypePriority": "custom",
        "imageName": DEFAULT_IMAGE,
        "interruptible": False,
        "locked": False,
        "minRAMPerGPU": 8,
        "minVCPUPerGPU": 2,
        "name": f"{POD_NAME_PREFIX}-{run_id}",
        "ports": ["22/tcp"],
        "supportPublicIp": True,
        "volumeInGb": 0,
    }
    result = request_json(
        REST_PODS_URL,
        method="POST",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        payload=payload,
        timeout=60,
    )
    if not isinstance(result, dict) or not result.get("id"):
        raise RuntimeError("Pod creation returned no Pod ID.")
    return result


def verify_created(
    api_key: str,
    pod: dict[str, Any],
    candidate: dict[str, Any],
    max_hourly_usd: float,
) -> dict[str, Any]:
    pod_id = str(pod["id"])
    machine = pod.get("machine") or {}
    gpu = pod.get("gpu") or {}
    gpu_id = str(gpu.get("id") or machine.get("gpuTypeId") or candidate["id"])
    price = as_float(pod.get("costPerHr"))
    if price is None:
        price = as_float(pod.get("adjustedCostPerHr"))

    violations: list[str] = []
    if machine.get("secureCloud") is not True:
        violations.append("provider response did not confirm Secure Cloud")
    if gpu_id != candidate["id"]:
        violations.append(f"GPU mismatch ({gpu_id!r} != {candidate['id']!r})")
    if candidate["memoryInGb"] < int(os.environ["MIN_VRAM_GB"]):
        violations.append("VRAM below requested minimum")
    if price is None:
        violations.append("provider response did not include hourly cost")
    elif price > max_hourly_usd:
        violations.append(f"hourly cost ${price:.4f} exceeds ${max_hourly_usd:.2f}")

    if violations:
        delete_pod(api_key, pod_id)
        raise RuntimeError("; ".join(violations))

    return {
        "id": pod_id,
        "gpu_id": gpu_id,
        "gpu_name": gpu.get("displayName") or candidate["displayName"],
        "memory_gb": candidate["memoryInGb"],
        "price": price,
        "reused": False,
    }


def emit_result(result: dict[str, Any]) -> None:
    lines = {
        "pod_id": result["id"],
        "gpu_id": result["gpu_id"],
        "gpu_name": result["gpu_name"],
        "vram_gb": result["memory_gb"],
        "hourly_usd": f'{result["price"]:.4f}',
        "reused_existing": str(bool(result.get("reused"))).lower(),
    }
    output_path = os.environ.get("GITHUB_OUTPUT")
    if output_path:
        with open(output_path, "a", encoding="utf-8") as handle:
            for key, value in lines.items():
                handle.write(f"{key}={value}\n")
    print(
        "RESERVATION_OK "
        f"pod={result['id']} gpu={result['gpu_name']} "
        f"vram={result['memory_gb']}GB price=${result['price']:.4f}/h "
        f"reused={bool(result.get('reused'))}"
    )


def main() -> None:
    api_key = os.environ.get("RUNPOD_API_KEY", "").strip()
    if not api_key:
        fail("RUNPOD_API_KEY secret is not configured.", 2)

    min_vram_gb = int(os.environ.get("MIN_VRAM_GB", "24"))
    max_hourly_usd = float(os.environ.get("MAX_HOURLY_USD", "0.50"))
    run_id = os.environ.get("GITHUB_RUN_ID", "manual")

    inventory = live_gpu_inventory(api_key)

    existing = matching_existing_pod(api_key, inventory, min_vram_gb, max_hourly_usd)
    if existing:
        print("Found an existing compliant MesoAI reservation; not creating a duplicate.")
        emit_result(existing)
        return

    candidates = eligible_gpus(inventory, min_vram_gb, max_hourly_usd)
    if not candidates:
        fail(
            f"No Secure Cloud NVIDIA GPU with >= {min_vram_gb} GB VRAM "
            f"is currently available at <= ${max_hourly_usd:.2f}/hour.",
            20,
        )

    print("Eligible Secure Cloud candidates:")
    for item in candidates:
        print(
            f"- {item['displayName']} ({item['memoryInGb']} GB) "
            f"${item['price']:.4f}/h stock={item['stock']}"
        )

    last_error = ""
    for candidate in candidates:
        try:
            print(f"Attempting reservation: {candidate['displayName']}")
            pod = create_pod(api_key, candidate, run_id)
            result = verify_created(api_key, pod, candidate, max_hourly_usd)
            emit_result(result)
            return
        except RuntimeError as exc:
            last_error = str(exc)
            print(
                f"::warning::{candidate['displayName']} reservation failed: {last_error}",
                file=sys.stderr,
            )

    fail(f"All compliant Secure Cloud reservation attempts failed. Last error: {last_error}", 21)


if __name__ == "__main__":
    main()
