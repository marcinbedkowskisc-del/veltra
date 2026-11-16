#!/usr/bin/env python3
"""Deploy the committed local Stage revision without changing persistent services."""

import argparse
import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PROJECT = "veltra-stage6a1"
DOCKER = ["docker", "--context", "default"]
VOLUMES = {"postgres": "veltra-stage6a1_postgres-data", "redis": "veltra-stage6a1_redis-data"}


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def output(args):
    return run(args, capture_output=True, text=True).stdout.strip()


def inspect(*args):
    return json.loads(output(DOCKER + list(args)))


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def validate_compose(config):
    require(config.get("name") == PROJECT, "Unexpected Compose project.")
    services = config["services"]
    for service in ("backend", "migrate", "queue"):
        require(services[service]["image"] == "veltra-stage6a1-backend:local", "Unexpected backend image.")
    require(services["frontend"]["image"] == "veltra-stage6a1-frontend:local", "Unexpected frontend image.")
    environment = services["backend"]["environment"]
    require(environment["APP_ENV"] == "local" and environment["VELTRA_LOCAL_STAGE_PROJECT"] == PROJECT
            and environment["DB_HOST"] == "postgres"
            and environment["DB_DATABASE"] == "veltra_stage6a1"
            and environment["APP_URL"] == "http://localhost:8001"
            and environment["FRONTEND_URL"] == "http://localhost:5174",
            "Compose does not describe the local Stage database and URLs.")
    require(services["postgres"]["environment"]["POSTGRES_DB"] == "veltra_stage6a1",
            "Unexpected PostgreSQL database.")
    for service, volume in VOLUMES.items():
        declared = config["volumes"][f"{service}-data"]
        require(declared.get("external") is True and declared.get("name") == volume,
                "Stage persistent volume is not explicitly external.")
    for service, target, published in (("backend", 8000, "8001"), ("frontend", 5173, "5174"),
                                       ("mailpit", 8025, "8026")):
        ports = services[service].get("ports", [])
        require(len(ports) == 1 and ports[0].get("host_ip") == "127.0.0.1"
                and ports[0].get("target") == target and str(ports[0].get("published")) == published
                and ports[0].get("protocol", "tcp") == "tcp", "Unexpected Stage port binding.")
    require(not services["postgres"].get("ports") and not services["redis"].get("ports"),
            "Persistent services must not publish ports.")
    for service in ("backend", "migrate", "queue", "frontend"):
        require(not services[service].get("volumes"), "Unexpected application bind or volume mount.")


def validate_daemon(context, info):
    require(context["Name"] == "default" and context["Endpoints"]["docker"]["Host"] == "unix:///var/run/docker.sock",
            "Unexpected Docker context.")
    require(info["Name"] == "docker-desktop" and info["DockerRootDir"] == "/var/lib/docker"
            and not any("rootless" in value.lower() for value in info["SecurityOptions"]),
            "Expected Docker Desktop, not the isolated TEST daemon.")


def validate_persistent(service, container, volume, users):
    expected = VOLUMES[service]
    target = "/var/lib/postgresql/data" if service == "postgres" else "/data"
    labels = container["Config"]["Labels"]
    require(container["Name"] == f"/{PROJECT}-{service}-1"
            and labels.get("com.docker.compose.project") == PROJECT
            and labels.get("com.docker.compose.service") == service
            and container["State"]["Running"] and container["State"]["Health"]["Status"] == "healthy",
            f"Unexpected or unhealthy Stage {service} container.")
    mounts = [mount for mount in container["Mounts"] if mount["Destination"] == target]
    require(len(mounts) == 1 and mounts[0]["Type"] == "volume" and mounts[0]["Name"] == expected
            and mounts[0]["RW"], f"Unexpected Stage {service} storage.")
    require(volume["Name"] == expected
            and volume["Labels"].get("com.docker.compose.project") == PROJECT
            and volume["Labels"].get("com.docker.compose.volume") == f"{service}-data"
            and set(users) == {container["Id"]}, f"Stage {service} volume identity or ownership is ambiguous.")
    require(set(container["NetworkSettings"]["Networks"]) == {f"{PROJECT}_default"},
            f"Unexpected Stage {service} network.")


