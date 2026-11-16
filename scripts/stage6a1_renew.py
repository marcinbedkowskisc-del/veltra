#!/usr/bin/env python3
"""Renew a verified local Stage 6A.1 RAW attestation; never changes Docker resources."""

from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import time
from pathlib import Path
from typing import Any

if __package__:
    from .stage6a1_isolation import NETWORK, POSTGRES_VOLUME, REDIS_VOLUME, SafetyError, validate_snapshot
else:
    from stage6a1_isolation import NETWORK, POSTGRES_VOLUME, REDIS_VOLUME, SafetyError, validate_snapshot


ROOT = Path(__file__).resolve().parents[1]
DOCKER = ["/usr/bin/docker", "--context", "default"]
ENV = {"PATH": "/usr/bin:/bin", "HOME": "/root", "DOCKER_CONFIG": "/root/.docker"}
RAW = "raw.json"
TEMPORARY_PREFIX = ".stage6a1-candidate-"
TEMPORARY_NAME = re.compile(r"\.stage6a1-candidate-[0-9a-f]{32}\Z")
RENEWAL_VERIFIED = False
IDENTITY_KEYS = {
    "compose_project", "postgres_volume", "container_hostname", "expires_at", "organization_id",
    "user_id", "database_oid", "server_address", "mailpit_address", "raw_integration_enabled",
}

CANDIDATE_PHP = r"""
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$instance = $app->make(App\Domain\Installation\LocalStage6a1Instance::class);
$database = $instance->databaseIdentity();
$organizations = App\Models\Organization::where('is_active', true)
    ->where('authorization_initialized', true)->get(['id']);
if (App\Models\Organization::count() !== 1 || $organizations->count() !== 1) exit(1);
$users = App\Models\User::where('organization_id', $organizations[0]->id)
    ->where('is_initial_identity', true)->where('is_active', true)
    ->whereNotNull('email_verified_at')->get(['id']);
if ($users->count() !== 1) exit(1);
$identity = [
    'compose_project' => 'veltra-stage6a1', 'postgres_volume' => 'veltra-stage6a1_postgres-data',
    'container_hostname' => gethostname(), 'expires_at' => time() + 3600,
    'organization_id' => (string) $organizations[0]->id, 'user_id' => (string) $users[0]->id,
    'database_oid' => (string) $database->oid, 'server_address' => gethostbyname('postgres'),
    'mailpit_address' => gethostbyname('mailpit'), 'raw_integration_enabled' => true,
];
$instance->assertConfiguration($identity, is_file('/.dockerenv'));
$instance->assertDatabase($identity, $database);
if (config('database.redis.default.host') !== 'redis') exit(1);
echo json_encode($identity, JSON_THROW_ON_ERROR);
"""

VERIFY_PHP = r"""
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = $app->make(App\Domain\Installation\LocalStage6a1Instance::class)->rawConfigurationIdentity();
if ($identity === null) exit(1);
echo hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
"""


def docker(*args: str) -> str:
    result = subprocess.run(DOCKER + list(args), env=ENV, capture_output=True, text=True, check=False)
    if result.returncode:
        raise SafetyError("Docker inspection or container verification failed.")
    return result.stdout


def inspect_one(*args: str) -> dict[str, Any]:
    result = json.loads(docker(*args))
    if not isinstance(result, list) or len(result) != 1 or not isinstance(result[0], dict):
        raise SafetyError("Docker inspection did not identify exactly one resource.")
    return result[0]


def snapshot() -> dict[str, Any]:
    ids = docker("ps", "-aq", "--no-trunc", "--filter", "label=com.docker.compose.project=veltra-stage6a1").splitlines()
    containers = json.loads(docker("inspect", *ids)) if ids else []
    return {
        "context": inspect_one("context", "inspect", "default"),
        "info": json.loads(docker("info", "--format", "{{json .}}")),
        "containers": containers,
        "network": inspect_one("network", "inspect", NETWORK),
        "postgres_volume": inspect_one("volume", "inspect", POSTGRES_VOLUME),
        "postgres_volume_users": docker("ps", "-aq", "--no-trunc", "--filter", f"volume={POSTGRES_VOLUME}").splitlines(),
        "redis_volume": inspect_one("volume", "inspect", REDIS_VOLUME),
        "redis_volume_users": docker("ps", "-aq", "--no-trunc", "--filter", f"volume={REDIS_VOLUME}").splitlines(),
    }


