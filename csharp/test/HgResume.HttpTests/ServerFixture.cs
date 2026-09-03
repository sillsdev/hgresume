using System.Diagnostics;
using System.IO.Compression;
using System.Net;
using Xunit;

namespace HgResume.HttpTests;

/// <summary>
/// Shared fixture that runs the hgresume C# image in a container (via podman) and drives it over HTTP.
/// Repos are seeded and torn down through the container's own <c>/api/manage/*</c> endpoints (see
/// <see cref="ManageSecret"/>), so nothing needs host-side <c>podman cp</c> access into the container's
/// filesystem. The one exception is <see cref="MiscFacts.GetRevisions_SubDir_Works"/>, which seeds a
/// repo at a path the manage API's <c>ProjectCode</c> validation deliberately rejects (it contains
/// "/") — that case, plus <see cref="AddAndCommit"/> and the maintenance-file helpers (none of which
/// are "manage" operations), still go through <c>podman exec</c>/<c>cp</c>.
///
/// Environment overrides:
///   HGRESUME_PODMAN     container CLI (default "podman")
///   HGRESUME_IMAGE      image to run (default "hgresume-csharp:test")
///   HGRESUME_PORT       host port to publish (default "8034")
///   HGRESUME_SKIP_BUILD if set, do not build the image (assume it exists)
///   HGRESUME_BASE_URL   reuse an already-running server at this URL (with HGRESUME_CONTAINER)
///   HGRESUME_CONTAINER  name of the already-running container to exec/cp against
///   HGRESUME_KEEP       if set, do not stop/remove the container on teardown
/// </summary>
public sealed class ServerFixture : IAsyncLifetime
{
    // Shared with the -e HGRESUME_MANAGE_SECRET passed to `podman run` below.
    private const string ManageSecret = "test-secret";

    private readonly string _podman = Env("HGRESUME_PODMAN", "podman");
    private readonly string _image = Env("HGRESUME_IMAGE", "hgresume-csharp:test");
    private readonly string _port = Env("HGRESUME_PORT", "8034");
    private readonly string _dataDir = Path.Combine(AppContext.BaseDirectory, "data");
    // Set HGRESUME_REPO_OWNER (e.g. "www-data") to chown seeded repos when the server runs as a
    // non-root user (the PHP/Apache reference image). Empty = leave ownership as-is (C# runs as root).
    private readonly string _repoOwner = Env("HGRESUME_REPO_OWNER", "");
    // Where the server looks for the maintenance file. C# default is under the cache dir; the PHP app
    // looks in its src dir (SourcePath . "/maintenance_message.txt").
    private readonly string _maintPath = Env("HGRESUME_MAINT_PATH", "/var/cache/hgresume/maintenance_message.txt");

    private bool _startedByUs;
    private HttpClient? _manageHttp;

    public string ContainerName { get; private set; } = "";
    public string BaseUrl { get; private set; } = "";
    public ApiClient Client { get; private set; } = default!;

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
        }
        else
        {
            if (Environment.GetEnvironmentVariable("HGRESUME_SKIP_BUILD") is null)
            {
                string context = FindContextDir();
                Run(_podman, "build", "-t", _image, "-f", Path.Combine(context, "Dockerfile"), context);
            }

            ContainerName = "hgresume-test-" + Environment.ProcessId;
            // Clean up a stale container with the same name, if any.
            TryRun(_podman, "rm", "-f", ContainerName);
            Run(_podman, "run", "-d", "--name", ContainerName, "-p", $"{_port}:80",
                "-e", $"HGRESUME_MANAGE_SECRET={ManageSecret}", _image);
            _startedByUs = true;
            BaseUrl = $"http://localhost:{_port}";
        }

        Client = new ApiClient(BaseUrl);
        await WaitForReadyAsync();
    }

    public Task DisposeAsync()
    {
        _manageHttp?.Dispose();
        if (_startedByUs && Environment.GetEnvironmentVariable("HGRESUME_KEEP") is null)
        {
            TryRun(_podman, "logs", ContainerName); // surfaced in test output on failures
            TryRun(_podman, "rm", "-f", ContainerName);
        }
        return Task.CompletedTask;
    }

    private async Task WaitForReadyAsync()
    {
        var deadline = DateTime.UtcNow.AddSeconds(90);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                var r = Client.IsAvailable();
                if ((int)r.Http == 200) return;
            }
            catch (Exception e)
            {
                last = e;
            }
            await Task.Delay(500);
        }
        throw new Exception($"Server at {BaseUrl} did not become ready in time. Last error: {last?.Message}");
    }

    // ---- repo/maintenance seeding ---------------------------------------------------------------

    /// <summary>
    /// Seeds /var/vcs/public/&lt;repoId&gt; from a fixture repo zip. Returns the repoId. Goes through
    /// POST /api/manage/repos/{code}/finish-reset (the zip becomes the repo's .hg folder) unless
    /// repoId isn't a valid ProjectCode (e.g. contains "/"; see
    /// <see cref="MiscFacts.GetRevisions_SubDir_Works"/>) or HGRESUME_REPO_OWNER is set (a non-root
    /// image with no manage API), in which case it falls back to extracting on the host and
    /// `podman cp`-ing the result in.
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
            Run(_podman, "cp", extractDir, $"{ContainerName}:/var/vcs/public/{repoId}");
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
        => Run(_podman, "exec", ContainerName, "sh", "-lc", shellCommand);

    public byte[] Fixture(string name) => File.ReadAllBytes(Path.Combine(_dataDir, name));

    public string FixtureText(string name) => File.ReadAllText(Path.Combine(_dataDir, name)).Trim();

    // ---- process helpers ------------------------------------------------------------------------

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

    private static (int Code, string Out, string Err) Run(string exe, params string[] args)
    {
        var (code, so, se) = TryRun(exe, args);
        if (code != 0)
        {
            throw new Exception($"`{exe} {string.Join(' ', args)}` failed ({code}).\nstdout:\n{so}\nstderr:\n{se}");
        }
        return (code, so, se);
    }

    private static (int Code, string Out, string Err) TryRun(string exe, params string[] args)
    {
        var psi = new ProcessStartInfo
        {
            FileName = exe,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        foreach (var a in args) psi.ArgumentList.Add(a);

        using var proc = Process.Start(psi)!;
        var so = proc.StandardOutput.ReadToEndAsync();
        var se = proc.StandardError.ReadToEndAsync();
        proc.WaitForExit();
        return (proc.ExitCode, so.GetAwaiter().GetResult(), se.GetAwaiter().GetResult());
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
