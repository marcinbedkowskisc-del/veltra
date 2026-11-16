import json
import os
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

from scripts.compose_test_cleanup import TEST_DOCKER_CONFIG


ROOT = Path(__file__).resolve().parents[2]
CONTEXT = "veltra-test-isolated"
NAME = "veltra-test-stage6a1-directory-mount-probe"
DOCKER = ["docker", "--context", CONTEXT]
ENV = {"PATH": os.environ["PATH"], "HOME": str(Path.home()), "DOCKER_CONFIG": str(TEST_DOCKER_CONFIG)}
if "XDG_RUNTIME_DIR" in os.environ:
    ENV["XDG_RUNTIME_DIR"] = os.environ["XDG_RUNTIME_DIR"]


def docker(*args, check=True):
    return subprocess.run(DOCKER + list(args), env=ENV, check=check, capture_output=True, text=True)


@unittest.skipUnless(os.environ.get("VELTRA_TEST_ENV_FILE"), "Isolated TEST environment is required.")
class DirectoryMountIntegrationTest(unittest.TestCase):
    def test_running_container_sees_atomic_replacement_and_rejects_bad_files(self):
        env_file = os.environ.get("VELTRA_TEST_ENV_FILE")
        if not env_file:
            self.fail("Set VELTRA_TEST_ENV_FILE to the isolated TEST configuration.")
        subprocess.run(
            ["python3", str(ROOT / "scripts/compose_test_cleanup.py"), "--target", "application",
             "--env-file", env_file, "--verify-context"],
            env=ENV, check=True, capture_output=True, text=True,
        )
        self.assertEqual(docker("ps", "-aq", "--filter", f"name=^{NAME}$").stdout.strip(), "")

        with tempfile.TemporaryDirectory(prefix="veltra-stage6a1-mount-test-") as directory:
            root = Path(directory)
            root.chmod(0o755)
            current = root / "raw.json"
            replacement = root / "next.json"
            current.write_text('{"marker":"old"}', encoding="utf-8")
            current.chmod(0o444)
            process = subprocess.Popen(
                DOCKER + ["run", "--rm", "--name", NAME, "--network", "none",
                          "--mount", f"type=bind,src={root},dst=/run/veltra-stage6a1,readonly",
                          "--mount", f"type=bind,src={ROOT / 'backend/app/Domain/Installation/LocalStage6a1Instance.php'},"
                                     "dst=/var/www/html/app/Domain/Installation/LocalStage6a1Instance.php,readonly",
                          "--entrypoint", "sh", "veltra-test-backend:local", "-c", "sleep 30"],
                env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
            )
            try:
                for _ in range(50):
                    state = docker("inspect", "--format", "{{.State.Running}}", NAME, check=False)
                    if state.returncode == 0 and state.stdout.strip() == "true":
                        break
                    time.sleep(0.1)
                else:
                    self.fail("Disposable integration container did not start.")

                def read_identity():
                    return docker(
                        "exec", NAME, "php", "-r",
                        'require "/var/www/html/vendor/autoload.php"; '
                        '$f = new ReflectionMethod(App\\Domain\\Installation\\LocalStage6a1Instance::class, "rawIdentity"); '
                        'try { $x = $f->invoke(new App\\Domain\\Installation\\LocalStage6a1Instance); '
                        'echo $x["marker"] ?? "ABSENT"; } catch (Throwable $e) { echo "REJECTED"; exit(1); }',
                        check=False,
                    )

                self.assertEqual(read_identity().stdout, "old")
                replacement.write_text('{"marker":"new"}', encoding="utf-8")
                replacement.chmod(0o444)
                self.assertEqual(read_identity().stdout, "old")
                os.replace(replacement, current)
                self.assertEqual(read_identity().stdout, "new")

                hostname = docker("exec", NAME, "hostname").stdout.strip()
                identity = {
                    "compose_project": "veltra-stage6a1", "postgres_volume": "veltra-stage6a1_postgres-data",
                    "container_hostname": hostname, "expires_at": int(time.time()) + 3600,
                    "organization_id": "synthetic-organization", "user_id": "synthetic-user",
                    "database_oid": "12345", "server_address": "postgres", "mailpit_address": "mailpit",
                    "raw_integration_enabled": True,
                }

                def check_configuration():
                    return docker(
                        "exec", NAME, "php", "-r",
                        'require "/var/www/html/vendor/autoload.php"; '
                        '$app = require "/var/www/html/bootstrap/app.php"; '
                        '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                        '$app->detectEnvironment(fn () => "local"); '
                        'config(["database.default"=>"pgsql", "database.connections.pgsql.host"=>"postgres", '
                        '"database.connections.pgsql.port"=>5432, "database.connections.pgsql.url"=>null, '
                        '"app.url"=>"http://localhost:8001", "app.frontend_url"=>"http://localhost:5174", '
                        '"development.management_enabled"=>false, "mail.default"=>"smtp", '
                        '"mail.mailers.smtp.transport"=>"smtp", "mail.mailers.smtp.url"=>null, '
                        '"mail.mailers.smtp.scheme"=>null, "mail.mailers.smtp.host"=>"mailpit", '
                        '"mail.mailers.smtp.port"=>1025, "mail.mailers.smtp.username"=>null, '
                        '"mail.mailers.smtp.password"=>null]); '
                        '$i = new App\\Domain\\Installation\\LocalStage6a1Instance; '
                        'try { $v = $i->readIdentity("/run/veltra-stage6a1/raw.json"); '
                        '$i->assertConfiguration($v, is_file("/.dockerenv")); echo "ACCEPTED"; } '
                        'catch (Throwable $e) { echo "REJECTED"; exit(1); }',
                        check=False,
                    )

                def install_identity(value):
                    replacement.write_text(json.dumps(value), encoding="utf-8")
                    replacement.chmod(0o444)
                    os.replace(replacement, current)

                install_identity(identity)
                self.assertEqual(check_configuration().stdout, "ACCEPTED")
                for change in ({"expires_at": int(time.time()) - 1},
                               {"expires_at": int(time.time()) + 86500},
                               {"container_hostname": "wrong-container"}):
                    with self.subTest(change=change):
                        install_identity(identity | change)
                        self.assertEqual(check_configuration().stdout, "REJECTED")
                current.chmod(0o644)
                self.assertEqual(read_identity().stdout, "REJECTED")
                current.chmod(0o444)
                current.unlink()
                current.symlink_to("elsewhere")
                self.assertEqual(read_identity().stdout, "REJECTED")
                current.unlink()
                current.write_text("invalid", encoding="utf-8")
                current.chmod(0o444)
                self.assertEqual(read_identity().stdout, "REJECTED")
                current.chmod(0o644)
                current.write_text('{"marker":"' + "x" * 4096 + '"}', encoding="utf-8")
                current.chmod(0o444)
                self.assertEqual(read_identity().stdout, "REJECTED")
                root.chmod(0o777)
                self.assertEqual(read_identity().stdout, "REJECTED")
                root.chmod(0o755)
                denied = docker("exec", NAME, "sh", "-c", "touch /run/veltra-stage6a1/unauthorized",
                                check=False)
                self.assertNotEqual(denied.returncode, 0)
            finally:
                try:
                    process.communicate(timeout=40)
                except subprocess.TimeoutExpired:
                    self.fail("Disposable integration container did not exit on its own.")
                self.assertEqual(process.returncode, 0)
                self.assertEqual(docker("ps", "-aq", "--filter", f"name=^{NAME}$").stdout.strip(), "")


if __name__ == "__main__":
    unittest.main()
