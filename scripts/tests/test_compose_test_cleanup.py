import json
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from unittest.mock import patch

from scripts.compose_test_cleanup import (
    DAEMON_LABEL,
    ROOT,
    SAFE_BACKEND_TEST_ENV,
    SafetyError,
    TEST_DOCKER_CONFIG,
    TARGETS,
    assert_test_context,
    docker_runner,
    sanitized_env,
    validate_project_name,
    validate_compose_config,
    verify_container,
    verify_resources,
    verify_test_docker_config,
    verify_test_context,
)


class ComposeTestCleanupSafetyTests(unittest.TestCase):
    def test_primary_and_stage_project_names_are_rejected(self):
        for project in ("veltra", "veltra-stage6a1", "other-project"):
            with self.subTest(project=project):
                with self.assertRaises(SafetyError):
                    validate_project_name(project)

    def test_primary_and_stage_docker_contexts_are_rejected(self):
        for context in ("default", "desktop-linux", "veltra-stage6a1"):
            with self.subTest(context=context):
                with self.assertRaises(SafetyError):
                    assert_test_context(
                        context,
                        "unix:///run/user/1000/veltra-test-docker/docker.sock",
                        [DAEMON_LABEL],
                        ["name=rootless"],
                        "/run/user/1000",
                        str(Path.home() / ".local/share/veltra-test-docker"),
                    )

    def test_missing_context_or_daemon_identity_is_rejected(self):
        with self.assertRaises(SafetyError):
            assert_test_context(None, None, [], [], None, None)

    def test_test_daemon_requires_the_exact_socket_and_label(self):
        assert_test_context(
            "veltra-test-isolated",
            "unix:///run/user/1000/veltra-test-docker/docker.sock",
            [DAEMON_LABEL],
            ["name=rootless"],
            "/run/user/1000",
            str(Path.home() / ".local/share/veltra-test-docker"),
        )
        with self.assertRaises(SafetyError):
            assert_test_context(
                "veltra-test-isolated",
                "unix:///var/run/docker.sock",
                [DAEMON_LABEL],
                ["name=rootless"],
                "/run/user/1000",
                str(Path.home() / ".local/share/veltra-test-docker"),
            )
        with self.assertRaises(SafetyError):
            assert_test_context(
                "veltra-test-isolated",
                "unix:///run/user/1000/veltra-test-docker/docker.sock",
                [DAEMON_LABEL],
                ["name=seccomp"],
                "/run/user/1000",
                str(Path.home() / ".local/share/veltra-test-docker"),
            )

    def test_explicit_context_verification_does_not_read_or_change_global_context(self):
        calls = []

        def run(args):
            calls.append(args)
            if args == ["context", "inspect", "veltra-test-isolated"]:
                return (
                    '[{"Endpoints":{"docker":{"Host":'
                    '"unix:///run/user/1000/veltra-test-docker/docker.sock"}}}]'
                )
            if args == ["info", "--format", "{{json .Labels}}"]:
                return f'["{DAEMON_LABEL}"]'
            if args == ["info", "--format", "{{json .SecurityOptions}}"]:
                return '["name=seccomp,profile=builtin","name=rootless"]'
            if args == ["info", "--format", "{{.DockerRootDir}}"]:
                return str(Path.home() / ".local/share/veltra-test-docker")
            if args[0] in {"ps", "network", "volume"}:
                return ""
            raise AssertionError(f"Unexpected Docker command: {args}")

        with patch.dict("os.environ", {"XDG_RUNTIME_DIR": "/run/user/1000"}):
            verify_test_context(run)

        self.assertNotIn(["context", "show"], calls)
        self.assertIn(
            ["ps", "-aq", "--filter", "label=com.docker.compose.project=veltra"],
            calls,
        )
        self.assertIn(
            ["volume", "ls", "-q", "--filter", "label=com.docker.compose.project=veltra-stage6a1"],
            calls,
        )

    def test_isolated_daemon_exposing_a_protected_project_is_rejected(self):
        def run(args):
            if args == ["context", "inspect", "veltra-test-isolated"]:
                return (
                    '[{"Endpoints":{"docker":{"Host":'
                    '"unix:///run/user/1000/veltra-test-docker/docker.sock"}}}]'
                )
            if args == ["info", "--format", "{{json .Labels}}"]:
                return f'["{DAEMON_LABEL}"]'
            if args == ["info", "--format", "{{json .SecurityOptions}}"]:
                return '["name=rootless"]'
            if args == ["info", "--format", "{{.DockerRootDir}}"]:
                return str(Path.home() / ".local/share/veltra-test-docker")
            if args[:2] == ["ps", "-aq"] and "veltra" in args[-1]:
                return "protected-container-id"
            if args[0] in {"ps", "network", "volume"}:
                return ""
            raise AssertionError(f"Unexpected Docker command: {args}")

        with patch.dict("os.environ", {"XDG_RUNTIME_DIR": "/run/user/1000"}):
            with self.assertRaisesRegex(SafetyError, "protected project veltra"):
                verify_test_context(run)

    def test_docker_runner_pins_test_context_and_discards_inherited_selectors(self):
        completed = SimpleNamespace(returncode=0, stdout="ok", stderr="")
        with patch.dict(
            "os.environ",
            {
                "DOCKER_CONTEXT": "default",
                "DOCKER_HOST": "tcp://primary.invalid",
                "DOCKER_CONFIG": "/tmp/inherited-docker",
                "PATH": "/usr/bin",
                "HOME": "/tmp/test-home",
            },
            clear=True,
        ):
            with patch("scripts.compose_test_cleanup.subprocess.run", return_value=completed) as run:
                self.assertEqual(docker_runner("veltra-test-isolated")(["info"]), "ok")

        self.assertEqual(run.call_args.args[0][:3], ["docker", "--context", "veltra-test-isolated"])
        self.assertNotIn("DOCKER_CONTEXT", run.call_args.kwargs["env"])
        self.assertNotIn("DOCKER_HOST", run.call_args.kwargs["env"])
        self.assertEqual(
            run.call_args.kwargs["env"]["DOCKER_CONFIG"],
            str(TEST_DOCKER_CONFIG),
        )

    def test_shared_test_docker_config_is_rejected(self):
        with TemporaryDirectory() as temp_dir:
            config = Path(temp_dir) / ".config" / "veltra-test" / "docker"
            config.mkdir(parents=True)
            for path in (config.parents[1], config.parents[0], config):
                path.chmod(0o700)

            with patch("scripts.compose_test_cleanup.TEST_DOCKER_CONFIG", config):
                verify_test_docker_config()
                config.chmod(0o755)
                with self.assertRaisesRegex(SafetyError, "must be private"):
                    verify_test_docker_config()

    def test_compose_and_docker_environment_is_not_inherited(self):
        with patch.dict(
            "os.environ",
            {
                "COMPOSE_PROJECT_NAME": "veltra",
                "DOCKER_HOST": "tcp://primary.invalid",
                "DOCKER_CONFIG": "/tmp/inherited-docker",
                "TEST_DB_APP_PASSWORD": "inherited-value",
                "PATH": "/usr/bin",
                "HOME": "/tmp/test-home",
            },
            clear=True,
        ):
            environment = sanitized_env()
        self.assertEqual(environment, {"PATH": "/usr/bin", "HOME": "/tmp/test-home"})

    def test_primary_or_stage_images_are_rejected_from_test_compose(self):
        project = TARGETS["application"].project
        config = self.application_config(project)
        config["services"]["backend-test"]["image"] = "veltra-backend:stage6a1-phase1"
        with self.assertRaises(SafetyError):
            validate_compose_config(config, TARGETS["application"])

    def test_primary_environment_files_and_alternate_database_urls_are_rejected(self):
        project = TARGETS["application"].project
        config = self.application_config(project)
        config["services"]["backend-test"]["volumes"] = [
            {"type": "bind", "source": str(ROOT / ".env"), "target": "/var/www/html/.env"}
        ]
        with self.assertRaises(SafetyError):
            validate_compose_config(config, TARGETS["application"])

        config = self.application_config(project)
        config["services"]["backend-test"]["environment"]["DATABASE_URL"] = "postgres://primary.invalid/veltra"
        with self.assertRaises(SafetyError):
            validate_compose_config(config, TARGETS["application"])

    def test_application_redis_override_is_pinned_to_test_service(self):
        project = TARGETS["application"].project
        config = self.application_config(project)
        config["services"]["backend-test"]["environment"]["TEST_REDIS_HOST"] = "127.0.0.1"
        with self.assertRaisesRegex(SafetyError, "non-test Redis endpoint"):
            validate_compose_config(config, TARGETS["application"])

    def test_backend_source_mount_requires_the_empty_env_overlay(self):
        project = TARGETS["application"].project
        config = self.application_config(project)
        config["services"]["backend-test"]["volumes"] = [
            {"type": "bind", "source": str(ROOT / "backend"), "target": "/var/www/html"}
        ]
        with self.assertRaises(SafetyError):
            validate_compose_config(config, TARGETS["application"])

        config["services"]["backend-test"]["volumes"].append(
            {
                "type": "bind",
                "source": str(SAFE_BACKEND_TEST_ENV),
                "target": "/var/www/html/.env",
                "read_only": True,
            }
        )
        validate_compose_config(config, TARGETS["application"])

    @staticmethod
    def application_config(project):
        service = {
            "image": "postgres:17-alpine",
            "environment": {"POSTGRES_DB": "veltra_testing"},
            "tmpfs": ["/var/lib/postgresql/data"],
            "networks": {"isolated": {}},
        }
        config = {
            "name": project,
            "services": {
                "postgres": service,
                "redis": {"image": "redis:7.4-alpine", "tmpfs": ["/data"], "networks": {"isolated": {}}},
                "clamav": {"image": "clamav/clamav:1.4", "networks": {"isolated": {}}},
                "backend-test": {
                    "image": "veltra-test-backend:local",
                    "environment": {
                        "DB_CONNECTION": "pgsql",
                        "DB_HOST": "postgres",
                        "DB_DATABASE": "veltra_testing",
                        "TEST_REDIS_HOST": "redis",
                        "TEST_REDIS_PORT": "6379",
                    },
                    "networks": {"isolated": {}},
                },
                "migrate-test": {
                    "image": "veltra-test-backend:local",
                    "environment": {
                        "DB_CONNECTION": "pgsql",
                        "DB_HOST": "postgres",
                        "DB_DATABASE": "veltra_testing",
                        "TEST_REDIS_HOST": "redis",
                        "TEST_REDIS_PORT": "6379",
                    },
                    "networks": {"isolated": {}},
                },
            },
            "networks": {"isolated": {"name": f"{project}_isolated", "internal": True}},
            "volumes": {},
        }
        return config

    def test_ambiguous_container_ownership_is_rejected(self):
        container = {
            "Name": "/veltra-test-stage3e-b1-runner-1",
            "Config": {"Labels": {"com.docker.compose.service": "runner"}},
            "NetworkSettings": {"Networks": {"veltra-test-stage3e-b1_isolated": {}}},
            "Mounts": [],
        }
        with self.assertRaises(SafetyError):
            verify_container(
                container,
                TARGETS["stage3e-b1"],
                {"veltra-test-stage3e-b1_isolated"},
                lambda _args: "",
            )

    def test_network_ownership_accepts_full_container_ids_from_docker(self):
        project = TARGETS["application"].project
        container_id = "a" * 64
        network_id = "b" * 64
        network_name = f"{project}_isolated"
        container = {
            "Name": f"/{project}-postgres-1",
            "Config": {
                "Labels": {
                    "com.docker.compose.project": project,
                    "com.docker.compose.service": "postgres",
                }
            },
            "NetworkSettings": {"Networks": {network_name: {}}},
            "Mounts": [],
        }
        network = {
            "Name": network_name,
            "Labels": {
                "com.docker.compose.project": project,
                "com.docker.compose.network": "isolated",
            },
            "Internal": True,
            "Containers": {container_id: {"Name": container["Name"]}},
        }

        def run(args):
            if args[:2] == ["ps", "-aq"]:
                self.assertIn("--no-trunc", args)
                return container_id
            if args == ["inspect", container_id]:
                return json.dumps([container])
            if args[:2] == ["network", "ls"]:
                return network_id
            if args == ["network", "inspect", network_id]:
                return json.dumps([network])
            if args[:2] == ["volume", "ls"]:
                return ""
            raise AssertionError(f"Unexpected Docker command: {args}")

        self.assertEqual(
            verify_resources(
                run,
                {"networks": {"isolated": {"name": network_name}}, "volumes": {}},
                TARGETS["application"],
            ),
            (1, 1, 0),
        )

    def test_unknown_volume_ownership_is_rejected(self):
        container = {
            "Name": "/veltra-test-stage3e-b1-runner-1",
            "Config": {
                "Labels": {
                    "com.docker.compose.project": "veltra-test-stage3e-b1",
                    "com.docker.compose.service": "runner",
                }
            },
            "NetworkSettings": {"Networks": {"veltra-test-stage3e-b1_isolated": {}}},
            "Mounts": [{"Type": "volume", "Name": "anonymous-volume"}],
        }

        def inspect_volume(_args):
            return '[{"Name":"anonymous-volume","Labels":{"com.docker.volume.anonymous":""}}]'

        with self.assertRaises(SafetyError):
            verify_container(
                container,
                TARGETS["stage3e-b1"],
                {"veltra-test-stage3e-b1_isolated"},
                inspect_volume,
            )


if __name__ == "__main__":
    unittest.main()
