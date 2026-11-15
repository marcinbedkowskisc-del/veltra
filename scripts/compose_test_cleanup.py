#!/usr/bin/env python3
"""Guarded Compose validation and cleanup for disposable test projects."""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable


ROOT = Path(__file__).resolve().parents[1]
TEST_CONTEXT = "veltra-test-isolated"
DAEMON_LABEL = "com.veltra.daemon-role=disposable-test"
EXPECTED_SOCKET_NAME = "veltra-test-docker/docker.sock"
LOCK_PATH = Path.home() / ".cache" / "veltra" / "compose-test-clean.lock"
FORBIDDEN_PROJECTS = {"veltra", "veltra-stage6a1"}
REQUIRED_ENV_KEYS = {
    "TEST_APP_KEY",
    "TEST_MUTATION_SIGNING_KEY",
    "TEST_PG_ADMIN_PASSWORD",
    "TEST_DB_APP_PASSWORD",
    "TEST_DB_MIGRATION_PASSWORD",
}
SAFE_BACKEND_TEST_ENV = ROOT / "tests" / "compose" / "backend.test.env"
FORBIDDEN_CONNECTION_ENV_KEYS = {
    "DATABASE_URL",
    "DB_URL",
    "PGHOST",
    "PGSERVICE",
    "POSTGRES_HOST",
    "REDIS_URL",
}


@dataclass(frozen=True)
class Target:
    project: str
    compose_file: str
    services: frozenset[str]
    volumes: frozenset[str]
    profiles: tuple[str, ...] = ()


TARGETS = {
    "application": Target(
        "veltra-test-application",
        "compose.testing.yaml",
        frozenset({"postgres", "redis", "clamav", "backend-test", "migrate-test"}),
        frozenset(),
    ),
    "stage3e-b1": Target(
        "veltra-test-stage3e-b1",
        "compose.stage3e-b1-test.yaml",
        frozenset({"postgres", "redis", "clamav", "runner"}),
        frozenset({"test-cache", "test-storage"}),
    ),
    "stage3e-b2": Target(
        "veltra-test-stage3e-b2",
        "compose.stage3e-b2-test.yaml",
        frozenset({"postgres", "redis", "clamav", "runner"}),
        frozenset({"test-cache", "test-storage"}),
    ),
    "stage3e-b3": Target(
        "veltra-test-stage3e-b3",
        "compose.stage3e-b3-test.yaml",
        frozenset({"postgres", "redis", "clamav", "runner"}),
        frozenset({"test-cache", "test-storage"}),
    ),
    "stage3e-b41": Target(
        "veltra-test-stage3e-b41",
        "compose.stage3e-b41-test.yaml",
        frozenset({"postgres", "redis", "clamav", "runner"}),
        frozenset({"test-cache", "test-storage"}),
    ),
    "stage2c-security": Target(
        "veltra-test-stage2c-security",
        "compose.stage2c-security-test.yaml",
        frozenset({"postgres", "redis", "clamav", "migrate", "test-runner"}),
        frozenset(),
        ("setup", "tools"),
    ),
}


class SafetyError(RuntimeError):
    pass


def validate_project_name(project: str) -> None:
    if project in FORBIDDEN_PROJECTS or not re.fullmatch(r"veltra-test-[a-z0-9-]+", project):
        raise SafetyError(f"Refusing non-test Compose project name: {project}")


def validate_env_file(path: Path) -> Path:
    if path.is_symlink() or not path.is_file():
        raise SafetyError("The explicit synthetic test env file is missing or is a symlink.")
    resolved = path.resolve()
    forbidden = {ROOT / ".env"}
    if resolved in {item.resolve() for item in forbidden if item.exists()}:
        raise SafetyError("Primary and Stage environment files are forbidden.")

    keys: set[str] = set()
    for line in resolved.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        key, separator, value = stripped.partition("=")
        if not separator or not re.fullmatch(r"TEST_[A-Z0-9_]+", key):
            raise SafetyError("Test env files may contain only explicit TEST_* assignments.")
        if key in keys or not value.strip() or "$" in value:
            raise SafetyError(f"Missing, duplicate, or interpolated test env value: {key}")
        keys.add(key)
    if not REQUIRED_ENV_KEYS.issubset(keys):
        missing = ", ".join(sorted(REQUIRED_ENV_KEYS - keys))
        raise SafetyError(f"Test env file is incomplete: {missing}")
    return resolved