def open_trusted_directory(path: str) -> int:
    if not path.startswith("/") or path == "/" or any(part in {"", ".", ".."} for part in path[1:].split("/")):
        raise SafetyError("Credential directory must be a canonical absolute path.")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for component in path[1:].split("/"):
            directory = os.fstat(fd)
            if directory.st_uid != 0 or directory.st_mode & 0o022:
                raise SafetyError("A credential directory ancestor is not protected by root.")
            next_fd = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        directory = os.fstat(fd)
        if directory.st_uid != 0 or directory.st_mode & 0o022:
            raise SafetyError("Credential directory is not protected by root.")
        return fd
    except BaseException:
        os.close(fd)
        raise


def check_current_file(directory_fd: int) -> None:
    try:
        info = os.stat(RAW, dir_fd=directory_fd, follow_symlinks=False)
    except FileNotFoundError:
        return
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o222
            or info.st_size > 4096 or info.st_nlink != 1):
        raise SafetyError("Existing credential is not a protected regular file.")


def lock_directory(directory_fd: int) -> None:
    try:
        fcntl.flock(directory_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError as exc:
        raise SafetyError("Another renewal is already in progress.") from exc


def cleanup_orphans(directory_fd: int) -> None:
    removed = False
    for name in os.listdir(directory_fd):
        if not TEMPORARY_NAME.fullmatch(name):
            continue
        info = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        mode = stat.S_IMODE(info.st_mode)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1
                or info.st_size > 4096 or (mode & ~0o600 and mode != 0o444)):
            raise SafetyError("Unsafe credential candidate found; manual investigation required.")
        os.unlink(name, dir_fd=directory_fd)
        removed = True
    if removed:
        os.fsync(directory_fd)


def verify_mount_identity(container_id: str, directory_fd: int) -> None:
    host = os.fstat(directory_fd)
    output = docker("exec", container_id, "php", "-r",
                    '$s=stat("/run/veltra-stage6a1"); echo json_encode(["dev"=>$s["dev"],"ino"=>$s["ino"]]);')
    mounted = json.loads(output)
    if mounted != {"dev": host.st_dev, "ino": host.st_ino}:
        raise SafetyError("The backend mount does not identify the verified host directory.")


def verify_postgres_bind(container_id: str) -> None:
    path = ROOT / "docker/postgres/10-create-runtime-role.sh"
    host = path.lstat()
    if not stat.S_ISREG(host.st_mode):
        raise SafetyError("PostgreSQL init script is not a regular file.")
    output = docker("exec", container_id, "stat", "-Lc", '{"dev":%d,"ino":%i}',
                    "/docker-entrypoint-initdb.d/10-create-runtime-role.sh")
    if json.loads(output) != {"dev": host.st_dev, "ino": host.st_ino}:
        raise SafetyError("PostgreSQL init-script mount does not match the host file.")


def validate_identity(value: Any) -> None:
    if not isinstance(value, dict) or set(value) != IDENTITY_KEYS or type(value["expires_at"]) is not int:
        raise SafetyError("Backend did not verify the Stage identity.")
    if (value["compose_project"] != "veltra-stage6a1" or value["postgres_volume"] != POSTGRES_VOLUME
            or value["raw_integration_enabled"] is not True
            or any(not isinstance(value[key], str) or not value[key]
                   for key in ("container_hostname", "organization_id", "user_id", "database_oid",
                               "server_address", "mailpit_address"))):
        raise SafetyError("Backend returned an invalid Stage identity.")
    if not time.time() < value["expires_at"] <= time.time() + 86400:
        raise SafetyError("Credential expiry is outside the permitted window.")


def candidate(container_id: str) -> dict[str, Any]:
    value = json.loads(docker("exec", container_id, "php", "-r", CANDIDATE_PHP))
    validate_identity(value)
    return value


