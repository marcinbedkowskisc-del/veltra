import copy
import json
import os
import select
import signal
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import stage6a1_isolation as isolation
from scripts import stage6a1_renew as renewal


DIRECTORY = "/root/stage-credentials"


def snapshot():
    ids = {service: f"{index:064x}" for index, service in enumerate(sorted(isolation.SERVICES), 1)}
    containers = []
    endpoints = {}
    for service, container_id in ids.items():
        number = int(container_id, 16)
        endpoint_id = f"{number + 100:064x}"
        address = f"172.22.0.{number + 1}"
        endpoints[container_id] = {"Name": f"{isolation.PROJECT}-{service}-1",
                                   "EndpointID": endpoint_id, "IPv4Address": f"{address}/16"}
        published = isolation.PUBLISHED_PORTS.get(service, {})
        bindings = {port: [{"HostIp": "127.0.0.1", "HostPort": host_port}]
                    for port, host_port in published.items()}
        exposed = dict.fromkeys(published, {})
        runtime = copy.deepcopy(bindings)
        if service in {"postgres", "redis", "queue"}:
            port = {"postgres": "5432/tcp", "redis": "6379/tcp", "queue": "8000/tcp"}[service]
            exposed[port] = {}
            runtime[port] = None
        mounts = []
        if service == "backend":
            mounts.append({"Type": "bind", "Source": DIRECTORY,
                           "Destination": isolation.CREDENTIAL_TARGET, "RW": False})
        if service == "postgres":
            mounts.extend([
                {"Type": "volume", "Name": isolation.POSTGRES_VOLUME,
                 "Destination": "/var/lib/postgresql/data", "RW": True},
                {"Type": "bind", "Destination": "/docker-entrypoint-initdb.d/10-create-runtime-role.sh",
                 "RW": False},
            ])
        if service == "redis":
            mounts.append({"Type": "volume", "Name": isolation.REDIS_VOLUME, "Destination": "/data", "RW": True})
        containers.append({
            "Id": container_id, "Name": f"/{isolation.PROJECT}-{service}-1",
            "Config": {"Labels": {"com.docker.compose.project": isolation.PROJECT,
                                  "com.docker.compose.service": service}, "ExposedPorts": exposed},
            "State": {"Running": True, "Health": {"Status": "healthy"}},
            "HostConfig": {"Privileged": False, "Links": None, "VolumesFrom": None,
                           "PublishAllPorts": False, "NetworkMode": isolation.NETWORK,
                           "PortBindings": bindings},
            "NetworkSettings": {"Networks": {isolation.NETWORK: {"NetworkID": "a" * 64,
                                                                  "EndpointID": endpoint_id, "IPAddress": address}},
                                "Ports": runtime},
            "Mounts": mounts,
        })
    return {
        "context": {"Name": "default", "Endpoints": {"docker": {"Host": "unix:///var/run/docker.sock"}}},
        "info": {"ID": "daemon-id", "Name": "docker-desktop", "DockerRootDir": "/var/lib/docker",
                 "SecurityOptions": []},
        "containers": containers,
        "network": {"Name": isolation.NETWORK, "Driver": "bridge", "Id": "a" * 64,
                    "Labels": {"com.docker.compose.project": isolation.PROJECT,
                               "com.docker.compose.network": "default"},
                    "Containers": endpoints},
        "postgres_volume": {"Name": isolation.POSTGRES_VOLUME, "Driver": "local",
                            "Options": None,
                            "Labels": {"com.docker.compose.project": isolation.PROJECT,
                                       "com.docker.compose.volume": "postgres-data"}},
        "postgres_volume_users": [ids["postgres"]],
        "redis_volume": {"Name": isolation.REDIS_VOLUME, "Driver": "local", "Options": None,
                         "Labels": {"com.docker.compose.project": isolation.PROJECT,
                                    "com.docker.compose.volume": "redis-data"}},
        "redis_volume_users": [ids["redis"]],
    }


def service(data, name):
    return next(container for container in data["containers"]
                if container["Config"]["Labels"]["com.docker.compose.service"] == name)


