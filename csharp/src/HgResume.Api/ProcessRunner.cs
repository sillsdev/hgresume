using System.Diagnostics;
using System.Text;

namespace HgResume.Api;

/// <summary>
/// Helpers for launching the external `hg` binary. The PHP original shelled out via exec()/`&amp;`
/// and GNU /usr/bin/time; here we use System.Diagnostics.Process directly.
/// </summary>
public static class ProcessRunner
{
    /// <summary>
    /// Runs a command synchronously (mirrors PHP exec()): returns stdout split into lines with the
    /// trailing empty line removed, plus the exit code. stderr is discarded (PHP exec captured stdout).
    /// </summary>
    public static (List<string> Lines, int ExitCode) RunSync(string workingDir, string program,
        params string[] args)
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
        var stdoutTask = proc.StandardOutput.ReadToEndAsync();
        var stderrTask = proc.StandardError.ReadToEndAsync();
        proc.WaitForExit();
        string stdout = stdoutTask.GetAwaiter().GetResult();
        _ = stderrTask.GetAwaiter().GetResult();

        var lines = stdout.Replace("\r\n", "\n").Split('\n').ToList();
        // PHP exec() drops the trailing newline / empty final element.
        if (lines.Count > 0 && lines[^1].Length == 0)
        {
            lines.RemoveAt(lines.Count - 1);
        }
        return (lines, proc.ExitCode);
    }
}