def verify_addresses(identity: dict[str, Any], containers: list[dict[str, Any]], backend_id: str) -> None:
    by_service = {container["Config"]["Labels"]["com.docker.compose.service"]: container for container in containers}
    addresses = {service: container["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
                 for service, container in by_service.items()}
    if (identity["server_address"] != addresses["postgres"] or identity["mailpit_address"] != addresses["mailpit"]
            or not addresses["redis"]):
        raise SafetyError("Application dependency addresses do not match the Stage network.")
    resolved = docker("exec", backend_id, "php", "-r", 'echo gethostbyname("redis");').strip()
    if resolved != addresses["redis"]:
        raise SafetyError("Redis DNS does not resolve to the Stage container.")


def publish(directory_fd: int, data: bytes) -> None:
    if not data or len(data) > 4096:
        raise SafetyError("Credential length is invalid.")

    def unique_fields(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result = dict(pairs)
        if len(result) != len(pairs):
            raise SafetyError("Credential contains duplicate fields.")
        return result

    try:
        identity = json.loads(data, object_pairs_hook=unique_fields)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise SafetyError("Credential format is invalid.") from exc
    validate_identity(identity)
    temporary = TEMPORARY_PREFIX + secrets.token_hex(16)
    fd = os.open(temporary, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                 0o600, dir_fd=directory_fd)
    try:
        with os.fdopen(fd, "w+b") as stream:
            stream.write(data)
            stream.flush()
            os.fchmod(stream.fileno(), 0o444)
            file_info = os.fstat(stream.fileno())
            if (file_info.st_uid != 0 or not stat.S_ISREG(file_info.st_mode)
                    or stat.S_IMODE(file_info.st_mode) != 0o444 or file_info.st_nlink != 1
                    or file_info.st_size != len(data) or file_info.st_dev != os.fstat(directory_fd).st_dev):
                raise SafetyError("New credential is not a protected regular file.")
            stream.seek(0)
            if stream.read() != data:
                raise SafetyError("New credential does not match the verified identity.")
            os.fsync(stream.fileno())
        validate_identity(identity)
        check_current_file(directory_fd)
        os.replace(temporary, RAW, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
        os.fsync(directory_fd)
    finally:
        try:
            os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError:
            pass


def renew(directory_fd: int, data: bytes, verify: Any) -> None:
    try:
        publish(directory_fd, data)
        verify()
    except (OSError, SafetyError) as exc:
        raise SafetyError(
            "Publication or verification failed; the new credential may already be active. "
            "No rollback was attempted; inspect the guard and directory before retrying."
        ) from exc


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify and renew a Stage 6A.1 RAW attestation.")
    parser.add_argument("--credential-dir", required=True, help="Absolute protected host directory mounted in Stage backend.")
    parser.add_argument("--daemon-id", required=True, help="Docker Desktop daemon ID independently checked by the administrator.")
    args = parser.parse_args()
    if not RENEWAL_VERIFIED:
        raise SafetyError("Renewal is disabled until interruption recovery and integration tests are verified.")
    if os.geteuid() != 0 or not sys.stdin.isatty() or not sys.stdout.isatty():
        raise SafetyError("Root privileges and an interactive terminal are required.")
    directory_fd = open_trusted_directory(args.credential_dir)
    try:
        lock_directory(directory_fd)
        check_current_file(directory_fd)
        first = snapshot()
        ids = validate_snapshot(first, args.credential_dir, args.daemon_id)
        verify_mount_identity(ids["backend"], directory_fd)
        verify_postgres_bind(ids["postgres"])
        initial = candidate(ids["backend"])
        verify_addresses(initial, first["containers"], ids["backend"])
        print(f"Daemon: {args.daemon_id}\nNetwork: {first['network']['Id']}\nPostgreSQL volume: {POSTGRES_VOLUME}")
        for service, container_id in sorted(ids.items()):
            print(f"{service}: {container_id}")
        if input("Type ODNOW STAGE6A1 to confirm: ") != "ODNOW STAGE6A1":
            raise SafetyError("Administrator did not confirm.")
        second = snapshot()
        current_ids = validate_snapshot(second, args.credential_dir, args.daemon_id)
        if (current_ids != ids or second["network"]["Id"] != first["network"]["Id"]
                or second["postgres_volume"]["CreatedAt"] != first["postgres_volume"]["CreatedAt"]
                or second["redis_volume"]["CreatedAt"] != first["redis_volume"]["CreatedAt"]):
            raise SafetyError("Stage identities changed after confirmation.")
        verify_mount_identity(ids["backend"], directory_fd)
        verify_postgres_bind(ids["postgres"])
        refreshed = candidate(ids["backend"])
        verify_addresses(refreshed, second["containers"], ids["backend"])
        if {k: v for k, v in refreshed.items() if k != "expires_at"} != {
            k: v for k, v in initial.items() if k != "expires_at"
        }:
            raise SafetyError("Stage application identity changed after confirmation.")
        check_current_file(directory_fd)
        cleanup_orphans(directory_fd)
        data = json.dumps(refreshed, separators=(",", ":"), ensure_ascii=False).encode()
        expected = hashlib.sha256(data).hexdigest()
        def verify() -> None:
            actual = docker("exec", ids["backend"], "php", "-r", VERIFY_PHP).strip()
            if actual != expected:
                raise SafetyError("The actual backend guard rejected or read a different credential.")
        renew(directory_fd, data, verify)
        print("Stage 6A.1 RAW attestation renewed and validated by the backend guard.")
        return 0
    finally:
        os.close(directory_fd)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (SafetyError, OSError, json.JSONDecodeError) as error:
        print(f"Renewal failed: {error}", file=sys.stderr)
        sys.exit(1)
