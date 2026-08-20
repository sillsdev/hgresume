using System.ComponentModel.DataAnnotations;

namespace HgResume.Api.Manage;

/// <summary>Lock and abandoned-transaction flags for a repo on disk.</summary>
public sealed record RepoStatusResponse(bool HasAbandonedTransactions, bool IsLocked);

/// <summary>Payload for copying one repo over another.</summary>
public sealed record CopyRepoRequest
{
    /// <summary>Project code of the source repo.</summary>
    [Required, MinLength(1)]
    public required string SourceCode { get; init; }
}

/// <summary>Payload for moving a repo into the deleted folder.</summary>
public sealed record SoftDeleteRepoRequest
{
    /// <summary>Suffix appended after the project code, e.g. a timestamp or <c>2024-01-01T00-00-00__reset</c>.</summary>
    [Required, MinLength(1)]
    public required string Suffix { get; init; }
}

/// <summary>Names of reset backups that were (or would be) removed.</summary>
public sealed record CleanupResetBackupsResponse(IReadOnlyList<string> DeletedRepos);
