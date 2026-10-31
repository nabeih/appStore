import importlib.util
import json
from pathlib import Path
import sys
import threading
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("visual_runtime", ROOT / "resources/ai/visual-search/runtime.py")
runtime = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = runtime
SPEC.loader.exec_module(runtime)


class ControlledEngine:
    load_ms = 0

    def __init__(self):
        self.started = threading.Event()
        self.release = threading.Event()
        self.order = []
        self.active = 0
        self.maximum = 0

    def embed(self, path):
        self.active += 1
        self.maximum = max(self.maximum, self.active)
        self.order.append(str(path))
        if str(path) == "running":
            self.started.set()
            self.release.wait(3)
        self.active -= 1
        return [1.0] + [0.0] * 511


class SchedulerTest(unittest.TestCase):
    def setUp(self):
        self.engine = ControlledEngine()
        self.scheduler = runtime.PriorityScheduler(self.engine)
        self.threads = []
        self.errors = []

    def tearDown(self):
        self.engine.release.set()
        for thread in self.threads:
            thread.join(4)
        self.scheduler.close()

    def submit(self, path, purpose="index", timeout=2):
        def run():
            try:
                self.scheduler.submit(Path(path), purpose, timeout)
            except runtime.RuntimeBusy as error:
                self.errors.append(error)
        thread = threading.Thread(target=run)
        self.threads.append(thread)
        thread.start()

    def wait_pending(self, purpose, count):
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline:
            if self.scheduler.status()["queued"][purpose] == count:
                return
            time.sleep(0.005)
        self.fail("Queue state did not become observable")

    def test_search_precedes_waiting_indexing_without_preempting_current_inference(self):
        self.submit("running")
        self.assertTrue(self.engine.started.wait(2))
        self.submit("index-next")
        self.wait_pending("index", 1)
        self.submit("search", "search")
        self.wait_pending("search", 1)
        self.assertEqual(self.engine.order, ["running"])
        self.engine.release.set()
        for thread in self.threads:
            thread.join(3)
        self.assertEqual(self.engine.order, ["running", "search", "index-next"])
        self.assertEqual(self.engine.maximum, 1)
        self.assertEqual(self.errors, [])

    def test_queue_capacity_is_bounded(self):
        self.submit("running")
        self.assertTrue(self.engine.started.wait(2))
        for index in range(4):
            self.submit(str(index), "search")
        self.wait_pending("search", 4)
        with self.assertRaises(runtime.RuntimeBusy):
            self.scheduler.submit(Path("excess"), "search", 0.1)

    def test_expired_queued_work_never_opens_the_image(self):
        self.submit("running")
        self.assertTrue(self.engine.started.wait(2))
        self.submit("expired", "search", 0.03)
        self.wait_pending("search", 1)
        time.sleep(0.06)
        self.engine.release.set()
        for thread in self.threads:
            thread.join(3)
        self.assertEqual(self.engine.order, ["running"])
        self.assertEqual(len(self.errors), 1)

    def test_paths_outside_private_search_and_public_images_are_rejected(self):
        with self.assertRaises(ValueError):
            runtime.allowed_path(str(ROOT / "resources/ai/visual-search/requirements.txt"))


class ProtocolTest(unittest.TestCase):
    def test_loopback_protocol_requires_authentication_and_rejects_paths(self):
        engine = ControlledEngine()
        scheduler = runtime.PriorityScheduler(engine)
        server = runtime.RuntimeServer(("127.0.0.1", 0), scheduler, "a" * 64, 1)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        url = f"http://127.0.0.1:{server.server_port}"
        try:
            with self.assertRaises(urllib.error.HTTPError) as denied:
                urllib.request.urlopen(url + "/health")
            self.assertEqual(denied.exception.code, 401)
            request = urllib.request.Request(url + "/health", headers={"Authorization": "Bearer " + "a" * 64})
            with urllib.request.urlopen(request) as response:
                self.assertEqual(json.load(response)["version"], runtime.VERSION)
            request = urllib.request.Request(url + "/embed", data=json.dumps({"version": runtime.VERSION,
                "path": str(ROOT / "composer.json")}).encode(), headers={"Authorization": "Bearer " + "a" * 64,
                "Content-Type": "application/json"})
            with self.assertRaises(urllib.error.HTTPError) as invalid:
                urllib.request.urlopen(request)
            self.assertEqual(invalid.exception.code, 422)
            self.assertEqual(engine.order, [])
        finally:
            server.shutdown()
            server.server_close()
            scheduler.close()
            thread.join(2)


if __name__ == "__main__":
    unittest.main()
