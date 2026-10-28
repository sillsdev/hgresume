using HgResume.Api;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace HgResume.Api.Tests;

public sealed class CacheGarbageCollectorTests : IDisposable
{
    private readonly string _cache = Path.Join(Path.GetTempPath(), "CacheGcTests-" + Guid.NewGuid().ToString("N"));

    public CacheGarbageCollectorTests() => Directory.CreateDirectory(_cache);

    public void Dispose()
    {
        if (Directory.Exists(_cache)) Directory.Delete(_cache, true);
    }

    private CacheGarbageCollector MakeGc(TimeSpan ttl)
    {
        var config = new ApiConfig
        {
            CachePath = _cache,
            RepoSearchPaths = [_cache],
            MaintenanceFilePath = Path.Join(_cache, "maintenance_message.txt"),
            MaxRequestBodySize = ApiConfig.DefaultMaxRequestBodySize,
            ResetCleanupAgeDays = 31,
            RequireManageSecret = false,
            CacheTtl = ttl,
        };
        return new CacheGarbageCollector(config, NullLogger<CacheGarbageCollector>.Instance);
    }

    private string Touch(string name, TimeSpan age)
    {
        string path = Path.Join(_cache, name);
        File.WriteAllText(path, "x");
        File.SetLastWriteTimeUtc(path, DateTime.UtcNow - age);
        return path;
    }

    [Fact]
    public void Collect_ReapsStaleTransactionArtifacts()
    {
        var stale = new[]
        {
            Touch("abc.bundle", TimeSpan.FromHours(48)),
            Touch("abc.metadata", TimeSpan.FromHours(48)),
            Touch("abc.async_run", TimeSpan.FromHours(48)),
            Touch("abc.bundle.incoming", TimeSpan.FromHours(48)),
        };

        int removed = MakeGc(TimeSpan.FromHours(24)).Collect();

        Assert.Equal(stale.Length, removed);
        Assert.All(stale, p => Assert.False(File.Exists(p)));
    }

    [Fact]
    public void Collect_LeavesFreshArtifactsAndUnrelatedFiles()
    {
        string fresh = Touch("fresh.async_run", TimeSpan.FromHours(1));
        string unrelated = Touch("maintenance_message.txt", TimeSpan.FromHours(48));
        string staleBundle = Touch("old.bundle", TimeSpan.FromHours(48));

        int removed = MakeGc(TimeSpan.FromHours(24)).Collect();

        Assert.Equal(1, removed);
        Assert.True(File.Exists(fresh), "a lock within the TTL must be kept");
        Assert.True(File.Exists(unrelated), "non-transaction files must be left alone");
        Assert.False(File.Exists(staleBundle));
    }
}