def validate_compose_config(config: dict[str, Any], target: Target) -> None:
    validate_project_name(target.project)
    if config.get("name") != target.project:
        raise SafetyError("Rendered Compose project name does not match the fixed test identity.")

    services = config.get("services")
    if not isinstance(services, dict) or set(services) != set(target.services):
        raise SafetyError("Rendered Compose service set differs from the allowlist.")
    for service_name, service in services.items():
        if not isinstance(service, dict):
            raise SafetyError(f"Invalid service configuration: {service_name}")
        forbidden_service_options = (
            "container_name",
            "hostname",
            "ports",
            "network_mode",
            "links",
            "volumes_from",
            "devices",
            "privileged",
        )
        if any(service.get(option) for option in forbidden_service_options):
            raise SafetyError(f"Explicit host or shared-resource settings are forbidden: {service_name}")
        image = service.get("image") or ""
        if image.startswith(("veltra-backend:", "veltra-frontend:")) or "stage6a1" in image:
            raise SafetyError(f"Primary or Stage image is forbidden in test configuration: {service_name}")
        build = service.get("build")
        if build is not None:
            if not isinstance(build, dict) or not isinstance(build.get("context"), str):
                raise SafetyError(f"Test build context is ambiguous: {service_name}")
            build_context = Path(build["context"]).resolve()
            try:
                build_context.relative_to(ROOT)
            except ValueError as exc:
                raise SafetyError("Test build contexts must remain inside the repository.") from exc
            dockerignore = build_context / ".dockerignore"
            if not dockerignore.is_file():
                raise SafetyError("Test build context must exclude host environment files.")
            ignore_patterns = {
                line.strip()
                for line in dockerignore.read_text(encoding="utf-8").splitlines()
                if line.strip() and not line.lstrip().startswith("#")
            }
            if not {".env", ".env.*"}.issubset(ignore_patterns):
                raise SafetyError("Test build context must exclude .env and .env.* files.")
        environment = service.get("environment", {})
        if not isinstance(environment, dict):
            raise SafetyError(f"Invalid service environment: {service_name}")
        if any(environment.get(key) for key in FORBIDDEN_CONNECTION_ENV_KEYS):
            raise SafetyError(f"Alternate database or Redis endpoints are forbidden: {service_name}")
        if any(environment.get(key) not in (None, "redis") for key in ("REDIS_HOST", "TEST_REDIS_HOST")):
            raise SafetyError(f"Service can target a non-test Redis endpoint: {service_name}")
        networks = service.get("networks", {})
        if set(networks) != {"isolated"}:
            raise SafetyError(f"Service is not confined to the isolated network: {service_name}")
        mounts = service.get("volumes", [])
        if not isinstance(mounts, list):
            raise SafetyError(f"Invalid service mounts: {service_name}")
        safe_env_overlay_targets: set[str] = set()
        for mount in mounts:
            if not isinstance(mount, dict):
                raise SafetyError("Unnormalized Compose mount found.")
            if mount.get("type") == "bind" and Path(mount.get("source", "")).resolve() == SAFE_BACKEND_TEST_ENV:
                source = Path(mount["source"])
                if (
                    source.is_symlink()
                    or not source.is_file()
                    or any(
                        line.strip() and not line.lstrip().startswith("#")
                        for line in source.read_text(encoding="utf-8").splitlines()
                    )
                ):
                    raise SafetyError("The backend test env overlay must be a regular, empty env file.")
                safe_env_overlay_targets.add(mount.get("target", ""))
        for mount in mounts:
            if mount.get("target") in {"/var/lib/postgresql/data", "/data"}:
                raise SafetyError("Database and Redis data paths must be tmpfs, not volumes or binds.")
            if mount.get("type") == "bind":
                source_value = mount.get("source")
                if not source_value:
                    raise SafetyError("Test bind mount source is missing.")
                source = Path(source_value).resolve()
                try:
                    source.relative_to(ROOT)
                except ValueError as exc:
                    raise SafetyError("Test bind mounts must remain inside the repository.") from exc
                is_sensitive_env_file = source.name == ".env" or (
                    source.name.startswith(".env.") and source.name != ".env.example"
                )
                if is_sensitive_env_file and source != SAFE_BACKEND_TEST_ENV:
                    raise SafetyError("Primary and Stage environment files are forbidden bind mounts.")
                if source.is_dir() and any(
                    child.is_file()
                    and (child.name == ".env" or child.name.startswith(".env.") and child.name != ".env.example")
                    for child in source.iterdir()
                ):
                    expected_overlay = f"{mount.get('target', '').rstrip('/')}/.env"
                    if expected_overlay not in safe_env_overlay_targets:
                        raise SafetyError("A source directory containing .env requires the empty test env overlay.")

    networks = config.get("networks", {})
    if set(networks) != {"isolated"}:
        raise SafetyError("Only the isolated test network is allowed.")
    network = networks["isolated"]
    if network.get("internal") is not True or network.get("external") is True:
        raise SafetyError("Test network must be internal and Compose-owned.")
    if not str(network.get("name", "")).startswith(f"{target.project}_"):
        raise SafetyError("Test network name is not scoped to the fixed project.")

    volumes = config.get("volumes", {})
    if set(volumes) != set(target.volumes):
        raise SafetyError("Rendered Compose volume set differs from the allowlist.")
    for key, volume in volumes.items():
        if volume.get("external") is True or not str(volume.get("name", "")).startswith(f"{target.project}_"):
            raise SafetyError(f"External or unscoped volume is forbidden: {key}")

    postgres = services.get("postgres")
    if not isinstance(postgres, dict):
        raise SafetyError("Test PostgreSQL service is missing.")
    postgres_env = postgres.get("environment", {})
    if postgres_env.get("POSTGRES_DB") != "veltra_testing":
        raise SafetyError("Test PostgreSQL must use only veltra_testing.")
    if "/var/lib/postgresql/data" not in postgres.get("tmpfs", []):
        raise SafetyError("Test PostgreSQL data must be held in tmpfs.")

    redis = services.get("redis")
    if not isinstance(redis, dict) or "/data" not in redis.get("tmpfs", []):
        raise SafetyError("Test Redis data must be held in tmpfs.")

    for service_name in {"backend-test", "migrate-test", "runner", "test-runner"} & set(services):
        environment = services[service_name].get("environment", {})
        if (
            environment.get("DB_CONNECTION") != "pgsql"
            or environment.get("DB_HOST") != "postgres"
            or environment.get("DB_DATABASE") != "veltra_testing"
        ):
            raise SafetyError(f"Application service can target a non-test database: {service_name}")

    rendered = json.dumps(config, sort_keys=True)
    forbidden_values = (
        "veltra_postgres-data",
        "veltra_redis-data",
        "veltra_default",
        "veltra-stage6a1",
        "veltra_stage6a1",
    )
    if any(value in rendered for value in forbidden_values):
        raise SafetyError("Rendered configuration references a primary or Stage resource.")


