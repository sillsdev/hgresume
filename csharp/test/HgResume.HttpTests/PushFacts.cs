using System.Text;
using Xunit;

namespace HgResume.HttpTests;

/// <summary>HTTP-level ports of the push cases in api/test/HgResumeApi_Test.php.</summary>
[Collection("server")]
public sealed class PushFacts
{
    private readonly ServerFixture _fx;
    private ApiClient Api => _fx.Client;

    public PushFacts(ServerFixture fx) => _fx = fx;

    private static byte[] B(string s) => Encoding.UTF8.GetBytes(s);

    [Fact]
    public void PushBundleChunk_BogusId_UnknownCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunk("fakeid", 10000, 0, B("chunkData"), nameof(PushBundleChunk_BogusId_UnknownCode));
        Assert.Equal("UNKNOWNID", r.Status);
    }

    [Fact]
    public void PushBundleChunk_EmptyId_UnknownCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunk("", 10000, 0, B("chunkData"), nameof(PushBundleChunk_EmptyId_UnknownCode));
        Assert.Equal("UNKNOWNID", r.Status);
    }

    [Fact]
    public void PushBundleChunk_InvalidOffset_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunk("sample-hg-repo", 1000, 2000, B("chunkData"), nameof(PushBundleChunk_InvalidOffset_FailCode));
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PushBundleChunk_NoData_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunk("sample-hg-repo", 1000, 0, Array.Empty<byte>(), nameof(PushBundleChunk_NoData_FailCode));
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PushBundleChunk_InvalidBundleSize_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunkRaw("sample-hg-repo", "invalid", 0, B("someData"), nameof(PushBundleChunk_InvalidBundleSize_FailCode));
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PushBundleChunk_DataTooLarge_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        var r = Api.PushBundleChunk("sample-hg-repo", 10, 0, B("someDataLargerThan 10 bytes"), nameof(PushBundleChunk_DataTooLarge_FailCode));
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PushBundleChunk_ChunkSent_ReceivedCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_ChunkSent_ReceivedCode);
        Api.FinishPushBundle(tx);
        var r = Api.PushBundleChunk("sample-hg-repo", 100, 0, B("someChunkData"), tx);
        Assert.Equal("RECEIVED", r.Status);
    }

    [Fact]
    public void PushBundleChunk_AllChunksSent_SuccessCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_AllChunksSent_SuccessCode);
        Api.FinishPushBundle(tx);
        var r = Protocol.PushEntireBundle(Api, "sample-hg-repo", tx, _fx.Fixture("sample.bundle"));
        Assert.Equal("SUCCESS", r.Status);
    }

    [Fact]
    public void PushBundleChunk_AllChunksSentButBadDataChunkSoBundleFails_ResetCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_AllChunksSentButBadDataChunkSoBundleFails_ResetCode);
        Api.FinishPushBundle(tx);
        Assert.Equal("RECEIVED", Api.PushBundleChunk("sample-hg-repo", 15, 0, B("12345"), tx).Status);
        Assert.Equal("RECEIVED", Api.PushBundleChunk("sample-hg-repo", 15, 5, B("1234"), tx).Status);
        Assert.Equal("RECEIVED", Api.PushBundleChunk("sample-hg-repo", 15, 9, B("1234"), tx).Status);
        var last = Api.PushBundleChunk("sample-hg-repo", 15, 13, B("12"), tx);
        last = Protocol.SettlePush(Api, "sample-hg-repo", tx, B("123451234123412"), last);
        Assert.Equal("RESET", last.Status);
    }

    [Fact]
    public void PushBundleChunk_RequestedOffsetNotEqualToSOW_FailCodeReturnsSOW()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_RequestedOffsetNotEqualToSOW_FailCodeReturnsSOW);
        Api.FinishPushBundle(tx);
        Api.PushBundleChunk("sample-hg-repo", 15, 0, B("12345"), tx);
        var r = Api.PushBundleChunk("sample-hg-repo", 15, 10, B("12345"), tx);
        Assert.Equal("FAIL", r.Status);
        Assert.Equal(5, r.HgrInt("sow"));
    }

    [Fact]
    public void PushBundleChunk_PushWithOffsetZeroButSOWGreaterThanZero_ReceivedCodeReturnsSOW()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_PushWithOffsetZeroButSOWGreaterThanZero_ReceivedCodeReturnsSOW);
        Api.FinishPushBundle(tx);
        Api.PushBundleChunk("sample-hg-repo", 15, 0, B("12345"), tx);
        var r = Api.PushBundleChunk("sample-hg-repo", 15, 0, B("12"), tx);
        Assert.Equal("RECEIVED", r.Status);
        Assert.Equal(5, r.HgrInt("sow"));
    }

    [Fact]
    public void PushBundleChunk_InitializedRepoWithZeroChangesets_BundleSuccessfullyApplied()
    {
        _fx.SeedRepo("empty-hg-repo.zip");
        string tx = nameof(PushBundleChunk_InitializedRepoWithZeroChangesets_BundleSuccessfullyApplied);
        Api.FinishPushBundle(tx);
        var r = Protocol.PushEntireBundle(Api, "empty-hg-repo", tx, _fx.Fixture("sample_entire.bundle"));
        Assert.Equal("SUCCESS", r.Status);
    }

    [Fact]
    public void PushBundleChunk_PushOneChunkThenRepoChanges_PushContinuesSuccessfully()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_PushOneChunkThenRepoChanges_PushContinuesSuccessfully);
        Api.FinishPushBundle(tx);

        byte[] bundle = _fx.Fixture("sample.bundle");
        int bundleSize = bundle.Length;
        int sow = 0;
        bool changed = false;
        ApiResponse r = default!;
        for (int guard = 0; guard < 2000; guard++)
        {
            if (sow >= 50 && !changed)
            {
                _fx.AddAndCommit("sample-hg-repo", "fileToAdd.txt", "sample data to add");
                changed = true;
            }
            r = Api.PushBundleChunk("sample-hg-repo", bundleSize, sow, Protocol.Slice(bundle, sow, 50), tx);
            if ((int)r.Http == 200) break;
            if ((int)r.Http == 202) { sow = r.HgrInt("sow"); if (r.Status == "INPROGRESS") Thread.Sleep(300); continue; }
            break;
        }
        Assert.Equal("SUCCESS", r.Status);
    }

    // The PHP tests push the whole unrelated bundle in a single call (offset 0, full bundleSize),
    // so we do the same here rather than chunking a 600KB bundle 50 bytes at a time.
    [Fact]
    public void PushBundleChunk_UnrelatedRepo1_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_UnrelatedRepo1_FailCode);
        Api.FinishPushBundle(tx);
        byte[] bundle = _fx.Fixture("unrelated.bundle");
        var r = Api.PushBundleChunk("sample-hg-repo", bundle.Length, 0, bundle, tx);
        r = Protocol.SettlePush(Api, "sample-hg-repo", tx, bundle, r);
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PushBundleChunk_UnrelatedRepo2_FailCode()
    {
        _fx.SeedRepo("sample-hg-repo.zip");
        string tx = nameof(PushBundleChunk_UnrelatedRepo2_FailCode);
        Api.FinishPushBundle(tx);
        byte[] bundle = _fx.Fixture("unrelated2.bundle");
        var r = Api.PushBundleChunk("sample-hg-repo", bundle.Length, 0, bundle, tx);
        r = Protocol.SettlePush(Api, "sample-hg-repo", tx, bundle, r);
        Assert.Equal("FAIL", r.Status);
    }
}
