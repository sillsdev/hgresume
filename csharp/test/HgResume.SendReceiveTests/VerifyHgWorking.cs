using Chorus;
using Chorus.VcsDrivers.Mercurial;
using FluentAssertions;
using Xunit;
using Xunit.Abstractions;

namespace HgResume.SendReceiveTests;

// Mirrors LexBox's SendReceiveServiceTests.VerifyHgWorking: confirms the bundled Chorus Mercurial
// is present and usable in this project before we exercise send/receive.
public class VerifyHgWorking
{
    private readonly ITestOutputHelper _output;

    public VerifyHgWorking(ITestOutputHelper output) => _output = output;

    [Fact]
    public void BundledMercurialIsUsable()
    {
        string hg = MercurialLocation.PathToHgExecutable;
        _output.WriteLine("hg path: " + hg);
        (File.Exists(hg) || File.Exists(hg + ".exe")).Should().BeTrue($"expected bundled hg at {hg}");
        HgRepository.GetEnvironmentReadinessMessage("en").Should().BeNull();
    }
}
