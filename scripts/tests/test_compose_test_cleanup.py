import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.compose_test_cleanup import (
    DAEMON_LABEL,
    ROOT,
    SAFE_BACKEND_TEST_ENV,
    SafetyError,
    TARGETS,
    assert_test_context,
    sanitized_env,
    validate_project_name,
    validate_compose_config,
    verify_container,
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
                        "/run/user/1000",
                        str(Path.home() / ".local/share/veltra-test-docker"),
                    )

    def test_missing_context_or_daemon_identity_is_rejected(self):
        with self.assertRaises(SafetyError):
            assert_test_context(None, None, [], None, None)

    def test_test_daemon_requires_the_exact_socket_and_label(self):
        assert_test_context(
            "veltra-test-isolated",
            "unix:///run/user/1000/veltra-test-docker/docker.sock",
            [DAEMON_LABEL],
            "/run/user/1000",
            str(Path.home() / ".local/share/veltra-test-docker"),
        )
        with self.assertRaises(SafetyError):
            assert_test_context(
                "veltra-test-isolated",
                "unix:///var/run/docker.sock",
                [DAEMON_LABEL],
                "/run/user/1000",
                str(Path.home() / ".local/share/veltra-test-docker"),
            )

    def test_compose_and_docker_environment_is_not_inherited(self):
        with patch.dict(
            "os.environ",
            {
                "COMPOSE_PROJECT_NAME": "veltra",
                "DOCKER_HOST": "tcp://primary.invalid",
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
                    },
                    "networks": {"isolated": {}},
                },
                "migrate-test": {
                    "image": "veltra-test-backend:local",
                    "environment": {
                        "DB_CONNECTION": "pgsql",
                        "DB_HOST": "postgres",
                        "DB_DATABASE": "veltra_testing",
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
