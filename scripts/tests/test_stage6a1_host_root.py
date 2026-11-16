import hashlib
import io
import json
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import stage6a1_renew as renewal
from scripts.tests.test_stage6a1_security import credential, service, snapshot


@unittest.skipUnless(os.geteuid() == 0, "requires actual host root")
class HostRootTest(unittest.TestCase):
    def test_protected_directory_ownership_permissions_and_symlinks(self):
        with tempfile.TemporaryDirectory(prefix="veltra-stage6a3-host-", dir="/run") as temporary:
            root = Path(temporary)
            ancestor = root / "ancestor"
            ancestor.mkdir(mode=0o755)
            directory = ancestor / "credentials"
            directory.mkdir(mode=0o755)
            fd = renewal.open_trusted_directory(str(directory))
            os.close(fd)

            link = root / "linked-directory"
            link.symlink_to(directory, target_is_directory=True)
            with self.assertRaises((OSError, renewal.SafetyError)):
                renewal.open_trusted_directory(str(link))

            ancestor.chmod(0o777)
            try:
                with self.assertRaises(renewal.SafetyError):
                    renewal.open_trusted_directory(str(directory))
            finally:
                ancestor.chmod(0o755)

            os.chown(directory, 65534, 65534)
            try:
                with self.assertRaises(renewal.SafetyError):
                    renewal.open_trusted_directory(str(directory))
            finally:
                os.chown(directory, 0, 0)

    def test_root_publication_and_unprivileged_write_denial(self):
        with tempfile.TemporaryDirectory(prefix="veltra-stage6a3-host-", dir="/run") as temporary:
            root = Path(temporary)
            root.chmod(0o755)
            directory = root / "credentials"
            directory.mkdir(mode=0o755)
            fd = renewal.open_trusted_directory(str(directory))
            try:
                renewal.lock_directory(fd)
                renewal.publish(fd, credential("host-synthetic"))
                active = directory / renewal.RAW
                metadata = active.lstat()
                self.assertEqual(metadata.st_uid, 0)
                self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o444)
                self.assertEqual(metadata.st_nlink, 1)
                renewal.check_current_file(fd)

                read_fd, write_fd = os.pipe()
                pid = os.fork()
                if pid == 0:
                    os.close(read_fd)
                    try:
                        os.setgroups([])
                        os.setgid(65534)
                        os.setuid(65534)
                        try:
                            probe_fd = os.open(directory / "unauthorized", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
                        except PermissionError:
                            os.write(write_fd, b"denied")
                        else:
                            os.close(probe_fd)
                            os.write(write_fd, b"allowed")
                    finally:
                        os._exit(0)
                os.close(write_fd)
                try:
                    result = os.read(read_fd, 16)
                    _, status = os.waitpid(pid, 0)
                finally:
                    os.close(read_fd)
                self.assertEqual(status, 0)
                self.assertEqual(result, b"denied")
                self.assertFalse((directory / "unauthorized").exists())

                active.unlink()
                active.symlink_to("missing")
                with self.assertRaises(renewal.SafetyError):
                    renewal.check_current_file(fd)
            finally:
                os.close(fd)

    def test_synthetic_confirmation_flow_with_mocked_docker(self):
        class Terminal(io.StringIO):
            def isatty(self):
                return True

        with tempfile.TemporaryDirectory(prefix="veltra-stage6a3-host-", dir="/run") as temporary:
            directory = Path(temporary) / "credentials"
            directory.mkdir(mode=0o755)
            inspected = snapshot()
            service(inspected, "backend")["Mounts"][0]["Source"] = str(directory)
            inspected["postgres_volume"]["CreatedAt"] = "synthetic"
            inspected["redis_volume"]["CreatedAt"] = "synthetic"
            identity = json.loads(credential("synthetic-host"))
            serialized = json.dumps(identity, separators=(",", ":"), ensure_ascii=False).encode()
            digest = hashlib.sha256(serialized).hexdigest()
            arguments = ["stage6a1_renew.py", "--credential-dir", str(directory), "--daemon-id", "daemon-id"]
            active = directory / renewal.RAW

            def execute(answer):
                with patch.object(renewal, "RENEWAL_VERIFIED", True), \
                        patch.object(sys, "argv", arguments), \
                        patch.object(sys, "stdin", Terminal()), patch.object(sys, "stdout", Terminal()), \
                        patch("builtins.input", return_value=answer), \
                        patch.object(renewal, "snapshot", return_value=inspected), \
                        patch.object(renewal, "verify_mount_identity"), \
                        patch.object(renewal, "verify_postgres_bind"), \
                        patch.object(renewal, "candidate", return_value=identity), \
                        patch.object(renewal, "verify_addresses"), \
                        patch.object(renewal, "docker", return_value=digest):
                    return renewal.main()

            with self.assertRaises(renewal.SafetyError):
                execute("NO")
            self.assertFalse(active.exists())
            self.assertEqual(execute("ODNOW STAGE6A1"), 0)
            self.assertEqual(active.read_bytes(), serialized)
            self.assertEqual(active.stat().st_uid, 0)


if __name__ == "__main__":
    unittest.main()