class IsolationTest(unittest.TestCase):
    def validate(self, data):
        return isolation.validate_snapshot(data, DIRECTORY, "daemon-id")

    def test_valid_snapshot(self):
        self.assertEqual(set(self.validate(snapshot())), isolation.SERVICES)

    def test_public_and_unspecified_addresses_rejected_in_both_sources(self):
        for source in ("configured", "runtime", "both"):
            for address in ("0.0.0.0", "::", "", "192.0.2.1"):
                with self.subTest(source=source, address=address):
                    data = snapshot()
                    backend = service(data, "backend")
                    if source in ("configured", "both"):
                        backend["HostConfig"]["PortBindings"]["8000/tcp"][0]["HostIp"] = address
                    if source in ("runtime", "both"):
                        backend["NetworkSettings"]["Ports"]["8000/tcp"][0]["HostIp"] = address
                    with self.assertRaises(isolation.SafetyError):
                        self.validate(data)

    def test_port_information_must_be_complete_and_consistent(self):
        for change in ("missing-config", "missing-runtime", "missing-exposed", "mismatch-port",
                       "mismatch-address", "duplicate", "missing-required", "unexpected-port"):
            with self.subTest(change=change):
                data = snapshot()
                backend = service(data, "backend")
                host = backend["HostConfig"]
                net = backend["NetworkSettings"]
                if change == "missing-config":
                    del host["PortBindings"]
                elif change == "missing-runtime":
                    net["Ports"] = None
                elif change == "missing-exposed":
                    backend["Config"]["ExposedPorts"] = None
                elif change == "mismatch-port":
                    host["PortBindings"]["8000/tcp"][0]["HostPort"] = "9001"
                elif change == "mismatch-address":
                    host["PortBindings"]["8000/tcp"][0]["HostIp"] = "::1"
                elif change == "duplicate":
                    host["PortBindings"]["8000/tcp"].append(
                        dict(host["PortBindings"]["8000/tcp"][0]))
                elif change == "missing-required":
                    host["PortBindings"] = {}
                    net["Ports"]["8000/tcp"] = None
                else:
                    postgres = service(data, "postgres")
                    binding = [{"HostIp": "127.0.0.1", "HostPort": "5432"}]
                    postgres["HostConfig"]["PortBindings"]["5432/tcp"] = binding
                    postgres["NetworkSettings"]["Ports"]["5432/tcp"] = copy.deepcopy(binding)
                with self.assertRaises(isolation.SafetyError):
                    self.validate(data)

    def test_relationships_are_checked(self):
        for change in ("context", "daemon", "project", "container", "network", "endpoint", "shared-network",
                       "volume", "volume-options", "shared-volume", "redis-shared", "cross-volume", "mount"):
            with self.subTest(change=change):
                data = snapshot()
                backend = service(data, "backend")
                if change == "context":
                    data["context"]["Name"] = "veltra-test-isolated"
                elif change == "daemon":
                    data["info"]["ID"] = "wrong"
                elif change == "project":
                    backend["Config"]["Labels"]["com.docker.compose.project"] = "primary"
                elif change == "container":
                    backend["Id"] = "not-a-container-id"
                elif change == "network":
                    backend["NetworkSettings"]["Networks"][isolation.NETWORK]["NetworkID"] = "wrong"
                elif change == "endpoint":
                    data["network"]["Containers"][backend["Id"]]["IPv4Address"] = "192.0.2.1/24"
                elif change == "shared-network":
                    data["network"]["Containers"]["f" * 64] = {}
                elif change == "volume":
                    data["postgres_volume"]["Name"] = "primary-postgres"
                elif change == "volume-options":
                    data["postgres_volume"]["Options"] = {"type": "none", "device": "/primary"}
                elif change == "shared-volume":
                    data["postgres_volume_users"].append("f" * 64)
                elif change == "redis-shared":
                    data["redis_volume_users"].append("f" * 64)
                elif change == "cross-volume":
                    backend["Mounts"].append({"Type": "volume", "Name": "primary-postgres"})
                else:
                    backend["Mounts"][0]["RW"] = True
                with self.assertRaises(isolation.SafetyError):
                    self.validate(data)


