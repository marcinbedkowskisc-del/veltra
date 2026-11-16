"""Read-only, fail-closed validation of Stage 6A.1 Docker relationships."""

from __future__ import annotations

import ipaddress
import re
from typing import Any


PROJECT = "veltra-stage6a1"
NETWORK = f"{PROJECT}_default"
POSTGRES_VOLUME = f"{PROJECT}_postgres-data"
REDIS_VOLUME = f"{PROJECT}_redis-data"
SERVICES = frozenset({"backend", "frontend", "postgres", "redis", "queue", "mailpit"})
CREDENTIAL_TARGET = "/run/veltra-stage6a1"
ID_PATTERN = re.compile(r"[0-9a-f]{64}\Z")
PUBLISHED_PORTS = {
    "backend": {"8000/tcp": "8001"},
    "frontend": {"5173/tcp": "5174"},
    "mailpit": {"8025/tcp": "8026"},
}


class SafetyError(RuntimeError):
    pass


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise SafetyError(message)


def _published_ports(container: dict[str, Any], service: str) -> None:
    configured = container["HostConfig"]["PortBindings"]
    runtime = container["NetworkSettings"]["Ports"]
    exposed = container["Config"]["ExposedPorts"]
    _require(isinstance(configured, dict) and isinstance(runtime, dict) and isinstance(exposed, dict),
             "Docker port information is missing or ambiguous.")

    def bindings(ports: dict[str, Any], allow_unpublished: bool) -> dict[str, set[tuple[str, str]]]:
        result: dict[str, set[tuple[str, str]]] = {}
        for port, entries in ports.items():
            _require(isinstance(port, str) and port in exposed, "Unexpected exposed port.")
            if entries is None and allow_unpublished:
                continue
            _require(isinstance(entries, list) and bool(entries), "Ambiguous port bindings.")
            values: set[tuple[str, str]] = set()
            for entry in entries:
                address = entry["HostIp"]
                host_port = entry["HostPort"]
                _require(isinstance(address, str) and ipaddress.ip_address(address).is_loopback,
                         "Published port is not bound to loopback.")
                _require(isinstance(host_port, str) and bool(re.fullmatch(r"[1-9][0-9]{0,4}", host_port))
                         and int(host_port) <= 65535, "Invalid published port.")
                values.add((address, host_port))
            _require(len(values) == len(entries), "Duplicate port binding.")
            result[port] = values
        return result

    declared = bindings(configured, False)
    actual = bindings(runtime, True)
    _require(declared == actual, "Docker configured and runtime port bindings differ.")
    expected = PUBLISHED_PORTS.get(service, {})
    _require(set(actual) == set(expected)
             and all(len(actual[port]) == 1 and next(iter(actual[port]))[1] == host_port
                     for port, host_port in expected.items()), "Unexpected published Stage ports.")


