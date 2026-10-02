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

    // A hg short (12-char) or full (40-char) node id. Anything else cannot be a base revision.
    private static readonly Regex HexHash = new("^[0-9a-fA-F]{1,40}$", RegexOptions.Compiled);
    // hg's "unknown revision" / "unknown revision or ambiguous" abort, meaning the hash simply isn't
    // in this repo (an absent base) — as opposed to a corruption/lock error we must surface.
    private static readonly Regex UnknownRevision =
        new("abort:.*(unknown revision|ambiguous identifier|filtered revision)", RegexOptions.Compiled);

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
    public async Task<bool> FinishValidatingAsync(string filepath, CancellationToken ct = default)
    {
        var asyncRunner = GetValidationRunner(filepath);
        if (await asyncRunner.WaitForIsCompleteAsync(ct))
        {
            string output = await asyncRunner.GetOutputAsync(ct);
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
        // An empty baseHashes list means the same thing as ["0"]: the client has no common base (it
        // cleared its cache, or this is a first sync), so send everything via --all. Without this an
        // empty list produced `hg bundle <file> -t v1` (no --base/--all) → hg aborts → FAIL → the client
        // retries forever. For an empty repo --all yields NOCHANGE upstream; for a non-empty repo it
        // yields a full clone. Both terminate.
        if (baseHashes.Count == 0 || (baseHashes.Count == 1 && baseHashes[0] == "0"))
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

    public async Task<AsyncRunner> MakeBundleAndWaitUntilFinishedAsync(IReadOnlyList<string> baseHashes,
        string bundleFilePath, CancellationToken ct = default)
    {
        var asyncRunner = MakeBundle(baseHashes, bundleFilePath);
        if (!await asyncRunner.WaitForIsCompleteAsync(ct))
        {
            throw new HgException("Error: make bundle failed to complete");
        }
        return asyncRunner;
    }

    // ---- revision inspection --------------------------------------------------------------------

    /// <returns>a baseHash (without branch information)</returns>
    public async Task<string> GetTipAsync(CancellationToken ct = default)
    {
        var revisionArray = await GetRevisionsAsync(0, 1, ct);
        string first = revisionArray[0];
        int colon = first.IndexOf(':');
        return colon >= 0 ? first.Substring(0, colon) : first;
    }

    public async Task<List<string>> GetBranchTipsAsync(CancellationToken ct = default)
    {
        var (branches, branchesExit, branchesErr) = await ProcessRunner.RunAsync(RepoPath, "hg", ["branches"], ct);
        if (branchesExit != 0)
        {
            throw new HgException($"command 'hg branches' failed (exit {branchesExit}): {Truncate(branchesErr)}");
        }
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
            revisionArray.AddRange(await GetRevisionsInternalAsync(0, 1, branchName, ct));
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
    public Task<List<string>> GetRevisionsAsync(int offset, int quantity, CancellationToken ct = default)
        => GetRevisionsInternalAsync(offset, quantity, null, ct);

    private async Task<List<string>> GetRevisionsInternalAsync(int offset, int quantity, string? branch,
        CancellationToken ct = default)
    {
        if (quantity < 1)
        {
            throw new ValidationException("quantity parameter much be larger than 0");
        }
        // Bound each query to the requested window instead of listing the whole history and paging in
        // memory. `hg log -l N` returns the newest N changesets in reverse-revision order — the same
        // prefix the unbounded log produced — so Skip(offset).Take(quantity) yields an identical result
        // while keeping the cost O(offset + quantity) per call rather than O(history). offset is
        // non-negative for every real caller; clamp defensively so a bogus negative offset can't ask hg
        // for a negative limit.
        int window = (offset > 0 ? offset : 0) + quantity;
        // ':' is illegal in branch names (it is used in tags) so we use it to split hash and branch
        string[] args = branch is null
            ? new[] { "log", "-l", window.ToString(), "--template", "{node|short}:{branches}\n" }
            : new[] { "log", "-b", branch, "-l", window.ToString(), "--template", "{node|short}:{branches}\n" };

        var (output, logExit, logErr) = await ProcessRunner.RunAsync(RepoPath, "hg", args, ct);
        if (logExit != 0)
        {
            // A real hg failure (corruption, stale lock, bad branch, fork-failure-under-load). Fail fast
            // with the exit code and stderr rather than the old uninformative "command 'hg log' failed!".
            throw new HgException($"command 'hg log' failed (exit {logExit}): {Truncate(logErr)}");
        }
        if (output.Count == 0)
        {
            var (tip, tipExit, tipErr) = await ProcessRunner.RunAsync(RepoPath, "hg",
                ["tip", "--template", "{rev}:{branches}\n"], ct);
            if (tipExit == 0 && tip.Count == 1 && tip[0].StartsWith("-1"))
            {
                // Empty repo (hg init, zero changesets). At offset 0 we emit '0:<branch>' (from
                // '-1:<branch>') as the sentinel callers expect; past offset 0 there is nothing more,
                // so return empty. Returning the sentinel for every offset would make paginating
                // callers loop forever, since they never see an empty page.
                if (offset > 0)
                {
                    return new List<string>();
                }
                tip[0] = Regex.Replace(tip[0], "^-1", "0");
                return tip;
            }
            // hg log exited 0 with no output but this is not the empty-repo sentinel — surface it rather
            // than silently returning empty.
            throw new HgException(
                $"command 'hg log' returned no revisions (hg tip exit {tipExit}): {Truncate(tipErr)}");
        }
        return output.Skip(offset).Take(quantity).ToList();
    }

    /// <summary>
    /// True if every requested hash is a real revision in this repo (the "0" sentinel is always valid).
    /// Each hash is checked directly with `hg log -r <hash>` — O(k) in the number of hashes — rather
    /// than paging the whole history looking for them (which was O(N) per page, O(N·k) overall, and
    /// spun forever on an empty repo). Hashes are hex-validated and passed as a positional argument, so
    /// there is no revset/shell injection.
    /// </summary>
    public async Task<bool> IsValidBaseAsync(IReadOnlyList<string> hashes, CancellationToken ct = default)
    {
        if (hashes.Count == 1 && hashes[0] == "0")
        {
            return true; // special case indicating revision 0
        }
        foreach (var hash in hashes)
        {
            if (!await RevisionExistsAsync(hash, ct))
            {
                return false;
            }
        }
        return true;
    }

    private async Task<bool> RevisionExistsAsync(string hash, CancellationToken ct)
    {
        // Anything that is not a hg short/long node id can't be a base. Reject it here so it never
        // reaches `hg log -r` as a revset expression.
        if (!HexHash.IsMatch(hash))
        {
            return false;
        }
        var (output, exitCode, stderr) = await ProcessRunner.RunAsync(RepoPath, "hg",
            ["log", "-r", hash, "--template", "{node|short}\n"], ct);
        if (exitCode == 0)
        {
            return output.Count > 0;
        }
        // hg exits non-zero for an unknown/ambiguous revision (a legitimately absent base). Distinguish
        // that from a genuine hg error (corruption, stale lock), which must surface rather than be
        // reported as a merely-invalid base.
        if (UnknownRevision.IsMatch(stderr))
        {
            return false;
        }
        throw new HgException($"command 'hg log -r' failed (exit {exitCode}): {Truncate(stderr)}");
    }

    private static string Truncate(string s) => s.Length > 500 ? s.Substring(0, 500) : s;
}
