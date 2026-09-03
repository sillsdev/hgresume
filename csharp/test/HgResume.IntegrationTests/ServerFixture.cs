using System.IO.Compression;
using DotNet.Testcontainers.Builders;
using DotNet.Testcontainers.Containers;
using DotNet.Testcontainers.Images;
using Xunit;

namespace HgResume.IntegrationTests;

/// <summary>
/// Shared fixture that runs the hgresume C# image in a container (via Testcontainers) and drives it
/// over HTTP. One container serves both test styles in this project: the HTTP-level wire-protocol
/// tests (via <see cref="Client"/>, an <see cref="ApiClient"/>) and the end-to-end Chorus send/receive
/// tests (via <see cref="InitServerRepo"/>/<see cref="GetServerTip"/> and <see cref="HostPort"/>).
///
/// Repos are seeded and torn down through the container's own <c>/api/manage/*</c> endpoints (see
/// <see cref="ManageSecret"/>), so nothing needs host-side access into the container's filesystem. The
/// one exception is <see cref="MiscFacts.GetRevisions_SubDir_Works"/>, which seeds a repo at a path the
/// manage API's <c>ProjectCode</c> validation deliberately rejects (it contains "/") — that case, plus
/// <see cref="AddAndCommit"/> and the maintenance-file helpers (none of which are "manage" operations),
/// still go through <see cref="IContainer.ExecAsync"/>/<see cref="IContainer.CopyAsync(DirectoryInfo, string, uint, uint, DotNet.Testcontainers.Configurations.UnixFileModes, CancellationToken)"/>,
/// which are unavailable when reusing an external server (see HGRESUME_BASE_URL below) and throw.
///
/// Testcontainers talks to the Docker Engine API directly rather than shelling out to a CLI, so a
/// plain podman-CLI-only setup is not enough on its own — podman needs to expose a Docker-API-compatible
/// socket (e.g. via `podman machine`) with `DOCKER_HOST` pointed at it. Docker Desktop/Docker Engine
/// work out of the box.
///
/// Environment overrides:
///   HGRESUME_IMAGE      image to run/build (default "hgresume-csharp:test")
///   HGRESUME_SKIP_BUILD if set, do not build the image (assume it exists)
///   HGRESUME_BASE_URL   reuse an already-running server at this URL (with HGRESUME_CONTAINER)
///   HGRESUME_CONTAINER  name of the already-running container (informational only in this mode)
///   HGRESUME_KEEP       if set, do not stop/remove the container on teardown
/// </summary>
public sealed class ServerFixture : IAsyncLifetime
{
    private const ushort ContainerPort = 80;

    // Shared with the HGRESUME_MANAGE_SECRET environment variable passed to the container below.
    private const string ManageSecret = "test-secret";

    private readonly string _image = Env("HGRESUME_IMAGE", "hgresume-csharp:test");
    private readonly string _dataDir = Path.Combine(AppContext.BaseDirectory, "data");
    // Set HGRESUME_REPO_OWNER (e.g. "www-data") to chown seeded repos when the server runs as a
    // non-root user (the PHP/Apache reference image). Empty = leave ownership as-is (C# runs as root).
    private readonly string _repoOwner = Env("HGRESUME_REPO_OWNER", "");
    // Where the server looks for the maintenance file. C# default is under the cache dir; the PHP app
    // looks in its src dir (SourcePath . "/maintenance_message.txt").
    private readonly string _maintPath = Env("HGRESUME_MAINT_PATH", "/var/cache/hgresume/maintenance_message.txt");

    private IContainer? _container;
    private HttpClient? _manageHttp;

    public string ContainerName { get; private set; } = "";
    public string BaseUrl { get; private set; } = "";
    public ApiClient Client { get; private set; } = default!;

    /// <summary>host:port with no scheme, e.g. for building a Chorus repo URL as http://{HostPort}/{code}.</summary>
    public string HostPort => BaseUrl.Replace("http://", "").Replace("https://", "");

    private HttpClient ManageHttp => _manageHttp ??= new HttpClient
    {
        BaseAddress = new Uri(BaseUrl),
        Timeout = TimeSpan.FromSeconds(120),
        DefaultRequestHeaders = { { "X-Manage-Secret", ManageSecret } },
    };

