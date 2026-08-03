using System.Text.RegularExpressions;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/HgRunner.php. All Mercurial operations shell out to the `hg` binary; stdout is
/// parsed verbatim (same templates/regexes as the PHP original) so behaviour matches byte-for-byte.
/// The PHP code chdir()'d into the repo before each call; we pass WorkingDirectory instead.
/// </summary>
public sealed class HgRunner
{
    private static readonly Regex UnknownParent = new("abort:.*unknown parent", RegexOptions.Compiled);
    private static readonly Regex ParentMinusOne = new("parent:\\s*-1:", RegexOptions.Compiled);
    private static readonly Regex NotAMercurialBundle = new("abort:.*not a Mercurial bundle", RegexOptions.Compiled);

    public string RepoPath { get; }

    public HgRunner(string repoPath)
    {
        if (!Directory.Exists(repoPath))
        {
            throw new ValidationException($"repo '{repoPath}' doesn't exist!");
        }
        RepoPath = repoPath;
    }

    // ---- push side: two-phase validate then unbundle --------------------------------------------

    public void StartValidating(string filepath)
    {
        if (!File.Exists(filepath))
        {
            throw new HgException($"bundle file '{filepath}' is not a file!");
        }
        // run hg incoming to make sure this bundle is related to the repo
        GetValidationRunner(filepath).Run(RepoPath, "hg", "incoming", filepath);
    }

    /// <returns>true if validation finished, otherwise false (still running)</returns>
    public bool FinishValidating(string filepath)
    {
        var asyncRunner = GetValidationRunner(filepath);
        if (asyncRunner.WaitForIsComplete())
        {
            string output = asyncRunner.GetOutput();
            if (UnknownParent.IsMatch(output))
            {
                throw new UnrelatedRepoException("Project is unrelated!  (unrelated bundle pushed to repo)");
            }
            if (ParentMinusOne.IsMatch(output))
            {
                throw new UnrelatedRepoException("Project is unrelated!  (unrelated bundle pushed to repo)");
            }
            if (NotAMercurialBundle.IsMatch(output))
            {
                throw new HgException("Project cannot be updated!  (corrupt bundle pushed to repo)");
            }
            return true;
        }
        return false;
    }

    private static AsyncRunner GetValidationRunner(string filepath) => new(filepath + ".incoming");

    public AsyncRunner Unbundle(string filepath)
    {
        if (!File.Exists(filepath))
        {
            throw new HgException($"bundle file '{filepath}' is not a file!");
        }
        var asyncRunner = new AsyncRunner(filepath);
        asyncRunner.Run(RepoPath, "hg", "unbundle", filepath);
        return asyncRunner;
    }

    public AsyncRunner Update(string revision = "")
    {
        var asyncRunner = new AsyncRunner(Path.Combine(RepoPath, "hg_update"));
        if (string.IsNullOrEmpty(revision))
        {
            asyncRunner.Run(RepoPath, "hg", "update");
        }
        else
        {
            asyncRunner.Run(RepoPath, "hg", "update", revision);
        }
        return asyncRunner;
    }

    // ---- pull side: bundle creation -------------------------------------------------------------

    public AsyncRunner MakeBundle(IReadOnlyList<string> baseHashes, string bundleFilePath)
    {
        var args = new List<string> { "bundle" };
        if (baseHashes.Count == 1 && baseHashes[0] == "0")
        {
            args.Add("--all");
            args.Add(bundleFilePath);
        }
        else
        {
            foreach (var hash in baseHashes)
            {
                args.Add("--base");
                args.Add(hash);
            }
            args.Add(bundleFilePath);
        }
        args.Add("-t");
        args.Add("v1");

        var asyncRunner = new AsyncRunner(bundleFilePath);
        asyncRunner.Run(RepoPath, "hg", args.ToArray());
        return asyncRunner;
    }

    public AsyncRunner MakeBundleAndWaitUntilFinished(IReadOnlyList<string> baseHashes, string bundleFilePath)
    {
        var asyncRunner = MakeBundle(baseHashes, bundleFilePath);
        if (!asyncRunner.WaitForIsComplete())
        {
            throw new HgException("Error: make bundle failed to complete");
        }
        return asyncRunner;
    }

