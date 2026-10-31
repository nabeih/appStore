"""Plan and install a pinned CPU-only runtime in the project's private storage.

No global installation, torch, remote inference, executable model format, or GPU
packages are used. The size gate includes the model and every required wheel.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import urllib.request
import venv

from pip._vendor.packaging.tags import sys_tags
from pip._vendor.packaging.utils import parse_wheel_filename

ROOT = Path(__file__).resolve().parents[3]
PRIVATE = ROOT / "storage/app/private/visual-search"
REVISION = "d15189d7028b43f1d3e65039190477f6af591c2a"
MODEL_HASH = "583fd1110a514667812fee7d684952aaf82a99b959760c8d7dca7e0ab9839299"
MODEL_VERSION = "clip-b32-int8-583fd111-clip-preprocess-v1"
MODEL_BYTES = 89117001
LIMIT = 300_000_000


def json_url(url):
    with urllib.request.urlopen(url, timeout=45) as response:
        return json.load(response)


def plan():
    priorities = {tag: index for index, tag in enumerate(sys_tags())}
    packages = []
    for line in Path(__file__).with_name("requirements.txt").read_text().splitlines():
        if not line.strip() or ("win32" in line and sys.platform != "win32"):
            continue
        name, version = line.split(";")[0].strip().split("==")
        metadata = json_url(f"https://pypi.org/pypi/{name}/{version}/json")
        if metadata.get("vulnerabilities"):
            raise RuntimeError("Review a vulnerable dependency before installing: " + name)
        candidates = []
        for item in metadata["urls"]:
            if not item["filename"].endswith(".whl") or item.get("yanked"):
                continue
            tags = parse_wheel_filename(item["filename"])[3]
            ranks = [priorities[tag] for tag in tags if tag in priorities]
            if ranks:
                candidates.append((min(ranks), item))
        if not candidates:
            raise RuntimeError("No supported binary wheel: " + name)
        item = min(candidates, key=lambda entry: entry[0])[1]
        packages.append({"name": name, "version": version, "url": item["url"],
                         "filename": item["filename"], "bytes": item["size"],
                         "sha256": item["digests"]["sha256"]})
    total = MODEL_BYTES + sum(item["bytes"] for item in packages) + 100_000
    if total > LIMIT:
        raise RuntimeError(f"Explicit download approval required: {total} bytes exceeds {LIMIT}.")
    return {"model": MODEL_VERSION, "revision": REVISION, "packages": packages,
            "model_bytes": MODEL_BYTES, "total_expected_bytes": total}


def download(url, destination, expected_bytes, expected_hash):
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.is_file() and destination.stat().st_size == expected_bytes:
        with destination.open("rb") as existing:
            if hashlib.file_digest(existing, "sha256").hexdigest() == expected_hash:
                return
    partial = destination.with_name(destination.name + ".part")
    digest, count = hashlib.sha256(), 0
    with urllib.request.urlopen(url, timeout=90) as response, partial.open("wb") as output:
        while chunk := response.read(256 * 1024):
            count += len(chunk)
            if count > expected_bytes:
                raise RuntimeError("Download exceeds its reviewed size.")
            digest.update(chunk)
            output.write(chunk)
    if count != expected_bytes or digest.hexdigest() != expected_hash:
        raise RuntimeError("Downloaded artifact failed its integrity check.")
    partial.replace(destination)


def install(reviewed):
    PRIVATE.mkdir(parents=True, exist_ok=True)
    wheels = PRIVATE / "wheels"
    paths = []
    for item in reviewed["packages"]:
        path = wheels / item["filename"]
        download(item["url"], path, item["bytes"], item["sha256"])
        paths.append(str(path))
    model = PRIVATE / "model/vision_model_quantized.onnx"
    download(f"https://huggingface.co/Xenova/clip-vit-base-patch32/resolve/{REVISION}/onnx/vision_model_quantized.onnx",
             model, MODEL_BYTES, MODEL_HASH)
    environment = PRIVATE / "venv"
    python = environment / ("Scripts/python.exe" if os.name == "nt" else "bin/python")
    if not python.is_file():
        venv.EnvBuilder(with_pip=True).create(environment)
    subprocess.run([str(python), "-m", "pip", "install", "--no-index", "--no-deps",
                    "--disable-pip-version-check", *paths], check=True)
    subprocess.run([str(python), "-m", "pip", "check"], check=True)
    token = PRIVATE / "runtime-token"
    if not token.exists():
        token.write_text(secrets.token_hex(32), encoding="ascii")
        if os.name != "nt":
            token.chmod(0o600)
    reviewed["model_sha256"] = MODEL_HASH
    reviewed["model_license"] = "MIT; original OpenAI CLIP license"
    reviewed["model_source"] = f"https://huggingface.co/Xenova/clip-vit-base-patch32/tree/{REVISION}"
    license_url = "https://raw.githubusercontent.com/openai/CLIP/main/LICENSE"
    with urllib.request.urlopen(license_url, timeout=30) as response:
        license_text = response.read(10_001)
    if len(license_text) > 10_000 or not license_text.startswith(b"MIT License"):
        raise RuntimeError("Review the upstream model license before distribution.")
    (PRIVATE / "model/LICENSE-CLIP.txt").write_bytes(license_text)
    reviewed["model_license_source"] = license_url
    (PRIVATE / "install-manifest.json").write_text(json.dumps(reviewed, indent=2), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--install", action="store_true")
    args = parser.parse_args()
    reviewed = plan()
    print(json.dumps(reviewed), flush=True)
    if args.install:
        install(reviewed)
