#!/usr/bin/env pwsh
# Builds the C# hgresume image with podman, then runs the HTTP-level test suite against a container.
# The test fixture starts/stops the container itself; this script just builds the image first.
param(
    [string]$Image = "hgresume-csharp:test",
    [string]$Port = "8034",
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
    $env:HGRESUME_PORT = $Port
    $env:HGRESUME_SKIP_BUILD = "1"   # already built above

    Write-Host "==> Running HTTP-level tests against the image" -ForegroundColor Cyan
    dotnet test test/HgResume.HttpTests/HgResume.HttpTests.csproj --logger "console;verbosity=normal"
    if ($LASTEXITCODE -ne 0) {
        throw "dotnet test failed with exit code $LASTEXITCODE"
    }
}
finally {
    Pop-Location
}
