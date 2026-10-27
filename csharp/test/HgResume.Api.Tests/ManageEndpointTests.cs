using System.IO.Compression;
using System.Net;
using Microsoft.AspNetCore.Mvc.Testing;
using Xunit;

namespace HgResume.Api.Tests;

/// <summary>
/// Manage endpoints through the real ASP.NET Core pipeline. Like Kestrel, TestServer rejects synchronous
/// writes to the response body, which service-level tests writing into a MemoryStream can't catch.
/// </summary>
public sealed class ManageEndpointTests : IDisposable
{
    private const string Secret = "endpoint-test-secret";

    private readonly string _basePath = Path.Join(Path.GetTempPath(), "ManageEndpointTests-" + Guid.NewGuid().ToString("N"));
    private readonly WebApplicationFactory<Program> _factory;
    private readonly HttpClient _client;

    public ManageEndpointTests()
    {
        // Program reads its config from the environment before the host is built
        Environment.SetEnvironmentVariable("HGRESUME_REPO_PATHS", Path.Join(_basePath, "repos"));
        Environment.SetEnvironmentVariable("HGRESUME_CACHE_PATH", Path.Join(_basePath, "cache"));
        Environment.SetEnvironmentVariable("HGRESUME_MANAGE_SECRET", Secret);
        _factory = new WebApplicationFactory<Program>();
        _client = _factory.CreateClient();
        _client.DefaultRequestHeaders.Add("X-Manage-Secret", Secret);
    }

    public void Dispose()
    {
        _client.Dispose();
        _factory.Dispose();
        if (Directory.Exists(_basePath)) Directory.Delete(_basePath, true);
    }

    [Fact]
    public async Task BackupRepo_StreamsAZipOfTheRepo()
    {
        using (var init = await _client.PostAsync("/api/manage/repos/backup-test", null))
        {
            Assert.Equal(HttpStatusCode.Created, init.StatusCode);
        }

        using var response = await _client.GetAsync("/api/manage/repos/backup-test/backup");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("application/zip", response.Content.Headers.ContentType?.MediaType);
        using var archive = new ZipArchive(new MemoryStream(await response.Content.ReadAsByteArrayAsync()), ZipArchiveMode.Read);
        Assert.Contains(archive.Entries, e => e.FullName.Replace('\\', '/') == ".hg/requires");
    }

    [Fact]
    public async Task BackupRepo_MissingRepoIsNotFound()
    {
        using var response = await _client.GetAsync("/api/manage/repos/no-such-repo/backup");
        Assert.Equal(HttpStatusCode.NotFound, response.StatusCode);
    }
}
