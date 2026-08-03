# hgresume — C# / ASP.NET Core rewrite

A drop-in, wire-compatible reimplementation of the PHP `hgresume` API (see `../api`) that provides a
server-side REST API for **resumable Mercurial bundle transfer**. It is a faithful port of the
`dockerize` (`c905288`) PHP lineage: same endpoints, same `X-HgR-*` header protocol, same status-code
mapping, and the same shell-out-to-`hg` behaviour — so existing Chorus clients work unchanged.

Authentication is intentionally **not** implemented here; it is handled by the surrounding platform
(reverse proxy / gateway), matching how the container is deployed.

## Layout

- `src/HgResume.Api/` — the ASP.NET Core app (net10.0).
  - `RestDispatcher` — routes on the last path segment (`/api/v03/<method>`), binds query/body params
    (including `baseHashes[]`), and writes the `X-HgR-*` response contract.
  - `HgResumeApi` — push/pull/getRevisions/finish*/isAvailable, faithful to the PHP state machine.
  - `HgRunner` — shells out to `hg` (incoming/unbundle/bundle `-t v1`/log/branches/tip), parsing stdout verbatim.
  - `AsyncRunner` — runs long hg commands in the background and signals completion via a `.async_run`
    file, so a later HTTP request can observe the result (this is what makes transfers resumable).
  - `BundleHelper` — per-transaction state + metadata (stored as JSON).
- `test/HgResume.HttpTests/` — HTTP-level xUnit tests ported from `api/test/HgResumeApi_Test.php`. They
  drive the **running container** over HTTP (via a podman-managed fixture) and assert on the protocol.
- `Dockerfile` — multi-stage `dotnet/sdk:10.0` → `dotnet/aspnet:10.0`, installs `mercurial`.
  Listens on port 80 and exposes the same `/var/cache/hgresume` and `/var/vcs/public` volumes as the
  PHP image, so it is a drop-in replacement in the existing `docker-compose.yaml`.
- `.dockerignore` — keeps `test/` (including large fixture zips) out of the image build context.

## Configuration (environment variables)

| Variable | Default | Purpose |
|---|---|---|
| `HGRESUME_CACHE_PATH` | `/var/cache/hgresume` | bundle + transaction cache |
| `HGRESUME_REPO_PATHS` | `/var/vcs/public;/var/vcs/private` | `;`-separated repo search paths |
| `HGRESUME_MAINTENANCE_FILE` | `<cache>/maintenance_message.txt` | non-empty file ⇒ 503 maintenance mode |
| `ASPNETCORE_URLS` | `http://+:80` | listen address |

## Build & run

```bash
podman build -t hgresume-csharp:test -f Dockerfile .
podman run -d --name hgresume -p 8034:80 \
  -v /path/to/repos:/var/vcs/public \
  hgresume-csharp:test
curl -i http://localhost:8034/api/v03/isAvailable
```

## Tests

The HTTP-level suite builds the image, runs it in a container, seeds fixture repos by extracting
zips on the host and `podman cp`-ing them in, and exercises the protocol end-to-end:

```bash
./run-tests.sh          # or: pwsh ./run-tests.ps1
```

Useful env overrides: `HGRESUME_IMAGE`, `HGRESUME_PORT`, `HGRESUME_SKIP_BUILD`, and
`HGRESUME_BASE_URL` + `HGRESUME_CONTAINER` (to run the tests against an already-running container).

## CI

`.github/workflows/docker-image.yml` builds this image and, on a pull request, pushes it to GHCR tagged
`pr-<number>` (multi-arch amd64/arm64) so it can be pulled and tested in a real environment:

```bash
docker pull ghcr.io/sillsdev/hgresume:pr-<number>
```
