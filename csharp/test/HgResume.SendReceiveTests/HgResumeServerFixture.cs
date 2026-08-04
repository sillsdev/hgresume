using System.Diagnostics;
using Xunit;

namespace HgResume.SendReceiveTests;

/// <summary>
/// Runs the C# hgresume image in a container (via podman/docker) and lets tests create empty server
/// repos with `hg init` — the stand-in for LexBox's project registration. Exposes the host:port the
/// Chorus resumable client points at. The project CODE must contain the substring "resumable" so
/// Chorus selects its resumable transport (RepositoryAddress.IsKnownResumableRepository).
/// </summary>
public sealed class HgResumeServerFixture : IAsyncLifetime
{
    private readonly string _cli = Env("HGRESUME_PODMAN", "podman");
    private readonly string _image = Env("HGRESUME_IMAGE", "hgresume-csharp:test");
    private readonly string _port = Env("HGRESUME_PORT", "8041");
    private readonly HttpClient _http = new();
    private string _container = "";

    public string BaseUrl => $"localhost:{_port}";

    public async Task InitializeAsync()
    {
        _container = "hgresume-sr-" + Environment.ProcessId;
        TryRun(_cli, "rm", "-f", _container);
        Run(_cli, "run", "-d", "--name", _container, "-p", $"{_port}:80", _image);
        await WaitForReadyAsync();
    }

    public Task DisposeAsync()
    {
        if (Environment.GetEnvironmentVariable("HGRESUME_KEEP") is null)
        {
            TryRun(_cli, "rm", "-f", _container);
        }
        return Task.CompletedTask;
    }

    /// <summary>Creates an empty hg repo on the server for the given project code.</summary>
    public void InitServerRepo(string code)
    {
        Run(_cli, "exec", _container, "sh", "-lc",
            $"rm -rf /var/vcs/public/{code} && hg init /var/vcs/public/{code}");
    }

    /// <summary>Returns the server's revision list ("hash:branch|...") for a repo, via the HTTP API.</summary>
    public async Task<string> GetServerRevisions(string code, int quantity = 50)
    {
        var resp = await _http.GetAsync($"http://{BaseUrl}/api/v03/getRevisions?offset=0&quantity={quantity}&repoId={code}");
        return await resp.Content.ReadAsStringAsync();
    }

    /// <summary>Server tip hash (first revision), or "" if the repo is empty.</summary>
    public async Task<string> GetServerTip(string code)
    {
        var revs = await GetServerRevisions(code, 1);
        // format: "<hash>:<branch>|..."; empty repo returns "0:"
        var first = revs.Split('|').FirstOrDefault() ?? "";
        return first.Split(':').FirstOrDefault() ?? "";
    }

    public string ContainerLogs() => TryRun(_cli, "logs", _container).Out;

    private async Task WaitForReadyAsync()
    {
        var deadline = DateTime.UtcNow.AddSeconds(90);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                var r = await _http.GetAsync($"http://{BaseUrl}/api/v03/isAvailable");
                if ((int)r.StatusCode == 200) return;
            }
            catch
            {
                // not up yet
            }
            await Task.Delay(500);
        }
        throw new Exception($"hgresume container not ready at {BaseUrl}");
    }

    private static (int Code, string Out, string Err) Run(string exe, params string[] args)
    {
        var r = TryRun(exe, args);
        if (r.Code != 0)
            throw new Exception($"`{exe} {string.Join(' ', args)}` failed ({r.Code}).\n{r.Out}\n{r.Err}");
        return r;
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

    private static string Env(string n, string d)
    {
        var v = Environment.GetEnvironmentVariable(n);
        return string.IsNullOrWhiteSpace(v) ? d : v;
    }
}

[CollectionDefinition("hgresume-server")]
public sealed class HgResumeServerCollection : ICollectionFixture<HgResumeServerFixture>
{
}