def credential(marker):
    return json.dumps({
        "compose_project": isolation.PROJECT,
        "postgres_volume": isolation.POSTGRES_VOLUME,
        "container_hostname": marker,
        "expires_at": int(time.time()) + 3600,
        "organization_id": "synthetic-organization",
        "user_id": "synthetic-user",
        "database_oid": "12345",
        "server_address": "192.0.2.1",
        "mailpit_address": "192.0.2.2",
        "raw_integration_enabled": True,
    }, separators=(",", ":")).encode()


class RenewalTest(unittest.TestCase):
    def test_unverified_command_fails_before_accessing_resources(self):
        with patch.object(sys, "argv", ["stage6a1_renew.py", "--credential-dir", "/not-used",
                                       "--daemon-id", "not-used"]):
            with patch.object(renewal, "open_trusted_directory") as open_directory:
                with self.assertRaisesRegex(isolation.SafetyError, "Renewal is disabled"):
                    renewal.main()
                open_directory.assert_not_called()

    def test_lock_rejects_another_open_description(self):
        with tempfile.TemporaryDirectory() as directory:
            first = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            second = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                renewal.lock_directory(first)
                with self.assertRaises(isolation.SafetyError):
                    renewal.lock_directory(second)
            finally:
                os.close(first)
                os.close(second)

    @unittest.skipUnless(os.geteuid() == 0, "requires root ownership (or an isolated user namespace)")
    def test_publication_errors_leave_old_or_new_authoritative_without_rollback(self):
        for operation in ("fchmod", "fsync", "replace", "replace-after", "directory-fsync", "guard"):
            with self.subTest(operation=operation), tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / renewal.RAW
                old, new = credential("old"), credential("new")
                path.write_bytes(old)
                path.chmod(0o444)
                directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
                real = getattr(os, operation.replace("-after", "").replace("directory-", "")) \
                    if operation != "guard" else None
                fired = False

                def injected(*args, **kwargs):
                    nonlocal fired
                    target = operation != "directory-fsync" or args[0] == directory_fd
                    if not fired and target:
                        fired = True
                        if operation == "replace-after":
                            real(*args, **kwargs)
                        raise OSError("injected")
                    return real(*args, **kwargs)

                def guard():
                    if operation == "guard":
                        raise OSError("injected")

                try:
                    if operation == "guard":
                        with self.assertRaises(isolation.SafetyError):
                            renewal.renew(directory_fd, new, guard)
                    else:
                        name = operation.replace("-after", "").replace("directory-", "")
                        with patch.object(renewal.os, name, side_effect=injected):
                            with self.assertRaises(isolation.SafetyError):
                                renewal.renew(directory_fd, new, guard)
                    self.assertEqual(path.read_bytes(),
                                     new if operation in {"guard", "replace-after", "directory-fsync"} else old)
                    self.assertEqual(os.listdir(directory), [renewal.RAW])
                    self.assertEqual(path.stat().st_nlink, 1)
                finally:
                    os.close(directory_fd)

    @unittest.skipUnless(os.geteuid() == 0, "requires root ownership (or an isolated user namespace)")
    def test_sigkill_publication_boundaries_and_orphan_recovery(self):
        stages = ("before-create", "after-create", "after-file-fsync", "before-replace",
                  "after-replace", "before-directory-fsync", "before-verify")
        for stage in stages:
            with self.subTest(stage=stage), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                active = root / renewal.RAW
                old, new = credential("old"), credential("new")
                active.write_bytes(old)
                active.chmod(0o444)
                read_fd, write_fd = os.pipe()
                pid = os.fork()
                if pid == 0:
                    os.close(read_fd)
                    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
                    renewal.lock_directory(directory_fd)
                    real_open, real_fsync, real_replace = os.open, os.fsync, os.replace

                    def pause():
                        os.write(write_fd, b"ready")
                        signal.pause()

                    def interrupted_open(path, flags, *args, **kwargs):
                        is_candidate = isinstance(path, str) and path.startswith(renewal.TEMPORARY_PREFIX)
                        if is_candidate and stage == "before-create":
                            pause()
                        fd = real_open(path, flags, *args, **kwargs)
                        if is_candidate and stage == "after-create":
                            pause()
                        return fd

                    def interrupted_fsync(fd):
                        if fd == directory_fd and stage == "before-directory-fsync":
                            pause()
                        real_fsync(fd)
                        if fd != directory_fd and stage == "after-file-fsync":
                            pause()

                    def interrupted_replace(*args, **kwargs):
                        if stage == "before-replace":
                            pause()
                        real_replace(*args, **kwargs)
                        if stage == "after-replace":
                            pause()

                    def verify():
                        if stage == "before-verify":
                            pause()

                    try:
                        with patch.object(renewal.os, "open", side_effect=interrupted_open), \
                                patch.object(renewal.os, "fsync", side_effect=interrupted_fsync), \
                                patch.object(renewal.os, "replace", side_effect=interrupted_replace):
                            renewal.renew(directory_fd, new, verify)
                    finally:
                        os._exit(2)
                os.close(write_fd)
                try:
                    self.assertTrue(select.select([read_fd], [], [], 10)[0], "Child did not reach injection point")
                    self.assertEqual(os.read(read_fd, 5), b"ready")
                finally:
                    os.kill(pid, signal.SIGKILL)
                    _, status = os.waitpid(pid, 0)
                    os.close(read_fd)
                self.assertEqual(os.WTERMSIG(status), signal.SIGKILL)
                published = stage in {"after-replace", "before-directory-fsync", "before-verify"}
                self.assertEqual(active.read_bytes(), new if published else old)
                self.assertEqual(active.stat().st_nlink, 1)
                for entry in root.iterdir():
                    self.assertEqual(entry.stat().st_nlink, 1)
                    self.assertFalse(entry.name.startswith(".previous-"))
                fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    renewal.lock_directory(fd)
                    renewal.cleanup_orphans(fd)
                finally:
                    os.close(fd)
                self.assertEqual(active.read_bytes(), new if published else old)
                self.assertEqual(os.listdir(directory), [renewal.RAW])

    @unittest.skipUnless(os.geteuid() == 0, "requires root ownership (or an isolated user namespace)")
    def test_cleanup_rejects_symlinks_and_preserves_unrelated_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            active = root / renewal.RAW
            active.write_bytes(credential("old"))
            active.chmod(0o444)
            unrelated = root / ".stage6a1-candidate-not-ours"
            unrelated.write_bytes(b"unrelated")
            orphan = root / (renewal.TEMPORARY_PREFIX + "a" * 32)
            orphan.write_bytes(b"incomplete")
            orphan.chmod(0o600)
            fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                renewal.lock_directory(fd)
                renewal.cleanup_orphans(fd)
                self.assertFalse(orphan.exists())
                self.assertTrue(active.exists())
                self.assertTrue(unrelated.exists())
                orphan.symlink_to(active)
                with self.assertRaises(isolation.SafetyError):
                    renewal.cleanup_orphans(fd)
                self.assertTrue(orphan.is_symlink())
                self.assertEqual(active.stat().st_nlink, 1)
            finally:
                os.close(fd)

    def test_invalid_candidate_is_rejected_before_creation(self):
        with tempfile.TemporaryDirectory() as directory:
            fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                expired = json.loads(credential("old"))
                expired["expires_at"] = int(time.time()) - 1
                too_long = dict(expired, expires_at=int(time.time()) + 86401)
                for data in (b"{}", b"invalid", b"x" * 4097, json.dumps(expired).encode(),
                             json.dumps(too_long).encode(),
                             credential("old").replace(b'"expires_at":', b'"expires_at":1,"expires_at":'),
                             credential("old").replace(b'"raw_integration_enabled":true',
                                                       b'"raw_integration_enabled":false'),
                             credential("old").replace(b'"expires_at":', b'"extra":1,"expires_at":')):
                    with self.subTest(data_length=len(data)), self.assertRaises(isolation.SafetyError):
                        renewal.publish(fd, data)
                self.assertEqual(os.listdir(directory), [])
            finally:
                os.close(fd)


if __name__ == "__main__":
    unittest.main()
