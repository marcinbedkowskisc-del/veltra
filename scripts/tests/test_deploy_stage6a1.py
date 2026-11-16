import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import deploy_stage6a1 as deploy


def configuration():
    backend = {
        "image": "veltra-stage6a1-backend:local",
        "environment": {
            "APP_ENV": "local", "VELTRA_LOCAL_STAGE_PROJECT": "veltra-stage6a1",
            "DB_HOST": "postgres", "DB_DATABASE": "veltra_stage6a1",
            "APP_URL": "http://localhost:8001", "FRONTEND_URL": "http://localhost:5174",
        },
        "ports": [{"host_ip": "127.0.0.1", "target": 8000, "published": "8001", "protocol": "tcp"}],
    }
    return {
        "name": deploy.PROJECT,
        "services": {
            "backend": backend, "migrate": {"image": "veltra-stage6a1-backend:local"},
            "queue": {"image": "veltra-stage6a1-backend:local"},
            "frontend": {"image": "veltra-stage6a1-frontend:local",
                         "ports": [{"host_ip": "127.0.0.1", "target": 5173, "published": "5174"}]},
            "mailpit": {"ports": [{"host_ip": "127.0.0.1", "target": 8025, "published": "8026"}]},
            "postgres": {"environment": {"POSTGRES_DB": "veltra_stage6a1"}}, "redis": {},
        },
        "volumes": {f"{service}-data": {"name": name, "external": True}
                    for service, name in deploy.VOLUMES.items()},
    }


def persistent(service):
    volume = deploy.VOLUMES[service]
    target = "/var/lib/postgresql/data" if service == "postgres" else "/data"
    container = {
        "Id": f"{service}-container", "Name": f"/{deploy.PROJECT}-{service}-1",
        "Config": {"Labels": {"com.docker.compose.project": deploy.PROJECT,
                              "com.docker.compose.service": service}},
        "State": {"Running": True, "Health": {"Status": "healthy"}},
        "Mounts": [{"Type": "volume", "Name": volume, "Destination": target, "RW": True}],
        "NetworkSettings": {"Networks": {f"{deploy.PROJECT}_default": {}}},
    }
    metadata = {"Name": volume, "Labels": {"com.docker.compose.project": deploy.PROJECT,
                                           "com.docker.compose.volume": f"{service}-data"}}
    return container, metadata, [container["Id"]]


def application(service):
    ports = {"8000/tcp": [{"HostIp": "127.0.0.1", "HostPort": "8001"}]}
    return {
        "Name": f"/{deploy.PROJECT}-{service}-1",
        "Config": {"Labels": {"com.docker.compose.project": deploy.PROJECT,
                              "com.docker.compose.service": service}},
        "HostConfig": {"PortBindings": ports},
        "NetworkSettings": {"Networks": {f"{deploy.PROJECT}_default": {}}, "Ports": ports},
    }