    public async Task InitializeAsync()
    {
        string? reuseUrl = Environment.GetEnvironmentVariable("HGRESUME_BASE_URL");
        if (!string.IsNullOrWhiteSpace(reuseUrl))
        {
            BaseUrl = reuseUrl;
            ContainerName = Env("HGRESUME_CONTAINER", "hgresumable");
            Client = new ApiClient(BaseUrl);
            return;
        }

        bool keep = Environment.GetEnvironmentVariable("HGRESUME_KEEP") is not null;
        ContainerName = "hgresume-test-" + Environment.ProcessId;

        IImage image;
        if (Environment.GetEnvironmentVariable("HGRESUME_SKIP_BUILD") is null)
        {
            IFutureDockerImage futureImage = new ImageFromDockerfileBuilder()
                .WithName(_image)
                .WithDockerfileDirectory(FindContextDir())
                .WithDockerfile("Dockerfile")
                .Build();
            await futureImage.CreateAsync();
            image = futureImage;
        }
        else
        {
            image = new DockerImage(_image);
        }

        _container = new ContainerBuilder(image)
            .WithName(ContainerName)
            .WithPortBinding(ContainerPort, assignRandomHostPort: true)
            .WithEnvironment("HGRESUME_MANAGE_SECRET", ManageSecret)
            .WithCleanUp(!keep)
            .WithWaitStrategy(Wait.ForUnixContainer()
                .UntilHttpRequestIsSucceeded(r => r.ForPort(ContainerPort).ForPath("/api/v03/isAvailable")))
            .Build();
        await _container.StartAsync();
        BaseUrl = $"http://{_container.Hostname}:{_container.GetMappedPublicPort(ContainerPort)}";
        Client = new ApiClient(BaseUrl);
    }

    public async Task DisposeAsync()
    {
        _manageHttp?.Dispose();
        if (_container is null) return;

        if (Environment.GetEnvironmentVariable("HGRESUME_KEEP") is not null) return;

        var (stdout, stderr) = await _container.GetLogsAsync();
        Console.WriteLine("--- container stdout ---\n" + stdout);
        Console.WriteLine("--- container stderr ---\n" + stderr);
        await _container.DisposeAsync();
    }

    // ---- repo/maintenance seeding ---------------------------------------------------------------

    /// <summary>
    /// Seeds /var/vcs/public/&lt;repoId&gt; from a fixture repo zip. Returns the repoId. Goes through
    /// POST /api/manage/repos/{code}/finish-reset (the zip becomes the repo's .hg folder) unless
    /// repoId isn't a valid ProjectCode (e.g. contains "/"; see
    /// <see cref="MiscFacts.GetRevisions_SubDir_Works"/>) or HGRESUME_REPO_OWNER is set (a non-root
    /// image with no manage API), in which case it falls back to extracting on the host and copying
    /// the result into the container directly.
    /// </summary>
    public string SeedRepo(string zipName, string? repoId = null)
    {
        repoId ??= Path.GetFileNameWithoutExtension(zipName);
        string localZip = Path.Combine(_dataDir, zipName);
        if (!File.Exists(localZip)) throw new FileNotFoundException($"fixture not found: {localZip}");

        if (repoId.Contains('/') || !string.IsNullOrEmpty(_repoOwner))
        {
            SeedRepoViaFilesystem(localZip, repoId);
            return repoId;
        }

        using var content = new ByteArrayContent(File.ReadAllBytes(localZip));
        using var resp = ManageHttp.PostAsync($"/api/manage/repos/{repoId}/finish-reset", content)
            .GetAwaiter().GetResult();
        if (!resp.IsSuccessStatusCode)
        {
            throw new Exception($"finish-reset for {repoId} failed: {(int)resp.StatusCode} {resp.ReasonPhrase}");
        }
        return repoId;
    }

    private void SeedRepoViaFilesystem(string localZip, string repoId)
    {
        string extractDir = Path.Combine(Path.GetTempPath(), "hgresume-seed-" + Guid.NewGuid().ToString("N"));
        try
        {
            Directory.CreateDirectory(extractDir);
            ZipFile.ExtractToDirectory(localZip, extractDir);

            Exec($"rm -rf /var/vcs/public/{repoId}");
            RequireContainer().CopyAsync(new DirectoryInfo(extractDir), $"/var/vcs/public/{repoId}")
                .GetAwaiter().GetResult();
            if (!string.IsNullOrEmpty(_repoOwner))
            {
                Exec($"chown -R {_repoOwner}:{_repoOwner} /var/vcs/public/{repoId}");
            }
        }
        finally
        {
            try { Directory.Delete(extractDir, recursive: true); }
            catch { /* best-effort temp cleanup */ }
        }
    }