def validate_snapshot(snapshot: dict[str, Any], credential_directory: str, expected_daemon_id: str) -> dict[str, str]:
    """Validate actual relationships, not only names or Compose labels."""
    try:
        context = snapshot["context"]
        info = snapshot["info"]
        _require(context["Name"] == "default", "Unexpected Docker context.")
        _require(context["Endpoints"]["docker"]["Host"] == "unix:///var/run/docker.sock",
                 "Unexpected Docker Desktop endpoint.")
        _require(info["ID"] == expected_daemon_id and bool(expected_daemon_id),
                 "Docker daemon ID does not match the operator's expected ID.")
        _require(info["Name"] == "docker-desktop" and info["DockerRootDir"] == "/var/lib/docker",
                 "Docker daemon is not the expected Docker Desktop.")
        _require(not any("rootless" in item.lower() for item in info["SecurityOptions"]),
                 "Rootless TEST daemon is not a Stage daemon.")

        containers = snapshot["containers"]
        _require(len(containers) == len(SERVICES), "Unexpected number of Stage containers.")
        services: dict[str, dict[str, Any]] = {}
        for container in containers:
            labels = container["Config"]["Labels"] or {}
            service = labels.get("com.docker.compose.service")
            _require(labels.get("com.docker.compose.project") == PROJECT and service in SERVICES,
                     "Unexpected Stage container project or service.")
            _require(service not in services and container["Name"] == f"/{PROJECT}-{service}-1",
                     "Ambiguous Stage container identity.")
            _require(bool(ID_PATTERN.fullmatch(container["Id"])) and container["State"]["Running"],
                     "Stage container is not uniquely identified and running.")
            if service != "queue":
                _require(container["State"]["Health"]["Status"] == "healthy", "Stage service is unhealthy.")
            host = container["HostConfig"]
            _require(not host["Privileged"] and not host["Links"] and not host["VolumesFrom"],
                     "Privileged, linked, or inherited Stage container.")
            _require(not host["PublishAllPorts"], "Publish-all-ports is not allowed.")
            _require(not host.get("ExtraHosts") and not host.get("Dns") and not host.get("DnsSearch")
                     and not host.get("CapAdd") and host.get("PidMode") not in {"host"}
                     and host.get("IpcMode") not in {"host"},
                     "Unexpected host, DNS, capability, or namespace configuration.")
            _require(host["NetworkMode"] == NETWORK and set(container["NetworkSettings"]["Networks"]) == {NETWORK},
                     "Stage container is attached to an unexpected network.")
            services[service] = container
        _require(set(services) == SERVICES, "Missing Stage service.")
        ids = {item["Id"] for item in services.values()}
        _require(len(ids) == len(SERVICES), "Duplicate Stage container IDs.")

        network = snapshot["network"]
        labels = network["Labels"] or {}
        _require(network["Name"] == NETWORK and labels.get("com.docker.compose.project") == PROJECT
                 and labels.get("com.docker.compose.network") == "default" and network["Driver"] == "bridge"
                 and bool(ID_PATTERN.fullmatch(network["Id"])),
                 "Unexpected Stage network.")
        _require(set(network["Containers"] or {}) == ids, "Stage network is shared or incomplete.")
        endpoints: set[str] = set()
        addresses: set[str] = set()
        for container in services.values():
            settings = container["NetworkSettings"]["Networks"][NETWORK]
            _require(settings["NetworkID"] == network["Id"], "Stage network ID changed.")
            endpoint = network["Containers"][container["Id"]]
            address = ipaddress.ip_address(settings["IPAddress"])
            _require(address.version == 4 and not address.is_unspecified
                     and endpoint["Name"] == container["Name"].removeprefix("/")
                     and endpoint["EndpointID"] == settings["EndpointID"]
                     and bool(ID_PATTERN.fullmatch(settings["EndpointID"]))
                     and ipaddress.ip_interface(endpoint["IPv4Address"]).ip == address,
                     "Stage network endpoint is inconsistent.")
            endpoints.add(settings["EndpointID"])
            addresses.add(str(address))
        _require(len(endpoints) == len(ids) and len(addresses) == len(ids), "Ambiguous Stage network endpoints.")
        for service, container in services.items():
            _published_ports(container, service)

        for service, name in (("postgres", POSTGRES_VOLUME), ("redis", REDIS_VOLUME)):
            volume = snapshot[f"{service}_volume"]
            labels = volume["Labels"] or {}
            _require(volume["Name"] == name and volume["Driver"] == "local" and not volume["Options"]
                     and labels.get("com.docker.compose.project") == PROJECT
                     and labels.get("com.docker.compose.volume") == f"{service}-data",
                     "Unexpected Stage volume identity or backing storage.")
            _require(set(snapshot[f"{service}_volume_users"]) == {services[service]["Id"]},
                     "Stage volume is shared or its owner is ambiguous.")

        allowed_volumes = {"postgres": {POSTGRES_VOLUME}, "redis": {REDIS_VOLUME}}
        for service, container in services.items():
            _require(all(mount["Type"] in {"bind", "volume"} for mount in container["Mounts"]),
                     "Unexpected Stage mount type.")
            volumes = {mount["Name"] for mount in container["Mounts"] if mount["Type"] == "volume"}
            _require(volumes == allowed_volumes.get(service, set()), "Unexpected or cross-environment volume.")
        postgres_mounts = services["postgres"]["Mounts"]
        _require(any(m["Type"] == "volume" and m.get("Name") == POSTGRES_VOLUME
                     and m["Destination"] == "/var/lib/postgresql/data" and m["RW"] for m in postgres_mounts),
                 "PostgreSQL volume is not mounted at its expected target.")
        _require(any(m["Type"] == "bind" and m["Destination"] == "/docker-entrypoint-initdb.d/10-create-runtime-role.sh"
                     and not m["RW"] for m in postgres_mounts), "Missing PostgreSQL init-script mount.")
        _require(any(m["Type"] == "volume" and m.get("Name") == REDIS_VOLUME
                     and m["Destination"] == "/data" and m["RW"] for m in services["redis"]["Mounts"]),
                 "Redis volume is not mounted at its expected target.")
        for service, container in services.items():
            binds = [mount for mount in container["Mounts"] if mount["Type"] == "bind"]
            if service == "backend":
                _require(len(binds) == 1 and binds[0]["Source"] == credential_directory
                         and binds[0]["Destination"] == CREDENTIAL_TARGET and not binds[0]["RW"],
                         "Backend credential directory mount does not match.")
            elif service == "postgres":
                _require(len(binds) == 1, "Unexpected PostgreSQL bind mount.")
            else:
                _require(not binds, "Unexpected bind mount on a Stage service.")
        return {service: container["Id"] for service, container in services.items()}
    except SafetyError:
        raise
    except (KeyError, TypeError, ValueError, AttributeError) as exc:
        raise SafetyError("Docker inspection data is incomplete or invalid.") from exc
