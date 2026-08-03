using Xunit;

namespace HgResume.HttpTests;

/// <summary>getRevisions, availability/maintenance, and wire-contract smoke checks.</summary>
[Collection("server")]
public sealed class MiscFacts
{
    private readonly ServerFixture _fx;
    private ApiClient Api => _fx.Client;

    public MiscFacts(ServerFixture fx) => _fx = fx;

    [Fact]
    public void GetRevisions_2BranchRepo_ReturnsTwoBranches()
    {
        _fx.SeedRepo("sample2branchHgRepo.zip");
        var r = Api.GetRevisions("sample2branchHgRepo", 0, 50);
        Assert.Equal("SUCCESS", r.Status);

        var branches = new HashSet<string>();
        foreach (var revision in r.Text.Split('|'))
        {
            var hashAndBranch = revision.Split(':');
            if (hashAndBranch.Length >= 2) branches.Add(hashAndBranch[1]);
        }
        Assert.Equal(2, branches.Count);
    }

    [Fact]
    public void GetRevisions_BogusId_UnknownCode()
    {
        _fx.SeedRepo("sampleHgRepo.zip");
        var r = Api.GetRevisions("fakeid", 0, 50);
        Assert.Equal("UNKNOWNID", r.Status);
    }

    [Fact]
    public void IsAvailable_NoMessageFile_SuccessCode()
    {
        _fx.ClearMaintenance();
        var r = Api.IsAvailable();
        Assert.Equal("SUCCESS", r.Status);
        Assert.Equal(200, (int)r.Http);
    }

    [Fact]
    public void IsAvailable_MessageFileExists_NotAvailableWithMessage()
    {
        const string message = "Server is down for maintenance.";
        try
        {
            _fx.SetMaintenance(message);
            var r = Api.IsAvailable();
            Assert.Equal("NOTAVAILABLE", r.Status);
            Assert.Equal(503, (int)r.Http);
            Assert.Equal(message, r.Text);
        }
        finally
        {
            _fx.ClearMaintenance();
        }
    }

    [Fact]
    public void ResponseContract_HeadersAndContentType()
    {
        _fx.ClearMaintenance();
        var r = Api.IsAvailable();
        Assert.Equal("3", r.Hgr("version"));
        Assert.Equal("SUCCESS", r.Hgr("status"));
        Assert.Contains("application/octet-stream", r.Headers["content-type"]);
    }

    [Fact]
    public void FinishPushBundle_MissingTransId_FailWithProtocolHeaders()
    {
        // PHP RestServer::buildOrderedParamArray rejected this before the API ran.
        var r = Api.GetRaw("finishPushBundle", "");
        Assert.Equal(400, (int)r.Http);
        Assert.Equal("FAIL", r.Status);
        Assert.Equal("3", r.Hgr("version"));
        Assert.Contains("transId", r.Hgr("error"));
        Assert.Contains("required", r.Hgr("error"));
    }

    [Fact]
    public void FinishPushBundle_EmptyTransId_FailWithProtocolHeaders()
    {
        // BundleHelper throws ValidationException for empty/non-alphanumeric transId; must not be a bare 500.
        var r = Api.FinishPushBundle("");
        Assert.Equal(400, (int)r.Http);
        Assert.Equal("FAIL", r.Status);
        Assert.Equal("3", r.Hgr("version"));
        Assert.False(string.IsNullOrEmpty(r.Hgr("error")));
    }

    [Fact]
    public void FinishPullBundle_InvalidTransId_FailWithProtocolHeaders()
    {
        var r = Api.FinishPullBundle("bad.id");
        Assert.Equal(400, (int)r.Http);
        Assert.Equal("FAIL", r.Status);
        Assert.Equal("3", r.Hgr("version"));
        Assert.Contains("alpha numeric", r.Hgr("error"));
    }

    [Fact]
    public void UnknownMethod_FailWithProtocolHeaders()
    {
        var r = Api.GetRaw("notARealMethod", "");
        Assert.Equal(400, (int)r.Http);
        Assert.Equal("FAIL", r.Status);
        Assert.Contains("Unknown method", r.Hgr("error"));
    }

    [Fact]
    public void GetRevisions_MissingRepoId_FailWithProtocolHeaders()
    {
        var r = Api.GetRaw("getRevisions", "?offset=0&quantity=50");
        Assert.Equal(400, (int)r.Http);
        Assert.Equal("FAIL", r.Status);
        Assert.Contains("repoId", r.Hgr("error"));
        Assert.Contains("required", r.Hgr("error"));
    }
}
