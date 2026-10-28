namespace HgResume.Api;

/// <summary>
/// Periodically reaps abandoned transaction artifacts from <see cref="ApiConfig.CachePath"/>.
///
/// Only <c>finishPull/PushBundle</c> cleaned these up, so a client that looped (or simply went away)
/// left its <c>.bundle/.metadata/.async_run/.incoming</c> files behind forever. The July 2026 incident
/// left <b>1,258</b> leaked <c>.async_run</c> locks. The PHP deployment would have needed a cron job;
/// the .NET host is long-running, so a <see cref="BackgroundService"/> fits without any image
/// process-model change.
/// </summary>
public sealed class CacheGarbageCollector : BackgroundService
{
    // Transaction artifacts BundleHelper/AsyncRunner write into the cache dir. Repo-reset backups live
    // under their own TempRepoFolder and are reaped by RepoManageService, so we don't touch those.
    private static readonly string[] Extensions = { ".bundle", ".metadata", ".async_run", ".incoming" };

    private readonly ApiConfig _config;
    private readonly ILogger<CacheGarbageCollector> _logger;

    public CacheGarbageCollector(ApiConfig config, ILogger<CacheGarbageCollector> logger)
    {
        _config = config;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // Run once at startup (reap anything a prior process left behind), then on the configured cadence.
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                Collect();
            }
            catch (Exception e)
            {
                _logger.LogWarning(e, "Cache GC pass failed");
            }

            try
            {
                await Task.Delay(_config.CacheGcInterval, stoppingToken);
            }
            catch (OperationCanceledException)
            {
                break;
            }
        }
    }

    /// <summary>Deletes cache artifacts whose last-write time is older than <see cref="ApiConfig.CacheTtl"/>.</summary>
    public int Collect()
    {
        if (!Directory.Exists(_config.CachePath))
        {
            return 0;
        }

        DateTime cutoff = DateTime.UtcNow - _config.CacheTtl;
        int removed = 0;
        foreach (string path in Directory.EnumerateFiles(_config.CachePath))
        {
            if (!Extensions.Contains(Path.GetExtension(path)))
            {
                continue;
            }
            try
            {
                if (File.GetLastWriteTimeUtc(path) < cutoff)
                {
                    File.Delete(path);
                    removed++;
                }
            }
            catch (Exception e)
            {
                // A file deleted or rewritten by a concurrent request between stat and delete is fine;
                // just skip it this pass.
                _logger.LogDebug(e, "Cache GC could not reap {Path}", path);
            }
        }

        if (removed > 0)
        {
            _logger.LogInformation("Cache GC reaped {Count} stale cache file(s) from {Path}",
                removed, _config.CachePath);
        }
        return removed;
    }
}
