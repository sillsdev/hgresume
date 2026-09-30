using System.IO.Compression;
using System.Runtime.InteropServices;
using System.Text.RegularExpressions;

namespace HgResume.Api.Manage;

/// <summary>
/// Filesystem-only repo management, ported from LexBox <c>HgService</c>.
/// Repos live at <c>{first repo search path}/{first-letter}/{code}</c> (LexBox layout), not
/// <c>{search path}/{code}</c>.
/// </summary>
public sealed partial class RepoManageService : IRepoManageService, IHostedService
{
    private const UnixFileMode Permissions = UnixFileMode.GroupRead | UnixFileMode.GroupWrite |
                                             UnixFileMode.GroupExecute | UnixFileMode.SetGroup |
                                             UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute |
                                             UnixFileMode.SetUser;

    private readonly ApiConfig _config;
    private readonly ILogger<RepoManageService> _logger;

    public RepoManageService(ApiConfig config, ILogger<RepoManageService> logger)
    {
        _config = config;
        _logger = logger;
    }

    private string RepoRoot => _config.RepoSearchPaths[0];

    private string PrefixRepoFilePath(ProjectCode code) =>
        Path.Combine(RepoRoot, code.Value[0].ToString(), code.Value);

    private string GetTempRepoPath(ProjectCode code, string reason) =>
        Path.Combine(RepoRoot, ProjectCode.TempRepoFolder, $"{code}__{reason}__{FileUtils.ToTimestamp(DateTimeOffset.UtcNow)}");

    /// <summary>
    /// Note: The repo is unstable and potentially unavailable for a short while after creation, so don't read from it right away.
    /// See: https://github.com/sillsdev/languageforge-lexbox/issues/173#issuecomment-1665478630
    /// </summary>
    public async Task InitRepo(ProjectCode code, CancellationToken cancellationToken = default)
    {
        if (Directory.Exists(PrefixRepoFilePath(code)))
            throw new AlreadyExistsException($"Repo already exists: {code}.");

        await InitRepoAt(new DirectoryInfo(PrefixRepoFilePath(code)), cancellationToken);
        await InvalidateDirCache(code, cancellationToken);
    }

    private async Task InitRepoAt(DirectoryInfo repoDirectory, CancellationToken cancellationToken)
    {
        repoDirectory.Parent?.Create();
        var workingDir = repoDirectory.Parent?.FullName ?? RepoRoot;
        var (_, exitCode) = await ProcessRunner.RunAsync(
            workingDir,
            "hg",
            ["init", repoDirectory.FullName],
            cancellationToken);
        if (exitCode != 0)
        {
            throw new HgException($"hg init failed for '{repoDirectory.FullName}' (exit {exitCode}).");
        }

        SetPermissionsRecursively(repoDirectory);
    }

    /// <summary>
    /// Danger: this replaces the repo at the destination with the source.
    /// Destination is treated as empty when it has no <c>.hg/store/00changelog.i</c>
    /// (the filesystem equivalent of LexBox's all-zero tip hash check).
    /// </summary>
    public async Task CopyRepo(ProjectCode sourceCode, ProjectCode destCode, CancellationToken cancellationToken = default)
    {
        var sourceFolder = new DirectoryInfo(PrefixRepoFilePath(sourceCode));
        if (!sourceFolder.Exists)
        {
            throw new DirectoryNotFoundException($"Source repo {sourceCode} not found");
        }

        var repoDirectory = new DirectoryInfo(PrefixRepoFilePath(destCode));
        if (repoDirectory.Exists && RepoHasCommits(repoDirectory.FullName))
        {
            throw new InvalidOperationException($"Destination repo {destCode} already exists and is not empty");
        }

        await Task.Run(() =>
        {
            if (repoDirectory.Exists) repoDirectory.Delete(true);
            repoDirectory.Create();
            FileUtils.CopyFilesRecursively(sourceFolder, repoDirectory, Permissions);
        }, cancellationToken);

        await InvalidateDirCache(destCode, cancellationToken);
    }

    public async Task DeleteRepoIfExists(ProjectCode code, CancellationToken cancellationToken = default)
    {
        await Task.Run(() =>
        {
            var path = PrefixRepoFilePath(code);
            if (Directory.Exists(path))
                Directory.Delete(path, true);
        }, cancellationToken);
    }

