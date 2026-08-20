using System.IO.Compression;
using HgResume.Api;
using HgResume.Api.Manage;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace HgResume.Api.Tests;

public sealed class RepoManageServiceTests : IDisposable
{
    private readonly string _basePath = Path.Join(Path.GetTempPath(), "RepoManageServiceTests-" + Guid.NewGuid().ToString("N"));
    private readonly RepoManageService _svc;

    public RepoManageServiceTests()
    {
        Directory.CreateDirectory(_basePath);
        var config = new ApiConfig
        {
            CachePath = Path.Join(_basePath, "cache"),
            RepoSearchPaths = [Path.Join(_basePath, "hg-repos")],
            MaintenanceFilePath = Path.Join(_basePath, "cache", "maintenance_message.txt"),
            MaxRequestBodySize = ApiConfig.DefaultMaxRequestBodySize,
            ResetCleanupAgeDays = 31,
        };
        _svc = new RepoManageService(config, NullLogger<RepoManageService>.Instance);
        _svc.EnsureLayout();
    }

    public void Dispose()
    {
        if (Directory.Exists(_basePath))
        {
            Directory.Delete(_basePath, true);
        }
    }

    [Fact]
    public async Task InitRepo_CreatesPrefixedEmptyRepo()
    {
        var code = new ProjectCode("unzip-test");
        await _svc.InitRepo(code);

        var repoPath = RepoPath("unzip-test");
        Assert.True(Directory.Exists(repoPath));
        Assert.True(Directory.Exists(Path.Combine(repoPath, ".hg")));
        Assert.False(_svc.HasAbandonedTransactions(code));
        Assert.False(_svc.RepoIsLocked(code));
    }

    [Fact]
    public async Task InitRepo_ThrowsIfAlreadyExists()
    {
        await _svc.InitRepo("unzip-test");
        await Assert.ThrowsAsync<AlreadyExistsException>(() => _svc.InitRepo("unzip-test"));
    }

    [Theory]
    [InlineData("-xy")]
    [InlineData("-x-y-z")]
    [InlineData("-123")]
    public async Task ProjectCodesMayNotStartWithHyphen(string code)
    {
        await Assert.ThrowsAsync<ArgumentException>(() => _svc.InitRepo(code));
    }

    [Theory]
    [InlineData(".hg/important-file.bin")]
    [InlineData("unzip-test/.hg/important-file.bin")]
    public async Task CanFinishResetByUnZippingAnArchive(string filePath)
    {
        var code = new ProjectCode("unzip-test");
        await _svc.InitRepo(code);

        using var stream = new MemoryStream();
        using (var zipArchive = new ZipArchive(stream, ZipArchiveMode.Create, true))
        {
            CreateSimpleEntry(zipArchive, filePath);
            CreateSimpleEntry(zipArchive, "random-subfolder/other-file.txt");
        }
        stream.Position = 0;
        await _svc.FinishReset(code, stream);

        var repoPath = RepoPath("unzip-test");
        var files = Directory.EnumerateFiles(repoPath, "*", SearchOption.AllDirectories)
            .Select(p => Path.GetRelativePath(repoPath, p))
            .ToList();
        Assert.Single(files);
        Assert.Equal(Path.Join(".hg", "important-file.bin"), files[0]);
    }

    [Fact]
    public async Task ThrowsIfNoHgFolderIsFound()
    {
        var code = new ProjectCode("unzip-test-no-hg");
        await _svc.InitRepo(code);
        using var stream = new MemoryStream();
        using (var zipArchive = new ZipArchive(stream, ZipArchiveMode.Create, true))
        {
            CreateSimpleEntry(zipArchive, "random-subfolder/other-file.txt");
        }

        stream.Position = 0;
        await Assert.ThrowsAsync<ProjectResetException>(() => _svc.FinishReset(code, stream));
    }

    [Fact]
    public async Task CopyRepo_CopiesIntoPrefixedDestination()
    {
        await _svc.InitRepo("source-repo");
        File.WriteAllText(Path.Combine(RepoPath("source-repo"), ".hg", "copied.txt"), "ok");

        await _svc.CopyRepo("source-repo", "dest-repo");

        Assert.True(File.Exists(Path.Combine(RepoPath("dest-repo"), ".hg", "copied.txt")));
    }

    [Fact]
    public async Task CopyRepo_ThrowsWhenDestinationHasChangelog()
    {
        await _svc.InitRepo("source-repo");
        await _svc.InitRepo("dest-repo");
        Directory.CreateDirectory(Path.Combine(RepoPath("dest-repo"), ".hg", "store"));
        File.WriteAllText(Path.Combine(RepoPath("dest-repo"), ".hg", "store", "00changelog.i"), "not-empty");

        await Assert.ThrowsAsync<InvalidOperationException>(() => _svc.CopyRepo("source-repo", "dest-repo"));
    }

    [Fact]
    public async Task SoftDelete_MovesRepoToDeletedFolder()
    {
        await _svc.InitRepo("to-delete");
        await _svc.SoftDeleteRepo("to-delete", "gone");

        Assert.False(_svc.RepoExists("to-delete"));
        Assert.True(Directory.Exists(Path.Combine(_basePath, "hg-repos", ProjectCode.DeletedRepoFolder, "to-delete__gone")));
    }

    [Fact]
    public async Task BackupRepo_ZipsContents()
    {
        await _svc.InitRepo("zip-me");
        var executor = _svc.BackupRepo("zip-me");
        Assert.NotNull(executor);

        using var ms = new MemoryStream();
        await executor!.ExecuteBackup(ms, CancellationToken.None);
        Assert.True(ms.Length > 0);
    }

    [Fact]
    public void GetResetDate_ParsesSuffix()
    {
        var name = "proj__2021-08-27T18-26-55__reset";
        var date = RepoManageService.GetResetDate(name);
        Assert.NotNull(date);
        Assert.Equal(new DateTimeOffset(2021, 8, 27, 18, 26, 55, TimeSpan.Zero), date!.Value);
    }

    [Fact]
    public async Task CleanupResetBackups_DeletesOldResetFolders()
    {
        var oldName = "oldproj__2020-01-01T00-00-00__reset";
        var oldPath = Path.Combine(_basePath, "hg-repos", ProjectCode.DeletedRepoFolder, oldName);
        Directory.CreateDirectory(oldPath);
        File.WriteAllText(Path.Combine(oldPath, "marker"), "x");

        var deleted = await _svc.CleanupResetBackups(dryRun: false);

        Assert.Contains(oldName, deleted);
        Assert.False(Directory.Exists(oldPath));
    }

    [Fact]
    public async Task HasAbandonedTransactions_WhenJournalExists()
    {
        await _svc.InitRepo("tx-repo");
        File.WriteAllText(Path.Combine(RepoPath("tx-repo"), ".hg", "store", "journal"), "j");
        Assert.True(_svc.HasAbandonedTransactions("tx-repo"));
    }

    private string RepoPath(string code) =>
        Path.GetFullPath(Path.Join(_basePath, "hg-repos", code[0].ToString(), code));

    private static void CreateSimpleEntry(ZipArchive zipArchive, string filePath)
    {
        var entry = zipArchive.CreateEntry(filePath);
        using var fileStream = entry.Open();
        Span<byte> buff = stackalloc byte[100];
        Random.Shared.NextBytes(buff);
        fileStream.Write(buff);
        fileStream.Flush();
    }
}
