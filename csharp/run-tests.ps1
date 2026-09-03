#!/usr/bin/env pwsh
# Builds the C# hgresume image with podman, then runs the integration test suite against a container.
# The test fixture (Testcontainers) starts/stops the container itself; this script just builds the
# image first. Testcontainers talks to the Docker Engine API directly, so this only works if podman's
# API socket is exposed and DOCKER_HOST points at it (Docker Desktop/Engine need no extra setup).
param(
    [string]$Image = "hgresume-csharp:test",
    [switch]$SkipBuild
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $here
try {
    if (-not $SkipBuild) {
        Write-Host "==> Building image $Image" -ForegroundColor Cyan
        podman build -t $Image -f Dockerfile .
        if ($LASTEXITCODE -ne 0) {
            throw "podman build failed with exit code $LASTEXITCODE"
        }
    }

    $env:HGRESUME_IMAGE = $Image
    $env:HGRESUME_SKIP_BUILD = "1"   # already built above

    Write-Host "==> Running integration tests against the image" -ForegroundColor Cyan
    dotnet test test/HgResume.IntegrationTests/HgResume.IntegrationTests.csproj --logger "console;verbosity=normal"
    if ($LASTEXITCODE -ne 0) {
        throw "dotnet test failed with exit code $LASTEXITCODE"
    }
}
finally {
    Pop-Location
}