    public BackupExecutor? BackupRepo(ProjectCode code)
    {
        string repoPath = PrefixRepoFilePath(code);
        if (!Directory.Exists(repoPath))
        {
            return null;
        }

        return new((stream, token) => Task.Run(() =>
        {
            ZipFile.CreateFromDirectory(repoPath, stream, CompressionLevel.Fastest, includeBaseDirectory: false);
        }, token));
    }

    public async Task ResetRepo(ProjectCode code, CancellationToken cancellationToken = default)
    {
        var tmpRepo = new DirectoryInfo(GetTempRepoPath(code, "reset"));
        await InitRepoAt(tmpRepo, cancellationToken);
        await SoftDeleteRepo(code, ResetSoftDeleteSuffix(DateTimeOffset.UtcNow), cancellationToken);
        // we must init the repo as uploading a zip is optional
        tmpRepo.MoveTo(PrefixRepoFilePath(code));
        await InvalidateDirCache(code, cancellationToken);
    }

    public static string ResetSoftDeleteSuffix(DateTimeOffset resetAt) =>
        $"{FileUtils.ToTimestamp(resetAt)}__reset";

    public async Task FinishReset(ProjectCode code, Stream zipFile, CancellationToken cancellationToken = default)
    {
        var tempRepoPath = GetTempRepoPath(code, "upload");
        var tempRepo = Directory.CreateDirectory(tempRepoPath);
        await Task.Run(() =>
        {
            using var archive = new ZipArchive(zipFile, ZipArchiveMode.Read);
            archive.ExtractToDirectory(tempRepoPath);
        }, cancellationToken);

        var hgPath = Path.Join(tempRepoPath, ".hg");
        if (!Directory.Exists(hgPath))
        {
            var hgFolder = Directory.EnumerateDirectories(tempRepoPath, ".hg", SearchOption.AllDirectories)
                .FirstOrDefault();
            if (hgFolder is null)
            {
                Directory.Delete(tempRepoPath, true);
                throw ProjectResetException.ZipMissingHgFolder();
            }

            Directory.Move(hgFolder, hgPath);
        }

        await CleanupRepoFolder(tempRepo, cancellationToken);
        SetPermissionsRecursively(tempRepo);
        await DeleteRepoIfExists(code, cancellationToken);
        tempRepo.MoveTo(PrefixRepoFilePath(code));
        await InvalidateDirCache(code, cancellationToken);
    }

    public async Task<string[]> CleanupResetBackups(bool dryRun = false, CancellationToken cancellationToken = default)
    {
        List<string> deletedRepos = [];
        int deletedCount = 0;
        int totalCount = 0;
        var deletedRoot = Path.Combine(RepoRoot, ProjectCode.DeletedRepoFolder);
        if (!Directory.Exists(deletedRoot))
        {
            return [];
        }

        foreach (var deletedRepo in Directory.EnumerateDirectories(deletedRoot))
        {
            cancellationToken.ThrowIfCancellationRequested();
            totalCount++;
            var deletedRepoName = Path.GetFileName(deletedRepo);
            var resetDate = GetResetDate(deletedRepoName);
            if (resetDate is null)
            {
                continue;
            }

            var resetAge = DateTimeOffset.UtcNow - resetDate.Value;
            var ageThreshold = TimeSpan.FromDays(Math.Max(_config.ResetCleanupAgeDays, 5));
            if (resetAge <= ageThreshold) continue;

            try
            {
                if (!dryRun)
                    await Task.Run(() => Directory.Delete(deletedRepo, true), cancellationToken);
                deletedRepos.Add(deletedRepoName);
                deletedCount++;
            }
            catch (Exception e)
            {
                _logger.LogWarning(e, "Failed to delete reset backup {Repo}", deletedRepoName);
            }
        }

        _logger.LogInformation(
            "CleanupResetBackups scanned {Total}, deleted {Deleted}, dryRun {DryRun}",
            totalCount, deletedCount, dryRun);
        return deletedRepos.ToArray();
    }

    public static DateTimeOffset? GetResetDate(string repoName)
    {
        var match = ResetProjectsRegex().Match(repoName);
        if (!match.Success) return null;
        return FileUtils.ToDateTimeOffset(match.Groups[1].Value);
    }

