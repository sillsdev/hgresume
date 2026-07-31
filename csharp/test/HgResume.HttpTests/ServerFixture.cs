using System.Diagnostics;
using System.Text;
using Xunit;

namespace HgResume.HttpTests;

/// <summary>
/// Shared fixture that runs the hgresume C# image in a container (via podman) and drives it over HTTP.
/// It seeds Mercurial repos into the repo volume with `podman cp` + `podman exec unzip`, so the tests
/// are true black-box HTTP-level tests against the built image.
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
    private readonly string _podman = Env("HGRESUME_PODMAN", "podman");
    private readonly string _image = Env("HGRESUME_IMAGE", "hgresume-csharp:test");
    private readonly string _port = Env("HGRESUME_PORT", "8034");
    private readonly string _dataDir = Path.Combine(AppContext.BaseDirectory, "data");

    private bool _startedByUs;

    public string ContainerName { get; private set; } = "";
    public string BaseUrl { get; private set; } = "";
    public ApiClient Client { get; private set; } = default!;

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
            Run(_podman, "run", "-d", "--name", ContainerName, "-p", $"{_port}:80", _image);
            _startedByUs = true;
            BaseUrl = $"http://localhost:{_port}";
        }

        Client = new ApiClient(BaseUrl);
        await WaitForReadyAsync();
    }

    public Task DisposeAsync()
    {
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

    /// <summary>Unzips a fixture repo into /var/vcs/public/&lt;repoId&gt;. Returns the repoId.</summary>
    public string SeedRepo(string zipName)
    {
        string repoId = Path.GetFileNameWithoutExtension(zipName);
        string localZip = Path.Combine(_dataDir, zipName);
        if (!File.Exists(localZip)) throw new FileNotFoundException($"fixture not found: {localZip}");

        Run(_podman, "cp", localZip, $"{ContainerName}:/tmp/{zipName}");
        Exec($"rm -rf /var/vcs/public/{repoId} && mkdir -p /var/vcs/public/{repoId} && " +
             $"cd /var/vcs/public/{repoId} && unzip -oq /tmp/{zipName}");
        return repoId;
    }

    public void RemoveRepo(string repoId) => Exec($"rm -rf /var/vcs/public/{repoId}");

    /// <summary>Adds and commits a file into the given repo (mirrors the PHP addAndCheckInFile helper).</summary>
    public void AddAndCommit(string repoId, string filename, string content)
    {
        Exec($"cd /var/vcs/public/{repoId} && printf '%s' '{content}' > {filename} && " +
             $"hg --config ui.username=system add {filename} && " +
             $"hg --config ui.username=system commit -m 'added {filename}'");
    }

    public void SetMaintenance(string message)
        => Exec($"printf '%s' '{message}' > /var/cache/hgresume/maintenance_message.txt");

    public void ClearMaintenance()
        => Exec("rm -f /var/cache/hgresume/maintenance_message.txt");

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
