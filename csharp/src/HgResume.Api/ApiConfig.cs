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

    /// <summary>Kestrel's stock default (30_000_000). PHP deployments had an analogous post_max_size.</summary>
    public const long DefaultMaxRequestBodySize = 30_000_000;

    public required string CachePath { get; init; }
    public required IReadOnlyList<string> RepoSearchPaths { get; init; }
    public required string MaintenanceFilePath { get; init; }

    /// <summary>
    /// Max pushBundleChunk (and any other) request body in bytes. Maps to
    /// KestrelServerLimits.MaxRequestBodySize. Oversize bodies get a bare 413 before the dispatcher.
    /// </summary>
    public required long MaxRequestBodySize { get; init; }

    /// <summary>
    /// Age after which reset backups in <c>_____deleted_____</c> are removed. LexBox default is 31;
    /// cleanup still enforces a 5-day minimum.
    /// </summary>
    public required int ResetCleanupAgeDays { get; init; }

    /// <summary>
    /// Shared secret callers must send (as the <c>X-Manage-Secret</c> header) to reach
    /// <c>/api/manage/*</c>. Unset means the endpoints are open, which is only acceptable when
    /// <see cref="RequireManageSecret"/> is also false (e.g. local dev).
    /// </summary>
    public string? ManageSecret { get; init; }

    /// <summary>
    /// Whether <see cref="ManageSecret"/> must be configured. Defaults to true so a deployment can't
    /// accidentally leave the manage API open; defaults to false in development so it works out of
    /// the box locally.
    /// </summary>
    public required bool RequireManageSecret { get; init; }

    /// <summary>Default upper bound on a client-supplied chunkSize (20 MB, matching the Chorus client).</summary>
    public const int DefaultChunkSizeMax = 20 * 1024 * 1024;

    /// <summary>
    /// Upper bound the server clamps a client-supplied <c>chunkSize</c> to. <see cref="GetChunkAsync"/>
    /// already caps each read at the remaining bundle length, so this is cheap defense against a client
    /// asking for an absurd allocation.
    /// </summary>
    public int ChunkSizeMax { get; init; } = DefaultChunkSizeMax;

    /// <summary>
    /// How old a <c>.async_run</c> lock that is NOT tracked by this process's in-memory registry must be
    /// before it is treated as a dead generation (API restarted mid-bundle, lock file left behind) and
    /// cleaned up so a new generation can be spawned. A live generation in this process is recognised via
    /// the registry regardless of age, so this only governs cross-restart recovery. Default 2 minutes.
    /// </summary>
    public TimeSpan StaleBundleLockThreshold { get; init; } = TimeSpan.FromSeconds(120);

    /// <summary>
    /// Maximum number of times the server will (re)spawn bundle generation for a single pull transaction
    /// before giving up with FAIL, so a bundle that genuinely cannot be produced fails fast instead of
    /// respawning hg forever. Default 3.
    /// </summary>
    public int MaxBundleGenAttempts { get; init; } = 3;

    /// <summary>Age after which the cache GC reaps a <c>.bundle/.metadata/.async_run/.incoming</c> file. Default 24h.</summary>
    public TimeSpan CacheTtl { get; init; } = TimeSpan.FromHours(24);

    /// <summary>How often the cache GC background service runs. Default 1h.</summary>
    public TimeSpan CacheGcInterval { get; init; } = TimeSpan.FromMinutes(60);

    public static ApiConfig FromEnvironment(bool isDevelopment)
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
            MaxRequestBodySize = EnvLong("HGRESUME_MAX_REQUEST_BODY_SIZE", DefaultMaxRequestBodySize),
            ResetCleanupAgeDays = EnvInt("HGRESUME_RESET_CLEANUP_AGE_DAYS", 31),
            ManageSecret = Environment.GetEnvironmentVariable("HGRESUME_MANAGE_SECRET") is { Length: > 0 } s ? s : null,
            RequireManageSecret = EnvBool("HGRESUME_REQUIRE_MANAGE_SECRET", !isDevelopment),
            ChunkSizeMax = EnvInt("HGRESUME_CHUNK_SIZE_MAX", DefaultChunkSizeMax),
            StaleBundleLockThreshold =
                TimeSpan.FromSeconds(EnvInt("HGRESUME_STALE_BUNDLE_LOCK_SECONDS", 120)),
            MaxBundleGenAttempts = EnvInt("HGRESUME_MAX_BUNDLE_GEN_ATTEMPTS", 3),
            CacheTtl = TimeSpan.FromHours(EnvInt("HGRESUME_CACHE_TTL_HOURS", 24)),
            CacheGcInterval = TimeSpan.FromMinutes(EnvInt("HGRESUME_CACHE_GC_INTERVAL_MINUTES", 60)),
        };
    }

    private static string Env(string name, string fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return string.IsNullOrWhiteSpace(v) ? fallback : v;
    }

    private static long EnvLong(string name, long fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return long.TryParse(v, out var n) && n > 0 ? n : fallback;
    }

    private static int EnvInt(string name, int fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return int.TryParse(v, out var n) && n > 0 ? n : fallback;
    }

    private static bool EnvBool(string name, bool fallback)
    {
        string? v = Environment.GetEnvironmentVariable(name);
        return bool.TryParse(v, out var b) ? b : fallback;
    }
}
