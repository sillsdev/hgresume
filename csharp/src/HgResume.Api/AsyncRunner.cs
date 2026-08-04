using System.Collections.Concurrent;
using System.Diagnostics;
using System.Text;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/AsyncRunner.php. Runs a long hg command in the background and signals completion
/// through a lock/output file so that a later (separate) HTTP request can observe the result — this
/// is what makes push/pull resumable and stateless.
///
/// The PHP original launched a detached shell that piped output into "&lt;path&gt;.async_run" and used
/// GNU /usr/bin/time to append an "AsyncCompleted:" marker. We instead run the process in-process on
/// a background task (kept alive via a static registry so it survives the originating request) and,
/// on completion, write the combined stdout+stderr followed by an "AsyncCompleted" marker. When the
/// process exits non-zero we also append "Command exited with non-zero status &lt;code&gt;", preserving
/// BundleHelper.BundleOutputHasErrors detection without depending on GNU time.
/// </summary>
public sealed class AsyncRunner
{
    private const string CompletedMarker = "AsyncCompleted";

    // Keep background tasks (and the Process they capture) alive until they finish.
    private static readonly ConcurrentDictionary<string, Task> Running = new();

    private readonly string _lockFile;

    public AsyncRunner(string runFilePath)
    {
        _lockFile = runFilePath + ".async_run";
    }

    /// <summary>Launches the command in the background. Returns immediately.</summary>
    public void Run(string workingDir, string program, params string[] args)
    {
        // touch the lock file so IsRunning() is true immediately (matches PHP `touch`)
        File.WriteAllText(_lockFile, string.Empty);

        var psi = new ProcessStartInfo
        {
            FileName = program,
            WorkingDirectory = workingDir,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        foreach (var a in args) psi.ArgumentList.Add(a);

        var proc = Process.Start(psi)
                   ?? throw new AsyncRunnerException($"failed to start process '{program}'");

        string lockFile = _lockFile;
        // Assigned before the body can observe it: Task.Run queues to the thread pool and returns
        // the Task before the delegate runs (except under a sync context that runs inline).
        Task task = null!;
        task = Task.Run(async () =>
        {
            try
            {
                var stdoutTask = proc.StandardOutput.ReadToEndAsync();
                var stderrTask = proc.StandardError.ReadToEndAsync();
                await Task.WhenAll(stdoutTask, stderrTask).ConfigureAwait(false);
                await proc.WaitForExitAsync().ConfigureAwait(false);

                var sb = new StringBuilder();
                sb.Append(stdoutTask.Result);
                sb.Append(stderrTask.Result);
                if (proc.ExitCode != 0)
                {
                    sb.Append($"\nCommand exited with non-zero status {proc.ExitCode}\n");
                }
                sb.Append($"\n{CompletedMarker}: done\n");
                // CancellationToken.None: this background write must survive the originating request.
                await File.WriteAllTextAsync(lockFile, sb.ToString(), CancellationToken.None).ConfigureAwait(false);
            }
            catch (Exception e)
            {
                // Ensure a completion marker is always written so pollers do not hang forever.
                await File.WriteAllTextAsync(lockFile,
                    $"AsyncRunner error: {e.Message}\n{CompletedMarker}: error\n", CancellationToken.None)
                    .ConfigureAwait(false);
            }
            finally
            {
                proc.Dispose();
                // Value-conditional remove so we never clear a newer run for the same lock path.
                Running.TryRemove(KeyValuePair.Create(lockFile, task));
            }
        });
        // Insert after Task.Run returns. A very fast command can finish (and TryRemove in finally)
        // before that insert, which would otherwise leave a leaked entry for the process lifetime.
        Running[lockFile] = task;
        if (task.IsCompleted)
        {
            Running.TryRemove(KeyValuePair.Create(lockFile, task));
        }
    }

    public bool IsRunning() => File.Exists(_lockFile);

    public async Task<bool> IsCompleteAsync(CancellationToken ct = default)
    {
        if (!File.Exists(_lockFile))
        {
            throw new AsyncRunnerException($"Lock file '{_lockFile}' not found, process is not running");
        }
        return (await ReadLockFileAsync(ct)).Contains(CompletedMarker);
    }

    public async Task<string> GetOutputAsync(CancellationToken ct = default)
    {
        if (!await IsCompleteAsync(ct))
        {
            throw new AsyncRunnerException($"Command on '{_lockFile}' not yet complete.");
        }
        return await ReadLockFileAsync(ct);
    }

    public void CleanUp()
    {
        if (File.Exists(_lockFile)) File.Delete(_lockFile);
    }

    /// <summary>Waits up to ~5s for the runner to complete. Mirrors PHP waitForIsComplete().</summary>
    public async Task<bool> WaitForIsCompleteAsync(CancellationToken ct = default)
    {
        for (int i = 0; i < 5; i++)
        {
            if (await IsCompleteAsync(ct)) return true;
            await Task.Delay(1000, ct);
        }
        return false;
    }

    private async Task<string> ReadLockFileAsync(CancellationToken ct = default)
    {
        // Share read/write so we never contend with the background writer.
        await using var fs = new FileStream(_lockFile, FileMode.Open, FileAccess.Read, FileShare.ReadWrite,
            bufferSize: 4096, FileOptions.Asynchronous);
        using var reader = new StreamReader(fs, Encoding.UTF8);
        return await reader.ReadToEndAsync(ct);
    }
}
