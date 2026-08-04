using Xunit;

namespace HgResume.HttpTests;

/// <summary>HTTP-level ports of the pull cases in api/test/HgResumeApi_Test.php.</summary>
[Collection("server")]
public sealed class PullFacts
{
    private readonly ServerFixture _fx;
    private ApiClient Api => _fx.Client;

    public PullFacts(ServerFixture fx) => _fx = fx;

    [Fact]
    public void PullBundleChunk_EmptyId_UnknownCode()
    {
        _fx.SeedRepo("sampleHgRepo.zip");
        string tx = nameof(PullBundleChunk_EmptyId_UnknownCode);
        Api.FinishPullBundle(tx);
        var r = Api.PullBundleChunk("", new[] { "" }, 0, 50, tx);
        Assert.Equal("UNKNOWNID", r.Status);
    }

    [Fact]
    public void PullBundleChunk_BogusId_UnknownCode()
    {
        _fx.SeedRepo("sampleHgRepo.zip");
        string tx = nameof(PullBundleChunk_BogusId_UnknownCode);
        Api.FinishPullBundle(tx);
        var r = Api.PullBundleChunk("fakeid", new[] { "" }, 0, 50, tx);
        Assert.Equal("UNKNOWNID", r.Status);
    }

    [Fact]
    public void PullBundleChunk_InvalidHash_FailCode()
    {
        _fx.SeedRepo("sampleHgRepo.zip");
        string tx = nameof(PullBundleChunk_InvalidHash_FailCode);
        Api.FinishPullBundle(tx);
        var r = Api.PullBundleChunk("sampleHgRepo", new[] { "fakehash" }, 0, 50, tx);
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PullBundleChunk_ValidRequestButNoChanges_NoChangeCode()
    {
        _fx.SeedRepo("sampleHgRepo.zip");
        string tx = nameof(PullBundleChunk_ValidRequestButNoChanges_NoChangeCode);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");
        var r = Api.PullBundleChunk("sampleHgRepo", new[] { hash }, 0, 50, tx);
        Assert.Equal("NOCHANGE", r.Status);
    }

    [Fact]
    public void PullBundleChunk_OffsetZero_ValidData()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_OffsetZero_ValidData);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");
        var r = Protocol.PullFirstChunk(Api, "sampleHgRepo2", new[] { hash }, 0, 50, tx);
        Assert.Equal("SUCCESS", r.Status);
        Assert.Equal(50, r.HgrInt("chunkSize"));
        Assert.Equal(_fx.Fixture("sample.bundle").Length, r.HgrInt("bundleSize"));
    }

    [Fact]
    public void PullBundleChunk_OffsetGreaterThanZeroAndNoBundleCreated_ResetResponse()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_OffsetGreaterThanZeroAndNoBundleCreated_ResetResponse);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");
        var r = Api.PullBundleChunk("sampleHgRepo2", new[] { hash }, 50, 50, tx);
        Assert.Equal("RESET", r.Status);
    }

    [Fact]
    public void PullBundleChunk_OffsetEqualToBundleSize_SuccessCodeWithZeroChunkSize()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_OffsetEqualToBundleSize_SuccessCodeWithZeroChunkSize);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");
        var first = Protocol.PullFirstChunk(Api, "sampleHgRepo2", new[] { hash }, 0, 50, tx);
        Assert.Equal("SUCCESS", first.Status);
        int bundleSize = first.HgrInt("bundleSize");
        // At offset == bundleSize the response is SUCCESS only once the transaction has flipped from the
        // Bundle to the Downloading state; until then the server returns INPROGRESS (as the real client
        // polls through). Poll rather than asserting SUCCESS on the first request, which is timing-flaky.
        var r = Protocol.PullFirstChunk(Api, "sampleHgRepo2", new[] { hash }, bundleSize, 1000, tx);
        Assert.Equal("SUCCESS", r.Status);
        Assert.Equal(0, r.HgrInt("chunkSize"));
        Assert.Empty(r.Content);
    }

    [Fact]
    public void PullBundleChunk_PullUntilFinished_AssembledBundleIsValid()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_PullUntilFinished_AssembledBundleIsValid);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");
        var (assembled, _) = Protocol.PullEntireBundle(Api, "sampleHgRepo2", new[] { hash }, tx);
        Assert.Equal(_fx.Fixture("sample.bundle"), assembled);
    }

    [Fact]
    public void PullBundleChunk_PullFromBaseRevisionUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid()
    {
        _fx.SeedRepo("sample2branchHgRepo.zip");
        string tx = nameof(PullBundleChunk_PullFromBaseRevisionUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample2branch.hash");
        var (assembled, _) = Protocol.PullEntireBundle(Api, "sample2branchHgRepo", new[] { hash }, tx);
        Assert.Equal(_fx.Fixture("sample2branch.bundle"), assembled);
    }

    [Fact]
    public void PullBundleChunk_PullFromTwoBaseRevisionsUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid()
    {
        _fx.SeedRepo("sample2branchHgRepo.zip");
        string tx = nameof(PullBundleChunk_PullFromTwoBaseRevisionsUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid);
        Api.FinishPullBundle(tx);
        var hashes = _fx.FixtureText("sample2branch2base.hash").Split('|');
        var (assembled, _) = Protocol.PullEntireBundle(Api, "sample2branchHgRepo", hashes, tx);
        Assert.Equal(_fx.Fixture("sample2branch2base.bundle"), assembled);
    }

    [Fact]
    public void PullBundleChunk_2BranchRepoNoChanges_ReturnsNoChange()
    {
        _fx.SeedRepo("sample2branchHgRepo.zip");
        string tx = nameof(PullBundleChunk_2BranchRepoNoChanges_ReturnsNoChange);
        Api.FinishPullBundle(tx);
        var hashes = _fx.FixtureText("sample2branch2tip.hash").Split('|');
        var r = Api.PullBundleChunk("sample2branchHgRepo", hashes, 0, 500, tx);
        Assert.Equal("NOCHANGE", r.Status);
    }

    [Fact]
    public void PullBundleChunk_BaseHashIsZero_ReturnsEntireRepoAsBundle()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_BaseHashIsZero_ReturnsEntireRepoAsBundle);
        Api.FinishPullBundle(tx);
        var (assembled, _) = Protocol.PullEntireBundle(Api, "sampleHgRepo2", new[] { "0" }, tx);
        Assert.Equal(_fx.Fixture("sample_entire.bundle"), assembled);
    }

    [Fact]
    public void PullBundleChunk_EmptyRepositoryReturnsNoChanges()
    {
        _fx.SeedRepo("emptyHgRepo.zip");
        string tx = nameof(PullBundleChunk_EmptyRepositoryReturnsNoChanges);
        Api.FinishPullBundle(tx);
        var r = Api.PullBundleChunk("emptyHgRepo", new[] { "0" }, 0, 50, tx);
        Assert.Equal("NOCHANGE", r.Status);
    }

    [Fact]
    public async Task PullBundleChunk_EmptyRepoWithNonZeroBaseHash_FailsWithoutHanging()
    {
        // Regression: IsValidBase looped forever on an empty (hg init, zero-changeset) repo whenever the
        // requested baseHash was anything other than "0". GetRevisions returns ["0:"] for the empty repo
        // regardless of offset, so the hash is never found and IsValidBase keeps advancing the offset and
        // re-querying forever, hanging the request. Contrast with PullBundleChunk_EmptyRepositoryReturnsNoChanges,
        // which passes baseHash "0" and short-circuits before the loop.
        _fx.SeedRepo("emptyHgRepo.zip");
        string tx = nameof(PullBundleChunk_EmptyRepoWithNonZeroBaseHash_FailsWithoutHanging);
        Api.FinishPullBundle(tx);

        // Run on a background task with a timeout so the bug surfaces as a fast, clear failure rather than
        // hanging until the HTTP client's 120s timeout (or forever, once the fix removes that safety net).
        var call = Task.Run(() => Api.PullBundleChunk("emptyHgRepo", new[] { "fakehash" }, 0, 50, tx));
        var finished = await Task.WhenAny(call, Task.Delay(TimeSpan.FromSeconds(30)));
        Assert.True(finished == call,
            "PullBundleChunk against an empty repo with a non-zero baseHash did not return within 30s — " +
            "IsValidBase is looping forever.");

        // An unknown baseHash is invalid, so the API should reject it the same way it does on a non-empty repo.
        var r = await call;
        Assert.Equal("FAIL", r.Status);
    }

    [Fact]
    public void PullBundleChunk_LongMakeBundle_InProgressCode()
    {
        _fx.SeedRepo("sampleLargeBundleHgRepo.zip");
        string tx = nameof(PullBundleChunk_LongMakeBundle_InProgressCode);
        Api.FinishPullBundle(tx);
        var r = Api.PullBundleChunk("sampleLargeBundleHgRepo", new[] { "0" }, 0, 10000000, tx);
        Assert.Equal("INPROGRESS", r.Status);
    }

    [Fact]
    public void PullBundleChunk_PullUntilFinishedThenRepoChanges_ResetReceivedFromFinishPullBundle()
    {
        _fx.SeedRepo("sampleHgRepo2.zip");
        string tx = nameof(PullBundleChunk_PullUntilFinishedThenRepoChanges_ResetReceivedFromFinishPullBundle);
        Api.FinishPullBundle(tx);
        string hash = _fx.FixtureText("sample.bundle.hash");

        int offset = 0;
        int chunkSize = 50;
        int bundleSize = 1;
        int ctr = 1;
        using var ms = new MemoryStream();
        while (offset < bundleSize)
        {
            if (ctr == 3)
            {
                _fx.AddAndCommit("sampleHgRepo2", "fileToAdd.txt", "sample data to add");
            }
            var r = Protocol.PullFirstChunk(Api, "sampleHgRepo2", new[] { hash }, offset, chunkSize, tx);
            Assert.Equal("SUCCESS", r.Status);
            bundleSize = r.HgrInt("bundleSize");
            chunkSize = r.HgrInt("chunkSize") > 0 ? r.HgrInt("chunkSize") : chunkSize;
            ms.Write(r.Content);
            offset += r.Content.Length;
            ctr++;
        }
        Assert.Equal(_fx.Fixture("sample.bundle"), ms.ToArray());
        var finish = Api.FinishPullBundle(tx);
        Assert.Equal("RESET", finish.Status);
    }
}
