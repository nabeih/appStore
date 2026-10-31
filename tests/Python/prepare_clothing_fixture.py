"""Prepare opt-in integration fixtures from licensed retailer garment images.

Downloads only the 4.4 MB Fashion-MNIST test split, never training weights or
customer images. Outputs remain in private test storage, outside the catalogue.
"""
import gzip
import hashlib
import json
from pathlib import Path
import struct
import urllib.request

from PIL import Image, ImageEnhance, ImageOps

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / "storage/app/private/visual-search"
FIXTURES = ROOT / "storage/framework/cache/visual-search/clothing-fixtures"
REVISION = "b2617bb6d3ffa2e429640350f613e3291e10b141"
BASE = f"https://raw.githubusercontent.com/zalandoresearch/fashion-mnist/{REVISION}"
FILES = [("t10k-images-idx3-ubyte.gz", 4422102, "bef4ecab320f06d8554ea6380940ec79"),
         ("t10k-labels-idx1-ubyte.gz", 5148, "bb300cfdad3c16e7a12a480ee83cd310")]


def main():
    installation = json.loads((PRIVATE / "install-manifest.json").read_text(encoding="utf-8"))
    total = installation["total_expected_bytes"] + sum(size for _, size, _ in FILES) + 10_000
    if total > 300_000_000:
        raise RuntimeError("Additional download approval is required.")
    destination = PRIVATE / "fashion-mnist"
    destination.mkdir(parents=True, exist_ok=True)
    artifacts = []
    for filename, size, md5 in FILES:
        target = destination / filename
        if not target.is_file():
            with urllib.request.urlopen(BASE + "/data/fashion/" + filename, timeout=60) as response:
                data = response.read(size + 1)
            if len(data) != size or hashlib.md5(data, usedforsecurity=False).hexdigest() != md5:
                raise RuntimeError("Dataset transfer differs from the publisher's checksums.")
            target.write_bytes(data)
        data = target.read_bytes()
        if len(data) != size or hashlib.md5(data, usedforsecurity=False).hexdigest() != md5:
            raise RuntimeError("Local fixture archive is invalid.")
        artifacts.append({"filename": filename, "bytes": size, "sha256": hashlib.sha256(data).hexdigest()})
    with gzip.open(destination / FILES[0][0], "rb") as source:
        images = source.read(7_840_017)
    with gzip.open(destination / FILES[1][0], "rb") as source:
        labels = source.read(10_009)
    if len(images) != 7_840_016 or struct.unpack(">IIII", images[:16]) != (2051, 10000, 28, 28):
        raise RuntimeError("Invalid image fixture layout.")
    if len(labels) != 10_008 or struct.unpack(">II", labels[:8]) != (2049, 10000):
        raise RuntimeError("Invalid label fixture layout.")
    FIXTURES.mkdir(parents=True, exist_ok=True)
    names = ["تيشيرت", "بنطال", "كنزة", "فستان", "معطف", "صندل", "قميص", "حذاء رياضي", "حقيبة", "حذاء قصير"]
    picked = {}
    for index, label in enumerate(labels[8:]):
        if label in picked:
            continue
        image = ImageOps.invert(Image.frombytes("L", (28, 28), images[16 + index * 784:16 + (index + 1) * 784]))
        image = image.convert("RGB").resize((224, 224), Image.Resampling.BICUBIC)
        filename = f"garment-{label}.png"
        image.save(FIXTURES / filename)
        picked[label] = {"label": label, "sample_index": index, "name": names[label], "image_path": filename}
        if label == 0:
            query = image.rotate(6, Image.Resampling.BICUBIC, fillcolor="white")
            query = ImageEnhance.Brightness(query).enhance(0.95)
            query.save(FIXTURES / "query.png")
        if len(picked) == 10:
            break
    with urllib.request.urlopen(BASE + "/LICENSE", timeout=30) as response:
        license_text = response.read(10_001)
    if len(license_text) > 10_000:
        raise RuntimeError("Unexpected license document size.")
    (destination / "LICENSE").write_bytes(license_text)
    report = {"source": "Zalando Fashion-MNIST test split", "revision": REVISION, "license": "MIT",
              "source_url": BASE, "additional_download_bytes": sum(size for _, size, _ in FILES),
              "all_downloads_planned_bytes": total, "artifacts": artifacts,
              "images": [picked[index] for index in sorted(picked)],
              "query": "query.png", "query_source_label": 0, "query_transform": "6-degree rotation and brightness 0.95",
              "original_resolution": "28x28 grayscale; not smartphone photographs"}
    (ROOT / "storage/app/reports/visual-search-clothing-fixtures.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"fixtures": len(picked), "download_bytes": report["additional_download_bytes"], "total_download_budget": total}))


if __name__ == "__main__":
    main()
