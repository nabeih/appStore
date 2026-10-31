"""Loopback-only, authenticated, single-model CPU image embedding runtime."""
import argparse
from dataclasses import dataclass, field
import hashlib
import heapq
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import hmac
import itertools
import json
import math
from pathlib import Path
import threading
import time

VERSION = "clip-b32-int8-583fd111-clip-preprocess-v1"
MODEL_SHA256 = "583fd1110a514667812fee7d684952aaf82a99b959760c8d7dca7e0ab9839299"
ROOT = Path(__file__).resolve().parents[3]
PRIVATE = ROOT / "storage/app/private/visual-search"


class RuntimeBusy(Exception):
    pass


@dataclass
class Work:
    path: Path
    purpose: str
    deadline: float
    done: threading.Event = field(default_factory=threading.Event)
    cancelled: bool = False
    result: dict | None = None
    error: Exception | None = None


class PriorityScheduler:
    """One inference at a time; queued searches precede queued indexing work.

    An already running inference is allowed to finish. Queue admission and
    deadlines are bounded independently of the HTTP connection thread count.
    """
    def __init__(self, engine):
        self.engine = engine
        self.condition = threading.Condition()
        self.queue = []
        self.sequence = itertools.count()
        self.pending = {"search": 0, "index": 0}
        self.active = None
        self.closed = False
        self.completed = 0
        self.worker = threading.Thread(target=self._run, daemon=True, name="image-inference")
        self.worker.start()

    def submit(self, path, purpose="search", timeout=8):
        task = Work(path, purpose, time.monotonic() + timeout)
        with self.condition:
            maximum = 4 if purpose == "search" else 1
            if self.closed or self.pending[purpose] >= maximum:
                raise RuntimeBusy("Runtime capacity reached")
            self.pending[purpose] += 1
            heapq.heappush(self.queue, (0 if purpose == "search" else 1, next(self.sequence), task))
            self.condition.notify()
        if not task.done.wait(timeout):
            task.cancelled = True
            raise RuntimeBusy("Runtime deadline reached")
        if task.error:
            raise task.error
        return task.result

    def _run(self):
        while True:
            with self.condition:
                self.condition.wait_for(lambda: self.queue or self.closed)
                if self.closed:
                    return
                _, _, task = heapq.heappop(self.queue)
                self.pending[task.purpose] -= 1
                self.active = task.purpose
            try:
                if task.cancelled or time.monotonic() >= task.deadline:
                    raise RuntimeBusy("Expired queued task")
                start = time.monotonic()
                cpu_start = time.process_time()
                vector = self.engine.embed(task.path)
                task.result = {"embedding": vector, "version": VERSION,
                               "inference_ms": round((time.monotonic() - start) * 1000, 2),
                               "cpu_ms": round((time.process_time() - cpu_start) * 1000, 2)}
                self.completed += 1
            except Exception as error:
                task.error = error
            finally:
                with self.condition:
                    self.active = None
                task.done.set()

    def status(self):
        with self.condition:
            return {"queued": dict(self.pending), "active": self.active, "completed": self.completed}

    def close(self):
        with self.condition:
            self.closed = True
            for _, _, task in self.queue:
                task.cancelled = True
                task.error = RuntimeBusy("Runtime stopping")
                task.done.set()
            self.queue.clear()
            self.condition.notify_all()
        self.worker.join(timeout=5)


def allowed_path(value):
    if not isinstance(value, str) or len(value) > 1024:
        raise ValueError("Invalid image reference")
    roots = [ROOT / "storage/app/public", ROOT / "storage/framework/cache/visual-search"]
    candidate = Path(value)
    if not candidate.is_absolute() or ".." in candidate.parts or value.startswith("\\\\"):
        raise ValueError("Invalid image reference")
    if not any(candidate.is_relative_to(root) for root in roots):
        raise ValueError("Image reference outside approved storage")
    path = candidate.resolve(strict=True)
    if not any(path.is_relative_to(root.resolve()) for root in roots) or not path.is_file():
        raise ValueError("Image reference outside approved storage")
    if path.stat().st_size > 12 * 1024 * 1024:
        raise ValueError("Image file too large")
    return path


