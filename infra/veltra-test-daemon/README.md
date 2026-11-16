# Isolated disposable-test Docker daemon

This rootless user-service template is intentionally not installed or started
by repository commands. It gives destructive infrastructure tests a separate
Docker data root and Unix socket; the primary and Stage Docker daemons must not
be used for those tests.

After separate owner authorization and installation of Docker Engine's rootless
prerequisites:

1. Install the matching official Docker static Engine archive into the
   private binary directory. Do not install `docker-ce` or `docker-ce-cli` in
   this WSL distribution because `/usr/bin/docker` is supplied by Docker
   Desktop:

   ```sh
   set -eu
   archive=$(mktemp /tmp/veltra-docker-29.9.2.XXXXXX.tgz)
   trap 'rm -f -- "$archive"' EXIT HUP INT TERM
   install -d -m 700 ~/.local/lib/veltra-test-docker/bin
   curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-29.9.2.tgz \
     -o "$archive"
   tar -tzf "$archive" >/dev/null
   tar -xzf "$archive" --strip-components=1 \
     -C ~/.local/lib/veltra-test-docker/bin
   ```

2. Copy `veltra-test-dockerd.service` to
   `~/.config/systemd/user/veltra-test-dockerd.service`.
   The service uses the private binary directory for `dockerd`, the Docker
   rootless helper package for `rootlesskit`, and a separate TEST data/runtime
   root.
3. Verify that `/usr/bin/dockerd-rootless.sh` exists and that the configured
   data and runtime directories are owned by the intended unprivileged user.
4. Create a private Docker CLI configuration and register the context there:

   ```sh
   install -d -m 700 ~/.config/veltra-test
   install -d -m 700 ~/.config/veltra-test/docker
   DOCKER_CONFIG=~/.config/veltra-test/docker docker context create \
     veltra-test-isolated \
     --docker "host=unix://$XDG_RUNTIME_DIR/veltra-test-docker/docker.sock"
   ```

   Do not switch the global Docker context.
5. Start only the dedicated user service with
   `systemctl --user daemon-reload` and
   `systemctl --user start veltra-test-dockerd.service`.
6. Verify that `DOCKER_CONFIG=~/.config/veltra-test/docker docker --context veltra-test-isolated info` reports the
   `com.veltra.daemon-role=disposable-test` daemon label and a data root under
   `~/.local/share/veltra-test-docker`.

Do not point this context at Docker Desktop, `default`, or the Stage daemon.
The cleanup wrapper checks both this context identity and the daemon label.
This template does not migrate or access any existing Docker data.

The former Stage session-state test overlays are marked `legacy-disabled`
because they depended on the Stage Compose project. Do not activate that
profile. Converting Stage-specific tests to a standalone test-only Compose
project requires controlled follow-up; the Stage database and volumes were not
changed here.
