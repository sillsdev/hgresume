# hgresume

ASP.NET Core server for **resumable Mercurial bundle transfer**. It speaks the same endpoints,
`X-HgR-*` header protocol, and status-code mapping as the historical PHP API, so existing Chorus
clients work unchanged.

Authentication is intentionally **not** implemented here; it is handled by the surrounding platform
(reverse proxy / gateway).

The original PHP implementation is no longer in this tree. The last commit that still contains it is
[`3f2b3c7`](https://github.com/sillsdev/hgresume/tree/3f2b3c7402408de3a1f0f8a36b21586df929b898)
([`api/`](https://github.com/sillsdev/hgresume/tree/3f2b3c7402408de3a1f0f8a36b21586df929b898/api)).

## Layout

- `csharp/src/HgResume.Api/` — the ASP.NET Core app (net10.0).
  - `RestDispatcher` — routes on the last path segment (`/api/v03/<method>`), binds query/body params
    (including `baseHashes[]`), and writes the `X-HgR-*` response contract.
  - `HgResumeApi` — push/pull/getRevisions/finish*/isAvailable.
  - `HgRunner` — shells out to `hg` (incoming/unbundle/bundle `-t v1`/log/branches/tip).
  - `AsyncRunner` — runs long hg commands in the background and signals completion via a `.async_run`
    file, so a later HTTP request can observe the result (this is what makes transfers resumable).
  - `BundleHelper` — per-transaction state + metadata (stored as JSON).
- `csharp/test/HgResume.HttpTests/` — HTTP-level xUnit tests. They drive the **running container**
  over HTTP (via a podman-managed fixture) and assert on the protocol.
- `csharp/Dockerfile` — multi-stage `dotnet/sdk:10.0` → `dotnet/aspnet:10.0`, installs `mercurial`.
  Listens on port 80 and exposes `/var/cache/hgresume` and `/var/vcs/public`.
- `docker-compose.yaml` — local run against a host Mercurial repo tree.

## Configuration (environment variables)

| Variable | Default | Purpose |
|---|---|---|
| `HGRESUME_CACHE_PATH` | `/var/cache/hgresume` | bundle + transaction cache |
| `HGRESUME_REPO_PATHS` | `/var/vcs/public;/var/vcs/private` | `;`-separated repo search paths |
| `HGRESUME_MAINTENANCE_FILE` | `<cache>/maintenance_message.txt` | non-empty file ⇒ 503 maintenance mode |
| `HGRESUME_MAX_REQUEST_BODY_SIZE` | `30000000` | max request body bytes (Kestrel; raise for whole-bundle pushes) |
| `ASPNETCORE_URLS` | `http://+:80` | listen address |

## Build & run

```bash
docker compose up --build
# or:
podman build -t hgresume:test -f csharp/Dockerfile csharp
podman run -d --name hgresume -p 8034:80 \
  -v /path/to/repos:/var/vcs/public \
  hgresume:test
curl -i http://localhost:8034/api/v03/isAvailable
```

## Tests

The HTTP-level suite builds the image, runs it in a container, seeds fixture repos, and exercises
the protocol end-to-end:

```bash
cd csharp
./run-tests.sh          # or: pwsh ./run-tests.ps1
```

Useful env overrides: `HGRESUME_IMAGE`, `HGRESUME_PORT`, `HGRESUME_SKIP_BUILD`, and
`HGRESUME_BASE_URL` + `HGRESUME_CONTAINER` (to run the tests against an already-running container).

## CI

`.github/workflows/docker-image.yml` builds the C# image on push to `master` and, on a pull request,
pushes it to GHCR tagged `pr-<number>` (multi-arch amd64/arm64):

```bash
docker pull ghcr.io/sillsdev/hgresume:pr-<number>
```

## Maintenance mode

To suspend the API, place a text file at `HGRESUME_MAINTENANCE_FILE` (default
`/var/cache/hgresume/maintenance_message.txt`) with an explanation. Clients receive that message
with HTTP 503.
