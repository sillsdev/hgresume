using System.IO.Compression;
using Chorus.VcsDrivers.Mercurial;
using FluentAssertions;
using SIL.Progress;
using Xunit;
using Xunit.Abstractions;

namespace HgResume.IntegrationTests;

/// <summary>
/// The main LexBox e2e send/receive tests (SendReceiveServiceTests.cs) adapted to run against our C#
/// hgresume container using the real Chorus resumable client. Auth/reset/hgweb-only cases are dropped;
/// LexBox project registration is replaced by `hg init` in the container.
/// </summary>
[Collection("server")]
public class SendReceiveTests
{
    private static readonly string BasePath = Path.Join(Path.GetTempPath(), "hgresume_sr_tests");
    private static readonly SendReceiveAuth Auth = new("test", "test"); // server ignores auth

    private readonly ITestOutputHelper _output;
    private readonly ServerFixture _server;
    private readonly MercurialService _sr;

    public SendReceiveTests(ITestOutputHelper output, ServerFixture server)
    {
        _output = output;
        _server = server;
        _sr = new MercurialService(output);
    }

    [Theory]
    [InlineData(HgProtocol.Resumable)]
    public async Task ModifyProjectData(HgProtocol protocol)
    {
        var code = NewCode();
        var project = InitLocalFlexProjectWithRepo(code);
        _server.InitServerRepo(code);

        var srp = new SendReceiveParams(protocol, _server.HostPort, project);

        // Push the fresh project to the server
        _sr.SendReceiveProject(srp, Auth);

        var tipAfterFirstPush = await _server.GetServerTip(code);
        _output.WriteLine($"server tip after first push: {tipAfterFirstPush}");
        tipAfterFirstPush.Should().NotBeNullOrEmpty();
        tipAfterFirstPush.Should().NotBe("0", "the project should have been pushed to the server");

        // Modify
        new FileInfo(srp.FwDataFile).Length.Should().BeGreaterThan(0);
        ModifyProjectHelper.ModifyProject(srp.FwDataFile);

        // Push changes
        _sr.SendReceiveProject(srp, Auth, "Modify project data automated test");

        // The push should have advanced the server tip
        var tipAfterModify = await _server.GetServerTip(code);
        _output.WriteLine($"server tip after modify: {tipAfterModify}");
        tipAfterModify.Should().NotBe(tipAfterFirstPush, "the modify should have produced a new commit on the server");
    }

    [Fact]
    public async Task CloneProject()
    {
        // Push a project to the server first so there is something to clone.
        var code = NewCode();
        var project = InitLocalFlexProjectWithRepo(code);
        _server.InitServerRepo(code);
        var srp = new SendReceiveParams(HgProtocol.Resumable, _server.HostPort, project);
        _sr.SendReceiveProject(srp, Auth);
        (await _server.GetServerTip(code)).Should().NotBe("0", "the project should have been pushed to the server");

        // Clone it into a fresh directory over the resumable protocol.
        var cloneDir = Path.Join(BasePath, $"{code}-clone");
        if (Directory.Exists(cloneDir)) Directory.Delete(cloneDir, true);
        var cloneParams = new SendReceiveParams(HgProtocol.Resumable, _server.HostPort, new ProjectPath(code, cloneDir));
        var clonedTo = _sr.CloneProject(cloneParams, Auth, cloneDir);

        // The cloned working directory should contain the fwdata, byte-identical to what we pushed.
        var clonedFwData = Path.Join(clonedTo, $"{code}.fwdata");
        File.Exists(clonedFwData).Should().BeTrue($"clone at {clonedTo} should contain the fwdata");
        new FileInfo(clonedFwData).Length.Should().Be(new FileInfo(project.FwDataFile).Length);
        File.ReadAllBytes(clonedFwData).SequenceEqual(File.ReadAllBytes(project.FwDataFile))
            .Should().BeTrue("the cloned fwdata should match the pushed fwdata");
    }

    [Fact]
    public async Task SendNewProject()
    {
        await SendNewProjectOfSize(25, 5);
    }

    private async Task SendNewProjectOfSize(int totalSizeMb, int fileCount)
    {
        var code = NewCode();
        var project = InitLocalFlexProjectWithRepo(code);
        _server.InitServerRepo(code);

        var srp = new SendReceiveParams(HgProtocol.Resumable, _server.HostPort, project);

        // add a bunch of large files as separate commits so the resumable push is large
        var progress = new NullProgress();
        for (var i = 1; i <= fileCount; i++)
        {
            var fileName = $"test-file{i}.bin";
            WriteFile(Path.Combine(srp.Dir, fileName), totalSizeMb / fileCount);
            HgRunner.Run($"hg add {fileName}", srp.Dir, 30, progress);
            HgRunner.Run($"""hg commit -m "large file commit {i}" """, srp.Dir, 30, progress)
                .ExitCode.Should().Be(0);
        }

        var srResult = _sr.SendReceiveProject(srp, Auth);
        _output.WriteLine(srResult);

        var tip = await _server.GetServerTip(code);
        tip.Should().NotBeNullOrEmpty();
        tip.Should().NotBe("0", "the large project should have been pushed to the server");
    }

    // ---- helpers (adapted from LexBox IntegrationFixture / Utils) --------------------------------

    private static string NewCode()
    {
        // MUST contain "resumable" so Chorus selects its resumable transport.
        var shortId = Guid.NewGuid().ToString().Split('-')[0];
        return $"sr-resumable-{shortId}-dev-flex";
    }

    private ProjectPath InitLocalFlexProjectWithRepo(string code)
    {
        var dir = Path.Join(BasePath, code);
        if (Directory.Exists(dir)) Directory.Delete(dir, true);
        Directory.CreateDirectory(dir);

        string templateZip = Path.Combine(AppContext.BaseDirectory, "test-template-repo.zip");
        ZipFile.ExtractToDirectory(templateZip, dir);
        File.Move(Path.Join(dir, "kevin-test-01.fwdata"), Path.Join(dir, $"{code}.fwdata"));

        var project = new ProjectPath(code, dir);
        File.Exists(project.FwDataFile).Should().BeTrue();
        return project;
    }

    private static void WriteFile(string path, int sizeMb)
    {
        var random = new Random();
        using var file = File.Open(path, FileMode.Create);
        Span<byte> buffer = stackalloc byte[1024 * 1024];
        for (var i = 0; i < sizeMb; i++)
        {
            random.NextBytes(buffer);
            file.Write(buffer);
        }
        file.Flush(true);
    }
}