def validate_application(service, container):
    labels = container["Config"]["Labels"]
    require(container["Name"] == f"/{PROJECT}-{service}-1"
            and labels.get("com.docker.compose.project") == PROJECT
            and labels.get("com.docker.compose.service") == service
            and set(container["NetworkSettings"]["Networks"]) == {f"{PROJECT}_default"},
            f"Unexpected Stage {service} identity or network.")
    expected = {"backend": ("8000/tcp", "8001"), "frontend": ("5173/tcp", "5174"),
                "mailpit": ("8025/tcp", "8026")}
    expected_ports = {expected[service][0]: [{"HostIp": "127.0.0.1", "HostPort": expected[service][1]}]} \
        if service in expected else {}
    configured = container["HostConfig"]["PortBindings"] or {}
    runtime = {port: entries for port, entries in (container["NetworkSettings"]["Ports"] or {}).items() if entries}
    require(configured == expected_ports and runtime == expected_ports,
            f"Unexpected or inconsistent Stage {service} port binding.")


def persistent_snapshot():
    result = {}
    for service, volume in VOLUMES.items():
        container = inspect("inspect", f"{PROJECT}-{service}-1")[0]
        volume_info = inspect("volume", "inspect", volume)[0]
        users = output(DOCKER + ["ps", "-aq", "--no-trunc", "--filter", f"volume={volume}"]).splitlines()
        validate_persistent(service, container, volume_info, users)
        result[service] = container["Id"]
    return result


def validate_network(network, containers):
    require(network["Name"] == f"{PROJECT}_default"
            and network["Labels"].get("com.docker.compose.project") == PROJECT
            and network["Driver"] == "bridge", "Unexpected Stage network.")
    ids = {container["Id"] for container in containers}
    require(len(containers) == 6 and len(ids) == 6 and set(network["Containers"]) == ids,
            "Stage network is shared or incomplete.")
    for container in containers:
        settings = container["NetworkSettings"]["Networks"][network["Name"]]
        endpoint = network["Containers"][container["Id"]]
        require(settings["NetworkID"] == network["Id"] and settings["EndpointID"] == endpoint["EndpointID"]
                and endpoint["Name"] == container["Name"].removeprefix("/"), "Stage network identity is inconsistent.")


def verify_network():
    services = ("backend", "frontend", "queue", "mailpit", "postgres", "redis")
    containers = inspect("inspect", *(f"{PROJECT}-{service}-1" for service in services))
    validate_network(inspect("network", "inspect", f"{PROJECT}_default")[0], containers)


def backup(directory, postgres_id):
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(directory.is_dir(), "Backup path is not a directory.")
    path = directory / f"stage6a1-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}.dump"
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    verified = False
    try:
        with os.fdopen(fd, "wb") as stream:
            run(DOCKER + ["exec", postgres_id, "sh", "-c",
                          'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc'], stdout=stream)
            stream.flush()
            os.fsync(stream.fileno())
        require(path.stat().st_size > 0, "Database backup is empty.")
        with path.open("rb") as stream:
            run(DOCKER + ["exec", "-i", postgres_id, "pg_restore", "--exit-on-error", "--file=/dev/null"],
                stdin=stream, stdout=subprocess.DEVNULL)
        with path.open("rb") as stream:
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        verified = True
    finally:
        if not verified:
            path.unlink()
    print(f"Verified PostgreSQL backup: {path} (SHA-256 {digest})", flush=True)
    return path


