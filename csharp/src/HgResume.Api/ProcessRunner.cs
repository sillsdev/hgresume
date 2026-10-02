using System.Diagnostics;
using System.Text;

namespace HgResume.Api;

/// <summary>
/// Result of running an external command: stdout split into lines (PHP exec() style, trailing empty
/// line removed), the process exit code, and the captured stderr (raw). Callers that previously only
/// cared about stdout can still deconstruct the first one or two members.
/// </summary>
public readonly record struct ProcessResult(List<string> Lines, int ExitCode, string StdErr);

/// <summary>
/// Helpers for launching the external `hg` binary. The PHP original shelled out via exec()/`&amp;`
/// and GNU /usr/bin/time; here we use System.Diagnostics.Process directly.
/// </summary>
public static class ProcessRunner
{
    /// <summary>
    /// Runs a command (mirrors PHP exec()): returns stdout split into lines with the trailing empty
    /// line removed, the exit code, and stderr. The PHP original discarded stderr and inferred failure
    /// from empty stdout; callers now key on the exit code and can surface stderr on a real failure.
    /// </summary>
    public static async Task<ProcessResult> RunAsync(string workingDir,
        string program, string[] args, CancellationToken ct = default)
    {
        var psi = new ProcessStartInfo
        {
            FileName = program,
            WorkingDirectory = workingDir,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        foreach (var a in args) psi.ArgumentList.Add(a);

        using var proc = Process.Start(psi)
                         ?? throw new HgException($"failed to start process '{program}'");
        // Read both streams to avoid pipe-buffer deadlock.
        var stdoutTask = proc.StandardOutput.ReadToEndAsync(ct);
        var stderrTask = proc.StandardError.ReadToEndAsync(ct);
        await Task.WhenAll(stdoutTask, stderrTask);
        await proc.WaitForExitAsync(ct);
        string stdout = stdoutTask.Result;

        var lines = stdout.Replace("\r\n", "\n").Split('\n').ToList();
        // PHP exec() drops the trailing newline / empty final element.
        if (lines.Count > 0 && lines[^1].Length == 0)
        {
            lines.RemoveAt(lines.Count - 1);
        }
        return new ProcessResult(lines, proc.ExitCode, stderrTask.Result.Trim());
    }
}