class CpuClip:
    def __init__(self, model, threads=1):
        import numpy as np
        import onnxruntime as ort
        from PIL import Image, ImageOps

        with model.open("rb") as source:
            if hashlib.file_digest(source, "sha256").hexdigest() != MODEL_SHA256:
                raise ValueError("Model integrity verification failed")
        self.np, self.Image, self.ImageOps = np, Image, ImageOps
        Image.MAX_IMAGE_PIXELS = 16_000_000
        ort.disable_telemetry_events()
        options = ort.SessionOptions()
        options.intra_op_num_threads = threads
        options.inter_op_num_threads = 1
        options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
        options.add_session_config_entry("session.intra_op.allow_spinning", "0")
        options.add_session_config_entry("session.inter_op.allow_spinning", "0")
        options.log_severity_level = 3
        started = time.monotonic()
        self.session = ort.InferenceSession(str(model), sess_options=options, providers=["CPUExecutionProvider"])
        self.load_ms = round((time.monotonic() - started) * 1000, 2)
        self.input_name = self.session.get_inputs()[0].name
        self.output_name = self.session.get_outputs()[0].name
        self.mean = np.array([0.48145466, 0.4578275, 0.40821073], dtype=np.float32)
        self.std = np.array([0.26862954, 0.26130258, 0.27577711], dtype=np.float32)

    def embed(self, path):
        np, Image, ImageOps = self.np, self.Image, self.ImageOps
        path = allowed_path(str(path))
        with Image.open(path) as source:
            if source.format not in ("JPEG", "PNG", "WEBP") or source.width * source.height > 16_000_000:
                raise ValueError("Unsupported image")
            if max(source.width, source.height) / min(source.width, source.height) > 20:
                raise ValueError("Unsupported image aspect ratio")
            image = ImageOps.exif_transpose(source).convert("RGB")
            width, height = image.size
            scale = 224 / min(width, height)
            image = image.resize((max(224, int(width * scale)), max(224, int(height * scale))), Image.Resampling.BICUBIC)
            left, top = (image.width - 224) // 2, (image.height - 224) // 2
            image = image.crop((left, top, left + 224, top + 224))
            pixels = (np.asarray(image, dtype=np.float32) / np.float32(255) - self.mean) / self.std
            tensor = np.ascontiguousarray(pixels.transpose(2, 0, 1)[None, ...])
        vector = self.session.run([self.output_name], {self.input_name: tensor})[0].reshape(-1)
        norm = float(np.linalg.norm(vector))
        if len(vector) != 512 or not math.isfinite(norm) or norm <= 0 or not np.isfinite(vector).all():
            raise ValueError("Invalid embedding output")
        return (vector / norm).astype(np.float32).tolist()


class RuntimeServer(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 8

    def __init__(self, address, scheduler, token, threads):
        self.scheduler, self.token, self.threads = scheduler, token, threads
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(address, Handler)

    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            try:
                request.sendall(b"HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            finally:
                self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except Exception:
            self.slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def reply(self, status, payload):
        data = json.dumps(payload, allow_nan=False).encode()
        try:
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("Connection", "close")
            if status == 503:
                self.send_header("Retry-After", "3")
            self.end_headers()
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError, TimeoutError):
            pass

    def authorized(self):
        return hmac.compare_digest(self.headers.get("Authorization", ""), "Bearer " + self.server.token)

    def do_GET(self):
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        if self.path != "/health":
            return self.reply(404, {"error": "not_found"})
        self.reply(200, {"version": VERSION, "dimensions": 512, "threads": self.server.threads,
                         "load_ms": self.server.scheduler.engine.load_ms,
                         **self.server.scheduler.status()})

    def do_POST(self):
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        if self.path != "/embed":
            return self.reply(404, {"error": "not_found"})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 0 < length <= 2048 or self.headers.get("Content-Type", "").split(";")[0] != "application/json":
                return self.reply(422, {"error": "invalid_request"})
            data = json.loads(self.rfile.read(length))
            if not isinstance(data, dict) or data.get("version") != VERSION:
                return self.reply(409, {"error": "model_version_mismatch"})
            purpose = data.get("purpose", "search")
            if purpose not in ("search", "index"):
                raise ValueError("Unknown purpose")
            path = allowed_path(data.get("path"))
            result = self.server.scheduler.submit(path, purpose, 8 if purpose == "search" else 45)
            self.reply(200, result)
        except RuntimeBusy:
            self.reply(503, {"error": "runtime_busy"})
        except (ValueError, TypeError, OSError, json.JSONDecodeError):
            self.reply(422, {"error": "invalid_image"})
        except Exception:
            self.reply(503, {"error": "runtime_unavailable"})


def singleton_lock(path):
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = path.open("a+b")
    handle.seek(0)
    handle.write(b"1")
    handle.flush()
    handle.seek(0)
    try:
        if __import__("os").name == "nt":
            import msvcrt
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        handle.close()
        raise RuntimeError("A visual runtime is already running for this project.") from None
    return handle


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--threads", type=int, choices=[1, 2], default=1)
    args = parser.parse_args()
    with singleton_lock(PRIVATE / "runtime.lock"):
        token = (PRIVATE / "runtime-token").read_text(encoding="ascii").strip()
        if len(token) != 64:
            raise RuntimeError("Runtime token is missing or invalid")
        engine = CpuClip(PRIVATE / "model/vision_model_quantized.onnx", args.threads)
        scheduler = PriorityScheduler(engine)
        server = RuntimeServer(("127.0.0.1", args.port), scheduler, token, args.threads)
        print(json.dumps({"ready": True, "version": VERSION, "port": args.port, "load_ms": engine.load_ms}), flush=True)
        try:
            server.serve_forever(poll_interval=0.25)
        except KeyboardInterrupt:
            pass
        finally:
            scheduler.close()
            server.server_close()


if __name__ == "__main__":
    main()