    [GeneratedRegex(@"__(\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2})__reset$")]
    public static partial Regex ResetProjectsRegex();

    /// <summary>
    /// Deletes all files and folders in the repo folder except for .hg.
    /// </summary>
    private static async Task CleanupRepoFolder(DirectoryInfo repoDir, CancellationToken cancellationToken)
    {
        await Task.Run(() =>
        {
            foreach (var info in repoDir.EnumerateFileSystemInfos())
            {
                if (info.Name == ".hg") continue;
                if (info is DirectoryInfo dir) dir.Delete(true);
                else info.Delete();
            }
        }, cancellationToken);
    }

    public async Task SoftDeleteRepo(ProjectCode code, string deletedRepoSuffix, CancellationToken cancellationToken = default)
    {
        var deletedRepoName = DeletedRepoName(code, deletedRepoSuffix);
        await Task.Run(() =>
        {
            var deletedRepoPath = Path.Combine(RepoRoot, ProjectCode.DeletedRepoFolder);
            var directory = Directory.CreateDirectory(deletedRepoPath);
            if (RuntimeInformation.IsOSPlatform(OSPlatform.Linux))
                directory.UnixFileMode = Permissions;
            Directory.Move(
                PrefixRepoFilePath(code),
                Path.Combine(deletedRepoPath, deletedRepoName));
        }, cancellationToken);
    }

    public static string DeletedRepoName(ProjectCode code, string deletedRepoSuffix) =>
        $"{code}__{deletedRepoSuffix}";

    private static void SetPermissionsRecursively(DirectoryInfo rootDir)
    {
        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Linux)) return;
        rootDir.UnixFileMode = Permissions;

        foreach (var dir in rootDir.EnumerateDirectories())
        {
            dir.UnixFileMode = Permissions;
            SetPermissionsRecursively(dir);
        }

        foreach (var file in rootDir.EnumerateFiles())
        {
            file.UnixFileMode = Permissions;
        }
    }

    public bool HasAbandonedTransactions(ProjectCode projectCode) =>
        Path.Exists(Path.Combine(PrefixRepoFilePath(projectCode), ".hg", "store", "journal"));

    public bool RepoIsLocked(ProjectCode projectCode) =>
        Path.Exists(Path.Combine(PrefixRepoFilePath(projectCode), ".hg", "store", "lock"));

    public bool RepoExists(ProjectCode code) => Directory.Exists(PrefixRepoFilePath(code));

    public Task InvalidateDirCache(ProjectCode code, CancellationToken cancellationToken = default)
    {
        var repoPath = PrefixRepoFilePath(code);
        if (Directory.Exists(repoPath))
        {
            // Invalidate NFS directory cache by forcing a write and re-read of the repo directory
            var randomPath = Path.Join(repoPath, Path.GetRandomFileName());
            while (File.Exists(randomPath) || Directory.Exists(randomPath))
            {
                randomPath = Path.Join(repoPath, Path.GetRandomFileName());
            }

            try
            {
                var d = Directory.CreateDirectory(randomPath);
                d.Delete();
            }
            catch (Exception)
            {
                // best-effort, matching LexBox
            }
        }

        return Task.CompletedTask;
    }

    private static bool RepoHasCommits(string repoPath) =>
        File.Exists(Path.Join(repoPath, ".hg", "store", "00changelog.i"));

    public void EnsureLayout()
    {
        foreach (var repoRoot in _config.RepoSearchPaths)
        {
            var repoContainerDirectories = ProjectCode.SpecialDirectoryNames
                .Concat(Enumerable.Range('a', 'z' - 'a' + 1).Select(c => ((char)c).ToString()))
                .Concat(Enumerable.Range(0, 10).Select(c => c.ToString()));

            foreach (var directory in repoContainerDirectories)
            {
                var path = Path.Combine(repoRoot, directory);
                var dirInfo = Directory.CreateDirectory(path);
                if (RuntimeInformation.IsOSPlatform(OSPlatform.Linux))
                    dirInfo.UnixFileMode = Permissions;
            }
        }
    }

    public Task StartAsync(CancellationToken cancellationToken)
    {
        EnsureLayout();
        return Task.CompletedTask;
    }

    public Task StopAsync(CancellationToken cancellationToken) => Task.CompletedTask;
}
