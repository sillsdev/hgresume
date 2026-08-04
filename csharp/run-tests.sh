#!/usr/bin/env bash
# Builds the C# hgresume image with podman, then runs the HTTP-level test suite against a container.
# The test fixture starts/stops the container itself; this script just builds the image first.
set -euo pipefail

IMAGE="${HGRESUME_IMAGE:-hgresume-csharp:test}"
PORT="${HGRESUME_PORT:-8034}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$here"

if [ "${1:-}" != "--skip-build" ]; then
  echo "==> Building image $IMAGE"
  podman build -t "$IMAGE" -f Dockerfile .
fi

export HGRESUME_IMAGE="$IMAGE"
export HGRESUME_PORT="$PORT"
export HGRESUME_SKIP_BUILD=1

echo "==> Running HTTP-level tests against the image"
dotnet test test/HgResume.HttpTests/HgResume.HttpTests.csproj --logger "console;verbosity=normal"
