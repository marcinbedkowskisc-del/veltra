import hashlib
import json
import os
import subprocess
import tempfile
import time
import unittest
import uuid
from pathlib import Path

from scripts.compose_test_cleanup import TEST_DOCKER_CONFIG
from scripts.stage6a1_renew import CANDIDATE_PHP, VERIFY_PHP


ROOT = Path(__file__).resolve().parents[2]
ENV = {"PATH": os.environ["PATH"], "HOME": str(Path.home()), "DOCKER_CONFIG": str(TEST_DOCKER_CONFIG)}
if "XDG_RUNTIME_DIR" in os.environ:
    ENV["XDG_RUNTIME_DIR"] = os.environ["XDG_RUNTIME_DIR"]
DOCKER = ["docker", "--context", "veltra-test-isolated"]


def docker(*args, check=True, input_text=None):
    return subprocess.run(DOCKER + list(args), env=ENV, check=check, input=input_text,
                          capture_output=True, text=True)


@unittest.skipUnless(os.environ.get("VELTRA_TEST_ENV_FILE"), "Isolated TEST environment is required.")
class GuardIntegrationTest(unittest.TestCase):
    def test_real_guard_with_disposable_postgres_and_atomic_directory_replacement(self):
        subprocess.run(
            ["python3", str(ROOT / "scripts/compose_test_cleanup.py"), "--target", "application",
             "--env-file", os.environ["VELTRA_TEST_ENV_FILE"], "--verify-context"],
            env=ENV, check=True, capture_output=True, text=True,
        )
        suffix = uuid.uuid4().hex
        network = f"veltra-test-stage6a3-guard-{suffix}"
        postgres_name = f"veltra-test-stage6a3-postgres-{suffix}"
        backend_name = f"veltra-test-stage6a3-backend-{suffix}"
        containers = []
        network_id = None
        with tempfile.TemporaryDirectory(prefix="veltra-stage6a3-guard-") as directory:
            root = Path(directory)
            root.chmod(0o755)
            try:
                network_id = docker("network", "create", "--internal", network).stdout.strip()
                postgres = docker(
                    "run", "--detach", "--rm", "--name", postgres_name, "--network", network_id,
                    "--network-alias", "postgres", "--tmpfs", "/var/lib/postgresql/data",
                    "-e", "POSTGRES_DB=veltra_stage6a1", "-e", "POSTGRES_USER=postgres",
                    "-e", "POSTGRES_PASSWORD=synthetic-test-password", "postgres:17-alpine",
                ).stdout.strip()
                containers.append(postgres)
                for _ in range(90):
                    ready = docker("exec", postgres, "pg_isready", "-U", "postgres",
                                   "-d", "veltra_stage6a1", check=False)
                    if ready.returncode == 0:
                        break
                    time.sleep(0.5)
                else:
                    self.fail("Disposable PostgreSQL did not become ready.")
                sql = """
                    CREATE ROLE veltra_app LOGIN PASSWORD 'synthetic-app-password';
                    CREATE TABLE organizations (id text PRIMARY KEY, is_active boolean, authorization_initialized boolean);
                    CREATE TABLE users (id text PRIMARY KEY, organization_id text, is_initial_identity boolean,
                                        is_active boolean, email_verified_at timestamptz);
                    INSERT INTO organizations VALUES ('synthetic-org', true, true);
                    INSERT INTO users VALUES ('synthetic-user', 'synthetic-org', true, true, now());
                    GRANT SELECT ON organizations, users TO veltra_app;
                """
                docker("exec", "-i", postgres, "psql", "-v", "ON_ERROR_STOP=1",
                       "-U", "postgres", "-d", "veltra_stage6a1", input_text=sql)
                backend_args = [
                    "run", "--detach", "--rm", "--name", backend_name, "--network", network_id,
                    "--network-alias", "mailpit",
                    "--mount", f"type=bind,src={root},dst=/run/veltra-stage6a1,readonly",
                    "--mount", f"type=bind,src={ROOT / 'backend/app/Domain/Installation/LocalStage6a1Instance.php'},"
                               "dst=/var/www/html/app/Domain/Installation/LocalStage6a1Instance.php,readonly",
                ]
                environment = {
                    "APP_ENV": "local", "APP_URL": "http://localhost:8001",
                    "FRONTEND_URL": "http://localhost:5174", "DEV_MANAGEMENT_ENABLED": "false",
                    "DB_CONNECTION": "pgsql", "DB_HOST": "postgres", "DB_PORT": "5432",
                    "DB_DATABASE": "veltra_stage6a1", "DB_USERNAME": "veltra_app",
                    "DB_PASSWORD": "synthetic-app-password", "REDIS_HOST": "redis",
                    "MAIL_MAILER": "smtp", "MAIL_HOST": "mailpit", "MAIL_PORT": "1025",
                }
                for key, value in environment.items():
                    backend_args.extend(["-e", f"{key}={value}"])
                backend_args.extend(["--entrypoint", "sh", "veltra-test-backend:local", "-c", "sleep 120"])
                backend = docker(*backend_args).stdout.strip()
                containers.append(backend)
                self.assertEqual(docker("inspect", "--format", "{{json .Mounts}}", postgres).stdout.strip(), "[]")
                identity = json.loads(docker("exec", backend, "php", "-r", CANDIDATE_PHP).stdout)

                def install(value, use_protocol=False):
                    data = json.dumps(value, separators=(",", ":"), ensure_ascii=False).encode()
                    if use_protocol:
                        script = (
                            "import os,sys; from scripts.stage6a1_renew import publish; "
                            "fd=os.open(sys.argv[1], os.O_RDONLY | os.O_DIRECTORY); "
                            "publish(fd,sys.stdin.buffer.read()); os.close(fd)"
                        )
                        subprocess.run(
                            ["unshare", "-Ur", "python3", "-c", script, str(root)], cwd=ROOT,
                            env={**ENV, "PYTHONDONTWRITEBYTECODE": "1"}, input=data,
                            check=True, capture_output=True,
                        )
                    else:
                        candidate = root / "synthetic-next"
                        candidate.write_bytes(data)
                        candidate.chmod(0o444)
                        os.replace(candidate, root / "raw.json")
                    return hashlib.sha256(data).hexdigest()

                def verify():
                    return docker("exec", backend, "php", "-r", VERIFY_PHP, check=False)

                orphan = root / (".stage6a1-candidate-" + "a" * 32)
                orphan.write_text(json.dumps(identity), encoding="utf-8")
                orphan.chmod(0o444)
                self.assertEqual(verify().returncode, 1)
                orphan.unlink()
                expected = install(identity, use_protocol=True)
                self.assertEqual(verify().stdout.strip(), expected)
                identity["expires_at"] += 60
                expected = install(identity, use_protocol=True)
                self.assertEqual(verify().stdout.strip(), expected)
                for change in ({"expires_at": int(time.time()) - 1},
                               {"container_hostname": "wrong-container"},
                               {"organization_id": "other-synthetic-org"}):
                    with self.subTest(change=change):
                        install(identity | change)
                        self.assertNotEqual(verify().returncode, 0)
                install(identity)
                active = root / "raw.json"
                active.chmod(0o644)
                self.assertNotEqual(verify().returncode, 0)
                active.chmod(0o444)
                self.assertEqual(verify().returncode, 0)
                docker("run", "--rm", "--network", "none", "--user", "0:0",
                       "--mount", f"type=bind,src={root},dst=/credentials",
                       "--entrypoint", "chown", "veltra-test-backend:local", "123:123", "/credentials/raw.json")
                self.assertNotEqual(verify().returncode, 0)
                active.unlink()
                active.symlink_to("missing")
                self.assertNotEqual(verify().returncode, 0)
                active.unlink()
                active.write_bytes(b"x" * 4097)
                active.chmod(0o444)
                self.assertNotEqual(verify().returncode, 0)
            finally:
                for container in reversed(containers):
                    docker("stop", "--time", "3", container)
                if network_id:
                    docker("network", "rm", network_id)


if __name__ == "__main__":
    unittest.main()