def assert_test_context(
    current: str | None,
    endpoint: str | None,
    labels: list[str],
    runtime_dir: str | None,
    data_root: str | None,
) -> None:
    if current != TEST_CONTEXT:
        raise SafetyError(f"Active Docker context must be {TEST_CONTEXT}.")
    if not runtime_dir:
        raise SafetyError("XDG_RUNTIME_DIR is required for the isolated rootless daemon.")
    expected_endpoint = f"unix://{runtime_dir.rstrip('/')}/{EXPECTED_SOCKET_NAME}"
    if endpoint != expected_endpoint:
        raise SafetyError("Test context endpoint does not match the dedicated rootless daemon socket.")
    if DAEMON_LABEL not in labels:
        raise SafetyError("Docker daemon is missing the disposable-test identity label.")
    expected_root = (Path.home() / ".local" / "share" / "veltra-test-docker").resolve()
    if not data_root or Path(data_root).resolve() != expected_root:
        raise SafetyError("Docker data root does not match the dedicated disposable-test directory.")


def verify_container(
    container: dict[str, Any],
    target: Target,
    expected_networks: set[str],
    run: Callable[[list[str]], str],
) -> None:
    config = container.get("Config", {})
    labels = config.get("Labels") or {}
    service = labels.get("com.docker.compose.service")
    if labels.get("com.docker.compose.project") != target.project or service not in target.services:
        raise SafetyError("Container ownership is missing, ambiguous, or outside the selected project.")
    if not str(container.get("Name", "")).startswith(f"/{target.project}-{service}-"):
        raise SafetyError("Container name does not match its Compose ownership labels.")
    attached = set(container.get("NetworkSettings", {}).get("Networks", {}))
    if not attached or not attached.issubset(expected_networks):
        raise SafetyError("Container is attached to an unknown or shared network.")

    for mount in container.get("Mounts", []):
        if mount.get("Type") == "bind":
            source_value = mount.get("Source")
            if not source_value:
                raise SafetyError("Bind mount source is missing.")
            source = Path(source_value).resolve()
            try:
                source.relative_to(ROOT)
            except ValueError as exc:
                raise SafetyError("Container bind mount points outside the repository.") from exc
        elif mount.get("Type") == "volume":
            volume_name = mount.get("Name")
            if not volume_name:
                raise SafetyError("Anonymous volume ownership is ambiguous.")
            volume = json.loads(run(["volume", "inspect", volume_name]))[0]
            volume_labels = volume.get("Labels") or {}
            volume_key = volume_labels.get("com.docker.compose.volume")
            if (
                volume_labels.get("com.docker.compose.project") != target.project
                or volume_key not in target.volumes
                or volume_name != f"{target.project}_{volume_key}"
            ):
                raise SafetyError("Unknown, external, shared, or unowned volume is attached.")
        elif mount.get("Type") != "tmpfs":
            raise SafetyError("Unsupported container mount type.")


