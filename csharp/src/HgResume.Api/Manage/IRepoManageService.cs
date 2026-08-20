using System.IO.Compression;

namespace HgResume.Api.Manage;

public sealed record BackupExecutor(Func<Stream, CancellationToken, Task> ExecuteBackup);

public interface IRepoManageService
{
    Task InitRepo(ProjectCode code, CancellationToken cancellationToken = default);
    Task CopyRepo(ProjectCode sourceCode, ProjectCode destCode, CancellationToken cancellationToken = default);
    Task DeleteRepoIfExists(ProjectCode code, CancellationToken cancellationToken = default);
    BackupExecutor? BackupRepo(ProjectCode code);
    Task ResetRepo(ProjectCode code, CancellationToken cancellationToken = default);
    Task FinishReset(ProjectCode code, Stream zipFile, CancellationToken cancellationToken = default);
    Task<string[]> CleanupResetBackups(bool dryRun = false, CancellationToken cancellationToken = default);
    Task SoftDeleteRepo(ProjectCode code, string deletedRepoSuffix, CancellationToken cancellationToken = default);
    bool HasAbandonedTransactions(ProjectCode projectCode);
    bool RepoIsLocked(ProjectCode projectCode);
    bool RepoExists(ProjectCode code);
    Task InvalidateDirCache(ProjectCode code, CancellationToken cancellationToken = default);
    void EnsureLayout();
}