class StageDeploymentTest(unittest.TestCase):
    def test_correct_configuration_and_persistent_resources_are_accepted(self):
        deploy.validate_compose(configuration())
        for service in deploy.VOLUMES:
            deploy.validate_persistent(service, *persistent(service))

    def test_wrong_project_database_ports_or_credentials_mount_are_rejected(self):
        changes = [
            lambda c: c.update(name="veltra-primary"),
            lambda c: c["services"]["backend"]["environment"].update(DB_DATABASE="veltra"),
            lambda c: c["services"]["backend"]["environment"].update(VELTRA_LOCAL_STAGE_PROJECT="veltra-primary"),
            lambda c: c["services"]["frontend"]["ports"][0].update(host_ip="0.0.0.0"),
            lambda c: c["services"]["backend"].update(volumes=[{"type": "bind"}]),
            lambda c: c["volumes"]["postgres-data"].update(name="veltra-primary-postgres-data"),
            lambda c: c["volumes"]["redis-data"].update(external=False),
        ]
        for change in changes:
            candidate = configuration()
            change(candidate)
            with self.subTest(candidate=candidate), self.assertRaises(RuntimeError):
                deploy.validate_compose(candidate)

    def test_wrong_daemon_and_context_are_rejected(self):
        context = {"Name": "default", "Endpoints": {"docker": {"Host": "unix:///var/run/docker.sock"}}}
        info = {"Name": "docker-desktop", "DockerRootDir": "/var/lib/docker", "SecurityOptions": []}
        deploy.validate_daemon(context, info)
        for bad_context, bad_info in (
            (context | {"Name": "veltra-test-isolated"}, info),
            (context, info | {"SecurityOptions": ["name=rootless"]}),
            (context, info | {"Name": "other"}),
        ):
            with self.subTest(context=bad_context, info=bad_info), self.assertRaises(RuntimeError):
                deploy.validate_daemon(bad_context, bad_info)

    def test_shared_or_wrong_persistent_volume_is_rejected(self):
        container, volume, users = persistent("postgres")
        for altered_container, altered_volume, altered_users in (
            (container, volume, users + ["other-container"]),
            (container, volume | {"Name": "veltra-primary-postgres-data"}, users),
            (container | {"NetworkSettings": {"Networks": {"veltra-primary_default": {}}}}, volume, users),
        ):
            with self.subTest(container=altered_container, volume=altered_volume), self.assertRaises(RuntimeError):
                deploy.validate_persistent("postgres", altered_container, altered_volume, altered_users)

    def test_application_port_sources_must_both_be_loopback_and_consistent(self):
        container = application("backend")
        deploy.validate_application("backend", container)
        container["HostConfig"]["PortBindings"] = {
            "8000/tcp": [{"HostIp": "0.0.0.0", "HostPort": "8001"}],
        }
        with self.assertRaises(RuntimeError):
            deploy.validate_application("backend", container)

    def test_stage_network_cannot_be_shared_or_inconsistent(self):
        containers = []
        endpoints = {}
        for index in range(6):
            identifier = f"container-{index}"
            name = f"stage-service-{index}"
            endpoint = f"endpoint-{index}"
            containers.append({"Id": identifier, "Name": f"/{name}", "NetworkSettings": {
                "Networks": {f"{deploy.PROJECT}_default": {"NetworkID": "network-id", "EndpointID": endpoint}}}})
            endpoints[identifier] = {"Name": name, "EndpointID": endpoint}
        network = {"Name": f"{deploy.PROJECT}_default", "Id": "network-id", "Driver": "bridge",
                   "Labels": {"com.docker.compose.project": deploy.PROJECT}, "Containers": endpoints}
        deploy.validate_network(network, containers)
        network["Containers"]["unexpected"] = {"Name": "primary", "EndpointID": "other"}
        with self.assertRaises(RuntimeError):
            deploy.validate_network(network, containers)

    def test_backup_verification_and_failure_cleanup(self):
        def fake_run(args, **kwargs):
            if "pg_restore" in args:
                self.assertEqual(kwargs["stdin"].read(), b"synthetic-dump")
            else:
                kwargs["stdout"].write(b"synthetic-dump")

        with tempfile.TemporaryDirectory() as directory:
            with patch.object(deploy, "run", side_effect=fake_run):
                path = deploy.backup(Path(directory), "postgres-id")
            self.assertEqual(path.read_bytes(), b"synthetic-dump")
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(deploy, "run", side_effect=subprocess.CalledProcessError(1, ["pg_dump"])):
                with self.assertRaises(subprocess.CalledProcessError):
                    deploy.backup(Path(directory), "postgres-id")
            self.assertEqual(os.listdir(directory), [])

    def test_backup_precedes_migration_and_only_application_services_are_recreated(self):
        config = configuration()
        calls = []
        applied = "\n".join(path.stem for path in (deploy.ROOT / "backend/database/migrations").glob("*.php"))

        def fake_output(args):
            if args[:2] == ["git", "-C"]:
                return "" if "status" in args else "synthetic-commit"
            if args[-3:] == ["config", "--format", "json"]:
                return json.dumps(config)
            if args[-3:] == ["info", "--format", "{{json .}}"]:
                return json.dumps({"Name": "docker-desktop", "DockerRootDir": "/var/lib/docker", "SecurityOptions": []})
            if "current_database()" in args[-1]:
                return "veltra_stage6a1"
            if "select migration from migrations" in args[-1]:
                return applied
            raise AssertionError("Unexpected synthetic command")

        context = [{"Name": "default", "Endpoints": {"docker": {"Host": "unix:///var/run/docker.sock"}}}]
        state = {"postgres": "pg-id", "redis": "redis-id"}
        with patch.object(sys, "argv", ["deploy_stage6a1.py", "--env-file", str(__file__), "--backup-dir", "/tmp"]), \
                patch.object(deploy, "output", side_effect=fake_output), \
                patch.object(deploy, "inspect", return_value=context), \
                patch.object(deploy, "persistent_snapshot", return_value=state), \
                patch.object(deploy, "validate_application"), \
                patch.object(deploy, "verify_network"), \
                patch.object(deploy, "backup", side_effect=lambda *args: calls.append("backup")), \
                patch.object(deploy, "verify_health", side_effect=lambda: calls.append("health")), \
                patch.object(deploy, "run", side_effect=lambda args: calls.append(args)):
            deploy.main()
        commands = [item for item in calls if isinstance(item, list)]
        migration = next(item for item in commands if "run" in item)
        recreate = next(item for item in commands if "up" in item)
        self.assertLess(calls.index("backup"), calls.index(migration))
        self.assertEqual(recreate[-3:], ["backend", "queue", "frontend"])
        self.assertIn("--no-deps", recreate)
        self.assertIn("health", calls)


if __name__ == "__main__":
    unittest.main()