def sanitized_env() -> dict[str, str]:
    allowed = {"PATH", "HOME", "DOCKER_CONFIG", "XDG_RUNTIME_DIR", "LANG", "LC_ALL"}
    return {key: value for key, value in os.environ.items() if key in allowed}


def docker_runner(context: str | None) -> Callable[[list[str]], str]:
    base = ["docker"]
    if context:
        base += ["--context", context]

    def run(args: list[str]) -> str:
        result = subprocess.run(
            base + args,
            cwd=ROOT,
            env=sanitized_env(),
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode:
            detail = result.stderr.strip() or "Docker command failed."
            raise SafetyError(detail)
        return result.stdout

    return run


def render_and_validate(run: Callable[[list[str]], str], env_file: Path, target: Target) -> dict[str, Any]:
    command = [
        "compose",
        "--project-name",
        target.project,
        "--project-directory",
        str(ROOT),
        "--env-file",
        str(env_file),
        "-f",
        str(ROOT / target.compose_file),
    ]
    for profile in target.profiles:
        command.extend(["--profile", profile])
    command.extend(["config", "--format", "json"])
    output = run(command)
    try:
        config = json.loads(output)
    except json.JSONDecodeError as exc:
        raise SafetyError("Compose configuration did not render as JSON.") from exc
    validate_compose_config(config, target)
    return config


def verify_test_context(
    run_current: Callable[[list[str]], str],
    run_test: Callable[[list[str]], str],
) -> None:
    try:
        current = run_current(["context", "show"]).strip()
        context_data = json.loads(run_current(["context", "inspect", TEST_CONTEXT]))
        if not isinstance(context_data, list) or not context_data:
            raise SafetyError("Isolated Docker context is missing.")
        endpoint = context_data[0].get("Endpoints", {}).get("docker", {}).get("Host")
        labels_output = run_test(["info", "--format", "{{json .Labels}}"])
        labels = json.loads(labels_output)
        data_root = run_test(["info", "--format", "{{.DockerRootDir}}"]).strip()
        if not isinstance(labels, list):
            raise SafetyError("Docker daemon labels are unavailable.")
        assert_test_context(current, endpoint, labels, os.environ.get("XDG_RUNTIME_DIR"), data_root)
    except (OSError, json.JSONDecodeError, KeyError, IndexError) as exc:
        raise SafetyError("Unable to verify the isolated test Docker context.") from exc


def verify_resources(
    run: Callable[[list[str]], str],
    config: dict[str, Any],
    target: Target,
) -> tuple[int, int, int]:
    expected_networks = {network["name"] for network in config["networks"].values()}
    expected_volume_names = {volume["name"] for volume in config.get("volumes", {}).values()}

    container_ids = [
        line
        for line in run(["ps", "-aq", "--filter", f"label=com.docker.compose.project={target.project}"]).splitlines()
        if line
    ]
    for container_id in container_ids:
        container = json.loads(run(["inspect", container_id]))[0]
        verify_container(container, target, expected_networks, run)

    network_ids = [
        line
        for line in run(["network", "ls", "-q", "--filter", f"label=com.docker.compose.project={target.project}"]).splitlines()
        if line
    ]
    for network_id in network_ids:
        network = json.loads(run(["network", "inspect", network_id]))[0]
        labels = network.get("Labels") or {}
        if (
            labels.get("com.docker.compose.project") != target.project
            or network.get("Name") not in expected_networks
            or not network.get("Internal")
        ):
            raise SafetyError("Network ownership is missing, shared, external, or not isolated.")
        attached_container_ids = set(network.get("Containers") or {})
        if not attached_container_ids.issubset(set(container_ids)):
            raise SafetyError("Test network is shared with a container outside the selected project.")

    volume_ids = [
        line
        for line in run(["volume", "ls", "-q", "--filter", f"label=com.docker.compose.project={target.project}"]).splitlines()
        if line
    ]
    for volume_name in volume_ids:
        volume = json.loads(run(["volume", "inspect", volume_name]))[0]
        labels = volume.get("Labels") or {}
        if (
            labels.get("com.docker.compose.project") != target.project
            or labels.get("com.docker.compose.volume") not in target.volumes
            or volume_name not in expected_volume_names
        ):
            raise SafetyError("A project volume is unknown or has ambiguous ownership.")
        attached_ids = [
            line
            for line in run(["ps", "-aq", "--filter", f"volume={volume_name}"]).splitlines()
            if line
        ]
        if not set(attached_ids).issubset(set(container_ids)):
            raise SafetyError("A test volume is shared with a container outside the selected project.")
    return len(container_ids), len(network_ids), len(volume_ids)


def acquire_lock() -> int:
    LOCK_PATH.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    flags = os.O_CREAT | os.O_RDWR
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    descriptor = os.open(LOCK_PATH, flags, 0o600)
    fcntl.flock(descriptor, fcntl.LOCK_EX)
    return descriptor


def execute_cleanup(target: Target, env_file: Path, confirm_project: str | None, execute: bool) -> int:
    validate_project_name(target.project)
    if execute and confirm_project != target.project:
        raise SafetyError("--execute requires --confirm-project with the exact selected project.")

    lock_fd = acquire_lock()
    try:
        run = docker_runner(TEST_CONTEXT)
        verify_test_context(docker_runner(None), run)
        config = render_and_validate(run, env_file, target)
        containers, networks, volumes = verify_resources(run, config, target)
        print(
            f"Verified {target.project}: containers={containers}, networks={networks}, volumes={volumes}. "
            "Named volumes will be preserved."
        )
        if not execute:
            print("Dry run only; no cleanup executed.")
            return 0

        command = [
            "compose",
            "--project-name",
            target.project,
            "--project-directory",
            str(ROOT),
            "--env-file",
            str(env_file),
            "-f",
            str(ROOT / target.compose_file),
        ]
        for profile in target.profiles:
            command.extend(["--profile", profile])
        command.append("down")
        run(command)
        print(f"Removed containers and network for {target.project}; volumes were not requested for removal.")
        return 0
    finally:
        os.close(lock_fd)


def validate_only(target: Target, env_file: Path) -> int:
    run = docker_runner(None)
    render_and_validate(run, env_file, target)
    print(f"Validated isolated Compose configuration for {target.project}; no Docker resources were contacted.")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Fail-closed cleanup for isolated VELTRA test projects.")
    parser.add_argument("--target", choices=sorted(TARGETS), required=True)
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--execute", action="store_true", help="Actually remove verified containers and network.")
    parser.add_argument("--confirm-project")
    parser.add_argument("--validate-only", action="store_true", help="Render and validate configuration without contacting Docker.")
    args = parser.parse_args(argv)
    try:
        env_file = validate_env_file(args.env_file)
        if args.validate_only:
            if args.execute:
                raise SafetyError("--validate-only cannot be combined with --execute.")
            return validate_only(TARGETS[args.target], env_file)
        return execute_cleanup(TARGETS[args.target], env_file, args.confirm_project, args.execute)
    except (OSError, SafetyError, json.JSONDecodeError, KeyError, IndexError, TypeError, AttributeError) as exc:
        print(f"Refusing cleanup: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
