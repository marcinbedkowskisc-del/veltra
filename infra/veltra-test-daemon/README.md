# Isolated disposable-test Docker daemon

This rootless user-service template is intentionally not installed or started
by repository commands. It gives destructive infrastructure tests a separate
Docker data root and Unix socket; the primary and Stage Docker daemons must not
be used for those tests.

After separate owner authorization and installation of Docker Engine's rootless
prerequisites:

1. Copy `veltra-test-dockerd.service` to
   `~/.config/systemd/user/veltra-test-dockerd.service`.
2. Verify that `/usr/bin/dockerd-rootless.sh` exists and that the configured
   data and runtime directories are owned by the intended unprivileged user.
3. Install and start the user service, then create the Docker context
   `veltra-test-isolated` pointing to
   `unix://$XDG_RUNTIME_DIR/veltra-test-docker/docker.sock`.
4. Verify that `docker --context veltra-test-isolated info` reports the
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
