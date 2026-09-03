#!/usr/bin/env bash
# Builds the C# hgresume image with podman, then runs the integration test suite against a container.
# The test fixture (Testcontainers) starts/stops the container itself; this script just builds the
# image first. Testcontainers talks to the Docker Engine API directly, so this only works if podman's
# API socket is exposed and DOCKER_HOST points at it (Docker Desktop/Engine need no extra setup).
set -euo pipefail

IMAGE="${HGRESUME_IMAGE:-hgresume-csharp:test}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$here"

if [ "${1:-}" != "--skip-build" ]; then
  echo "==> Building image $IMAGE"
  podman build -t "$IMAGE" -f Dockerfile .
fi

export HGRESUME_IMAGE="$IMAGE"
export HGRESUME_SKIP_BUILD=1

echo "==> Running integration tests against the image"
dotnet test test/HgResume.IntegrationTests/HgResume.IntegrationTests.csproj --logger "console;verbosity=normal"
