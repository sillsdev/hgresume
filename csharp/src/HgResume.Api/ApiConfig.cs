namespace HgResume.Api;

/// <summary>
/// Runtime configuration. Mirrors api/src/config.php (CACHE_PATH, repoSearchPaths, API_VERSION)
/// plus the maintenance-message file location (PHP: SourcePath . "/maintenance_message.txt").
/// All values are overridable via environment variables so the container and tests can point
/// at alternate paths.
/// </summary>
public sealed class ApiConfig
{
    public const int ApiVersion = 3; // PHP: define('API_VERSION', 3)

    public required string CachePath { get; init; }
    public required IReadOnlyList<string> RepoSearchPaths { get; init; }
    public required string MaintenanceFilePath { get; init; }

    public static ApiConfig FromEnvironment()
    {
        string cache = Env("HGRESUME_CACHE_PATH", "/var/cache/hgresume");
        string repos = Env("HGRESUME_REPO_PATHS", "/var/vcs/public;/var/vcs/private");
        string maintenance = Env("HGRESUME_MAINTENANCE_FILE", Path.Combine(cache, "maintenance_message.txt"));

        return new ApiConfig
        {
            CachePath = cache,
            RepoSearchPaths = repos
                .Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries),
            MaintenanceFilePath = maintenance,
        };
    }

    private static string Env(string name, string fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return string.IsNullOrWhiteSpace(v) ? fallback : v;
    }
}