def verify_health():
    deadline = time.monotonic() + 120
    while True:
        containers = inspect("inspect", f"{PROJECT}-backend-1", f"{PROJECT}-frontend-1", f"{PROJECT}-queue-1")
        for service, container in zip(("backend", "frontend", "queue"), containers):
            validate_application(service, container)
        if (len(containers) == 3 and all(container["State"]["Running"] for container in containers)
                and all(container["State"].get("Health", {}).get("Status") == "healthy"
                        for container in containers[:2])):
            break
        require(time.monotonic() < deadline, "Stage application containers did not become healthy.")
        time.sleep(2)
    with urllib.request.urlopen("http://127.0.0.1:8001/api/health", timeout=10) as response:
        health = json.load(response)
    require(health.get("status") == "ok" and health.get("checks", {}).get("postgresql") == "up"
            and health.get("checks", {}).get("redis") == "up", "Backend dependency health check failed.")
    with urllib.request.urlopen("http://127.0.0.1:5174/", timeout=10) as response:
        require(response.status == 200, "Frontend health check failed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--backup-dir", type=Path, required=True)
    args = parser.parse_args()
    require(args.env_file.is_file(), "Stage environment file does not exist.")
    require(not output(["git", "-C", str(ROOT), "status", "--porcelain"]), "Commit or preserve working-tree changes before deployment.")
    commit = output(["git", "-C", str(ROOT), "rev-parse", "HEAD"])
    context = inspect("context", "inspect", "default")
    require(len(context) == 1, "Docker context is ambiguous.")
    validate_daemon(context[0], json.loads(output(DOCKER + ["info", "--format", "{{json .}}"])))
    compose = DOCKER + ["compose", "--project-name", PROJECT, "--profile", "migrations", "--project-directory", str(ROOT),
                        "--env-file", str(args.env_file.resolve()), "-f", str(ROOT / "compose.yaml"),
                        "-f", str(ROOT / "compose.stage6a1.yaml")]
    validate_compose(json.loads(output(compose + ["config", "--format", "json"])))
    before = persistent_snapshot()
    for service in ("backend", "frontend", "queue", "mailpit"):
        validate_application(service, inspect("inspect", f"{PROJECT}-{service}-1")[0])
    verify_network()
    database = output(DOCKER + ["exec", before["postgres"], "sh", "-c",
                                'exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select current_database()"'])
    require(database == "veltra_stage6a1", "Connected PostgreSQL database is not Stage.")
    backup_dir = args.backup_dir.resolve()
    require(not backup_dir.is_relative_to(ROOT), "Backups must be stored outside the Git workspace.")
    backup(backup_dir, before["postgres"])
    run(compose + ["build", "backend", "frontend"])
    require(output(["git", "-C", str(ROOT), "rev-parse", "HEAD"]) == commit
            and not output(["git", "-C", str(ROOT), "status", "--porcelain"]),
            "Working tree or commit changed during the build.")
    applied = set(output(DOCKER + ["exec", before["postgres"], "sh", "-c",
                                   'exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select migration from migrations"']).splitlines())
    migrations = {path.stem for path in (ROOT / "backend/database/migrations").glob("*.php")}
    require(not applied - migrations, "The database contains migrations absent from this revision.")
    pending = sorted(migrations - applied)
    if pending:
        print("Pending migrations:", *pending, sep="\n  ", flush=True)
        require(input("Review migration effects and type MIGRUJ STAGE6A1 to continue: ") == "MIGRUJ STAGE6A1",
                "Migrations were not confirmed.")
    require(persistent_snapshot() == before, "Persistent Stage containers changed before migration.")
    run(compose + ["run", "--rm", "--no-deps", "migrate"])
    run(compose + ["up", "-d", "--no-deps", "backend", "queue", "frontend"])
    require(persistent_snapshot() == before, "Persistent Stage containers changed during deployment.")
    verify_health()
    verify_network()
    run(compose + ["ps"])
    print(f"Deployed commit: {commit}\nBackend: http://localhost:8001\nFrontend: http://localhost:5174", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, subprocess.CalledProcessError, KeyError, TypeError, ValueError, EOFError) as error:
        print(f"Stage deployment failed: {error}", file=sys.stderr)
        sys.exit(1)