    public void RemoveRepo(string repoId)
    {
        if (repoId.Contains('/'))
        {
            Exec($"rm -rf /var/vcs/public/{repoId}");
            return;
        }

        using var resp = ManageHttp.DeleteAsync($"/api/manage/repos/{repoId}").GetAwaiter().GetResult();
        if (!resp.IsSuccessStatusCode)
        {
            throw new Exception($"delete repo {repoId} failed: {(int)resp.StatusCode}");
        }
    }

    /// <summary>Creates an empty hg repo on the server for the given project code, via /api/manage.</summary>
    public void InitServerRepo(string code)
    {
        RemoveRepo(code);
        using var resp = ManageHttp.PostAsync($"/api/manage/repos/{code}", null).GetAwaiter().GetResult();
        if (!resp.IsSuccessStatusCode)
        {
            throw new Exception($"init repo {code} failed: {(int)resp.StatusCode}");
        }
    }

    /// <summary>Returns the server's revision list ("hash:branch|...") for a repo, via the HTTP API.</summary>
    public Task<string> GetServerRevisions(string code, int quantity = 50) =>
        Task.Run(() => Client.GetRevisions(code, 0, quantity).Text);

    /// <summary>Server tip hash (first revision), or "" if the repo is empty.</summary>
    public async Task<string> GetServerTip(string code)
    {
        var revs = await GetServerRevisions(code, 1);
        // format: "<hash>:<branch>|..."; empty repo returns "0:"
        var first = revs.Split('|').FirstOrDefault() ?? "";
        return first.Split(':').FirstOrDefault() ?? "";
    }

    public string ContainerLogs()
    {
        if (_container is null) return "";
        var (stdout, stderr) = _container.GetLogsAsync().GetAwaiter().GetResult();
        return stdout + stderr;
    }

    /// <summary>Adds and commits a file into the given repo (mirrors the PHP addAndCheckInFile helper).</summary>
    public void AddAndCommit(string repoId, string filename, string content)
    {
        string repoPath = RepoPath(repoId);
        string cmd = $"cd {repoPath} && printf '%s' '{content}' > {filename} && " +
                     $"hg --config ui.username=system add {filename} && " +
                     $"hg --config ui.username=system commit -m 'added {filename}'";
        if (!string.IsNullOrEmpty(_repoOwner))
        {
            cmd += $" && chown -R {_repoOwner}:{_repoOwner} {repoPath}";
        }
        Exec(cmd);
    }

    /// <summary>
    /// Repos with a slash in their id were seeded at that literal path (the SubDir path-traversal
    /// test); everything else went through /api/manage, which nests repos one level under their
    /// first character (see RepoManageService.PrefixRepoFilePath).
    /// </summary>
    private static string RepoPath(string repoId) =>
        repoId.Contains('/') ? $"/var/vcs/public/{repoId}" : $"/var/vcs/public/{repoId[0]}/{repoId}";

    public void SetMaintenance(string message)
        => Exec($"printf '%s' '{message}' > {_maintPath}");

    public void ClearMaintenance()
        => Exec($"rm -f {_maintPath}");

    public void Exec(string shellCommand)
    {
        var result = RequireContainer().ExecAsync(["sh", "-lc", shellCommand]).GetAwaiter().GetResult();
        if (result.ExitCode != 0)
        {
            throw new Exception($"`{shellCommand}` failed ({result.ExitCode}).\nstdout:\n{result.Stdout}\nstderr:\n{result.Stderr}");
        }
    }

    public byte[] Fixture(string name) => File.ReadAllBytes(Path.Combine(_dataDir, name));

    public string FixtureText(string name) => File.ReadAllText(Path.Combine(_dataDir, name)).Trim();

    // ---- helpers ---------------------------------------------------------------------------------

    private IContainer RequireContainer() => _container ?? throw new NotSupportedException(
        "Exec/filesystem-based helpers aren't available when reusing an external server via " +
        "HGRESUME_BASE_URL; use the /api/manage-based helpers (SeedRepo, RemoveRepo, InitServerRepo) instead.");

    private static string FindContextDir()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null)
        {
            if (File.Exists(Path.Combine(dir.FullName, "Dockerfile")) &&
                Directory.Exists(Path.Combine(dir.FullName, "src")))
            {
                return dir.FullName;
            }
            dir = dir.Parent;
        }
        throw new Exception("could not locate csharp/ context dir (with Dockerfile) above the test output");
    }

    private static string Env(string name, string fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return string.IsNullOrWhiteSpace(v) ? fallback : v;
    }
}

[CollectionDefinition("server")]
public sealed class ServerCollection : ICollectionFixture<ServerFixture>
{
}