    // ---- revision inspection --------------------------------------------------------------------

    /// <returns>a baseHash (without branch information)</returns>
    public string GetTip()
    {
        var revisionArray = GetRevisions(0, 1);
        string first = revisionArray[0];
        int colon = first.IndexOf(':');
        return colon >= 0 ? first.Substring(0, colon) : first;
    }

    public List<string> GetBranchTips()
    {
        var (branches, _) = ProcessRunner.RunSync(RepoPath, "hg", "branches");
        var revisionArray = new List<string>();
        foreach (var branch in branches)
        {
            string branchName;
            if (branch.Length == 0)
            {
                branchName = "default";
            }
            else
            {
                int space = branch.IndexOf(' ');
                branchName = space >= 0 ? branch.Substring(0, space) : branch;
            }
            revisionArray.AddRange(GetRevisionsInternal(0, 1, branchName));
        }
        var revisions = new List<string>();
        foreach (var hashAndBranch in revisionArray)
        {
            int colon = hashAndBranch.IndexOf(':');
            revisions.Add(colon >= 0 ? hashAndBranch.Substring(0, colon) : hashAndBranch);
        }
        return revisions;
    }

    /// <summary>Returns "hash:branch" pairs, e.g. 'fb7a8f23394d:default'.</summary>
    public List<string> GetRevisions(int offset, int quantity) => GetRevisionsInternal(offset, quantity, null);

    private List<string> GetRevisionsInternal(int offset, int quantity, string? branch)
    {
        if (quantity < 1)
        {
            throw new ValidationException("quantity parameter much be larger than 0");
        }
        // ':' is illegal in branch names (it is used in tags) so we use it to split hash and branch
        string[] args = branch is null
            ? new[] { "log", "--template", "{node|short}:{branches}\n" }
            : new[] { "log", "-b", branch, "--template", "{node|short}:{branches}\n" };

        var (output, _) = ProcessRunner.RunSync(RepoPath, "hg", args);
        if (output.Count == 0)
        {
            var (tip, _) = ProcessRunner.RunSync(RepoPath, "hg", "tip", "--template", "{rev}:{branches}\n");
            if (tip.Count == 1 && tip[0].StartsWith("-1"))
            {
                // Empty repo (hg init, zero changesets). At offset 0 we emit '0:<branch>' (from
                // '-1:<branch>') as the sentinel callers expect; past offset 0 there is nothing more,
                // so return empty. Returning the sentinel for every offset would make paginating
                // callers (e.g. IsValidBase) loop forever, since they never see an empty page.
                if (offset > 0)
                {
                    return new List<string>();
                }
                tip[0] = Regex.Replace(tip[0], "^-1", "0");
                return tip;
            }
            throw new HgException($"command 'hg log' failed!\n");
        }
        return output.Skip(offset).Take(quantity).ToList();
    }

    public bool IsValidBase(IReadOnlyList<string> hashes)
    {
        if (hashes.Count == 1 && hashes[0] == "0")
        {
            return true; // special case indicating revision 0
        }
        int foundHash = 0;
        const int q = 200;
        int i = 0;
        while (foundHash < hashes.Count)
        {
            var revisions = GetRevisions(i, q);
            if (revisions.Count == 0)
            {
                return false; // paged past the last revision without matching every hash
            }
            foreach (var hashAndBranch in revisions)
            {
                int colon = hashAndBranch.IndexOf(':');
                string rev = colon >= 0 ? hashAndBranch.Substring(0, colon) : hashAndBranch;
                if (hashes.Contains(rev))
                {
                    foundHash++;
                    if (foundHash >= hashes.Count) break;
                }
            }
            // A page shorter than the requested quantity means hg returned everything it had, so this
            // was the last page. Stop rather than advancing the offset again: this guarantees the loop
            // terminates even if GetRevisions ever returns a fixed non-empty page regardless of offset
            // (the empty-repo '0:' sentinel bug, or any similar future quirk).
            if (revisions.Count < q)
            {
                break;
            }
            i += q;
        }
        return foundHash >= hashes.Count;
    }
}
