using Chorus.Model;
using Chorus.VcsDrivers;
using Chorus.VcsDrivers.Mercurial;
using SIL.Progress;
using Xunit.Abstractions;

namespace HgResume.SendReceiveTests;

/// <summary>
/// Drives the REAL Chorus resumable client (HgResumeTransport, via HgRepository) against our hgresume
/// container. This is the same transport LexBox's SendReceiveService exercises; we bypass LfMergeBridge
/// only because its FLEx `FixFwData` fixup step (a client-side .fwdata normaliser that never touches the
/// server) requires the full FieldWorks stack. The resumable protocol the server sees is identical.
///
/// The repo URL must contain the substring "resumable" so Chorus picks its resumable transport
/// (RepositoryAddress.IsKnownResumableRepository). Our server ignores auth, but the Chorus client still
/// requires non-empty credentials, which we set via ServerSettingsModel.SaveUserSettings().
/// </summary>
public class MercurialService
{
    private readonly ITestOutputHelper _output;

    public MercurialService(ITestOutputHelper output) => _output = output;

    private StringBuilderProgress NewProgress() => new()
    {
        ProgressIndicator = new NullProgressIndicator(),
        ShowVerbose = true,
    };

    /// <summary>Commit any working-directory changes, then send/receive with the server (pull then push).</summary>
    public string SendReceiveProject(SendReceiveParams p, SendReceiveAuth auth, string commitMessage = "Testing")
    {
        SaveCredentials(auth);
        var progress = NewProgress();
        string repoUrl = RepoUrl(p);

        // Commit working changes (e.g. the fwdata) so there is a changeset to push.
        HgRunner.Run("hg addremove", p.Dir, 120, progress);
        HgRunner.Run($"""hg --config ui.username=LexBox commit -m "{commitMessage}" """, p.Dir, 120, progress);

        var repo = new HgRepository(p.Dir, progress);
        var address = RepositoryAddress.Create("LexBox", repoUrl);
        try
        {
            repo.Pull(address, repoUrl);
            repo.Push(address, repoUrl);
        }
        catch (Exception e)
        {
            _output.WriteLine($"Send/Receive threw: {e}");
            _output.WriteLine("--- Chorus progress ---\n" + progress.Text);
            throw new Exception($"Send/Receive failed: {e.Message}\n--- Chorus progress ---\n{progress.Text}", e);
        }

        _output.WriteLine(progress.Text);
        return progress.Text;
    }

    /// <summary>
    /// Clone the server repo into destDir (exercises the resumable pull path). Returns the actual
    /// clone directory (Chorus may adjust it if destDir already exists).
    /// </summary>
    public string CloneProject(SendReceiveParams p, SendReceiveAuth auth, string destDir)
    {
        SaveCredentials(auth);
        var progress = NewProgress();
        string repoUrl = RepoUrl(p);
        var address = RepositoryAddress.Create("LexBox", repoUrl);
        try
        {
            string clonedTo = HgRepository.Clone(address, destDir, progress);
            _output.WriteLine(progress.Text);
            return clonedTo;
        }
        catch (Exception e)
        {
            _output.WriteLine($"Clone threw: {e}");
            _output.WriteLine("--- Chorus progress ---\n" + progress.Text);
            throw new Exception($"Clone failed: {e.Message}\n--- Chorus progress ---\n{progress.Text}", e);
        }
    }

    private static string RepoUrl(SendReceiveParams p) => $"http://{p.BaseUrl}/{p.Code}";

    private static void SaveCredentials(SendReceiveAuth auth)
    {
        new ServerSettingsModel
        {
            Username = string.IsNullOrEmpty(auth.Username) ? "test" : auth.Username,
            RememberPassword = false,
            Password = string.IsNullOrEmpty(auth.Password) ? "test" : auth.Password,
        }.SaveUserSettings();
    }
}
