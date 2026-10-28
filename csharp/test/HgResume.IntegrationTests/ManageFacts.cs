using Xunit;

namespace HgResume.IntegrationTests;

/// <summary>/api/manage endpoints against the real container, i.e. behind Kestrel.</summary>
[Collection("server")]
public sealed class ManageFacts
{
    private readonly ServerFixture _fx;

    public ManageFacts(ServerFixture fx) => _fx = fx;

    [Fact]
    public async Task Backup_RestoresTheSameRepo()
    {
        var source = _fx.SeedRepo("sample-hg-repo.zip", "backup-source");
        _fx.RemoveRepo("backup-restored");

        var backup = _fx.DownloadBackup(source);
        _fx.FinishReset("backup-restored", backup);

        var restoredRevisions = await _fx.GetServerRevisions("backup-restored");
        Assert.NotEqual("", await _fx.GetServerTip(source));
        Assert.Equal(await _fx.GetServerRevisions(source), restoredRevisions);
    }
}
