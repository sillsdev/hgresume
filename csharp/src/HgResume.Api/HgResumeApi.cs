using System.Text;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/HgResumeApi.php. The public methods map 1:1 to the REST endpoints. All chunk
/// sizes/offsets are raw byte counts. Behaviour (state machine, status codes, response values) is a
/// faithful port of c905288.
/// </summary>
public sealed class HgResumeApi
{
    private readonly ApiConfig _config;

    public HgResumeApi(ApiConfig config)
    {
        _config = config;
    }

    // ---- push -----------------------------------------------------------------------------------

    public async Task<HgResumeResponse> PushBundleChunkAsync(string repoId, int bundleSize, int offset,
        byte[] data, string transId, CancellationToken ct = default)
    {
        var availability = await IsAvailableAsync(ct);
        if (availability.Code == HgResumeResponse.NOTAVAILABLE)
        {
            return availability;
        }

        string repoPath = GetRepoPath(repoId);
        if (string.IsNullOrEmpty(repoPath))
        {
            return new HgResumeResponse(HgResumeResponse.UNKNOWNID);
        }
        var hg = new HgRunner(repoPath);

        if (offset < 0 || offset >= bundleSize)
        {
            return Fail("invalid offset");
        }
        int dataSize = data.Length;
        if (dataSize == 0)
        {
            return Fail("no data sent");
        }
        if (dataSize > bundleSize - offset)
        {
            return Fail("data sent is larger than remaining bundle size");
        }
        if (bundleSize < 0)
        {
            return Fail("negative bundle size");
        }

        var bundle = new BundleHelper(_config, transId);
        switch (bundle.State)
        {
            case BundleHelper.State_Start:
                bundle.State = BundleHelper.State_Uploading;
                goto case BundleHelper.State_Uploading;

            case BundleHelper.State_Uploading:
                // If the data sent falls before the start of window, mark it received and reply with the
                // correct startOfWindow. Fail on overlap/mismatch after the window.
                int startOfWindow = bundle.GetOffset();
                if (offset != startOfWindow)
                {
                    if (offset < startOfWindow)
                    {
                        return new HgResumeResponse(HgResumeResponse.RECEIVED, new Dictionary<string, string>
                        {
                            ["sow"] = startOfWindow.ToString(),
                            ["Note"] = "server received duplicate data",
                        });
                    }
                    return new HgResumeResponse(HgResumeResponse.FAIL, new Dictionary<string, string>
                    {
                        ["sow"] = startOfWindow.ToString(),
                        ["Error"] = $"data sent ({dataSize}) with offset ({offset}) falls after server's start of window ({startOfWindow})",
                    });
                }

                // write chunk data to bundle file (chunks arrive in order so offset == current length)
                await using (var fs = new FileStream(bundle.BundleFileName, FileMode.Append,
                    FileAccess.Write, FileShare.None, bufferSize: 4096, FileOptions.Asynchronous))
                {
                    await fs.WriteAsync(data, ct);
                }

                int newSow = offset + dataSize;
                bundle.SetOffset(newSow);

                if (newSow != bundleSize)
                {
                    // not the last chunk; expect more
                    return new HgResumeResponse(HgResumeResponse.RECEIVED, new Dictionary<string, string>
                    {
                        ["transId"] = transId,
                        ["sow"] = newSow.ToString(),
                    });
                }
                // got everything -> assemble/apply the bundle
                goto case BundleHelper.State_Validating;

            case BundleHelper.State_Validating:
            case BundleHelper.State_Unbundle:
                return await CompletePushBundleAsync(bundle, hg, transId, bundleSize, ct);
        }

        return new HgResumeResponse(HgResumeResponse.FAIL); // unreachable, mirrors PHP returning null
    }

    private async Task<HgResumeResponse> CompletePushBundleAsync(BundleHelper bundle, HgRunner hg,
        string transId, int bundleSize, CancellationToken ct)
    {
        try
        {
            string bundleFilePath = bundle.BundleFileName;
            switch (bundle.State)
            {
                case BundleHelper.State_Uploading:
                    bundle.State = BundleHelper.State_Validating;
                    hg.StartValidating(bundleFilePath);
                    goto case BundleHelper.State_Validating;

                case BundleHelper.State_Validating:
                    if (await hg.FinishValidatingAsync(bundleFilePath, ct))
                    {
                        bundle.State = BundleHelper.State_Unbundle;
                        hg.Unbundle(bundleFilePath);
                        goto case BundleHelper.State_Unbundle;
                    }
                    return HgResumeResponse.PendingResponse(transId, "Verification in progress...", bundleSize);

                case BundleHelper.State_Unbundle:
                    var asyncRunner = new AsyncRunner(bundleFilePath);
                    if (await asyncRunner.WaitForIsCompleteAsync(ct))
                    {
                        if (BundleHelper.BundleOutputHasErrors(await asyncRunner.GetOutputAsync(ct)))
                        {
                            return new HgResumeResponse(HgResumeResponse.RESET, new Dictionary<string, string>
                            {
                                ["transId"] = transId,
                            });
                        }
                        bundle.CleanUp();
                        asyncRunner.CleanUp();
                        return new HgResumeResponse(HgResumeResponse.SUCCESS, new Dictionary<string, string>
                        {
                            ["transId"] = transId,
                        });
                    }
                    return HgResumeResponse.PendingResponse(transId, "Unpacking in progress...", bundleSize);
            }
            return new HgResumeResponse(HgResumeResponse.FAIL);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            // Client disconnected: don't reset the transaction's offset or fabricate an error
            // response — let the aborted request unwind so the resumable state stays intact.
            throw;
        }
        catch (UnrelatedRepoException e)
        {
            bundle.SetOffset(0);
            return new HgResumeResponse(HgResumeResponse.FAIL, new Dictionary<string, string>
            {
                ["Error"] = Truncate(e.Message),
                ["transId"] = transId,
            });
        }
        catch (Exception e)
        {
            bundle.SetOffset(0);
            return new HgResumeResponse(HgResumeResponse.RESET, new Dictionary<string, string>
            {
                ["Error"] = Truncate(e.Message),
                ["transId"] = transId,
            });
        }
    }

    // ---- pull -----------------------------------------------------------------------------------

    public Task<HgResumeResponse> PullBundleChunkAsync(string repoId, IReadOnlyList<string> baseHashes,
        int offset, int chunkSize, string transId, CancellationToken ct = default)
        => PullBundleChunkInternalAsync(repoId, baseHashes, offset, chunkSize, transId, false, ct);

    public async Task<HgResumeResponse> PullBundleChunkInternalAsync(string repoId,
        IReadOnlyList<string> baseHashes, int offset, int chunkSize, string transId,
        bool waitForBundleToFinish, CancellationToken ct = default)
    {
        try
        {
            var availability = await IsAvailableAsync(ct);
            if (availability.Code == HgResumeResponse.NOTAVAILABLE)
            {
                return availability;
            }

            string repoPath = GetRepoPath(repoId);
            if (string.IsNullOrEmpty(repoPath))
            {
                return new HgResumeResponse(HgResumeResponse.UNKNOWNID);
            }
            if (offset < 0)
            {
                return Fail("invalid offset");
            }

            var hg = new HgRunner(repoPath);
            if (!await hg.IsValidBaseAsync(baseHashes, ct))
            {
                return Fail("invalid baseHash");
            }

            // If every requested baseHash is a branch tip then no pull is necessary
            var sortedBase = baseHashes.OrderBy(h => h, StringComparer.Ordinal).ToList();
            var branchTips = await hg.GetBranchTipsAsync(ct);
            branchTips.Sort(StringComparer.Ordinal);
            if (branchTips.Count == 0)
            {
                return new HgResumeResponse(HgResumeResponse.NOCHANGE);
            }
            if (sortedBase.Count == branchTips.Count)
            {
                bool areEqual = true;
                for (int i = 0; i < sortedBase.Count; i++)
                {
                    if (sortedBase[i] != branchTips[i]) areEqual = false;
                }
                if (areEqual)
                {
                    return new HgResumeResponse(HgResumeResponse.NOCHANGE);
                }
            }

            var bundle = new BundleHelper(_config, transId);
            string bundleFilename = bundle.BundleFileName;
            var asyncRunner = new AsyncRunner(bundleFilename);
            if (!bundle.Exists())
            {
                // Client asked for offset > 0 but the bundle had to be created now -> cache expired.
                if (offset > 0)
                {
                    return new HgResumeResponse(HgResumeResponse.RESET, new Dictionary<string, string>
                    {
                        ["Error"] = "Cannot request data for bundle that doesnt exist yet",
                    });
                }
                // first pull request (offset == 0): make a new bundle
                asyncRunner = waitForBundleToFinish
                    ? await hg.MakeBundleAndWaitUntilFinishedAsync(sortedBase, bundleFilename, ct)
                    : hg.MakeBundle(sortedBase, bundleFilename);
                bundle.SetProp("tip", await hg.GetTipAsync(ct));
                bundle.SetProp("repoId", repoId);
                bundle.State = BundleHelper.State_Bundle;
            }

            var response = new HgResumeResponse(HgResumeResponse.SUCCESS);
            switch (bundle.State)
            {
                case BundleHelper.State_Bundle:
                    if (await asyncRunner.IsCompleteAsync(ct))
                    {
                        string bundleOutput = await asyncRunner.GetOutputAsync(ct);
                        if (BundleHelper.BundleOutputHasErrors(bundleOutput))
                        {
                            return new HgResumeResponse(HgResumeResponse.FAIL, new Dictionary<string, string>
                            {
                                ["Error"] = Truncate(bundleOutput),
                            });
                        }
                        bundle.State = BundleHelper.State_Downloading;
                    }
                    for (int i = 0; i < 7; i++)
                    {
                        if (CanGetChunkBelowBundleSize(bundleFilename, chunkSize, offset))
                        {
                            byte[] data = await GetChunkAsync(bundleFilename, chunkSize, offset, ct);
                            response.Values = new Dictionary<string, string>
                            {
                                ["bundleSize"] = new FileInfo(bundleFilename).Length.ToString(),
                                ["chunkSize"] = data.Length.ToString(),
                                ["transId"] = transId,
                            };
                            response.Content = data;
                            return response; // break out of loop and switch (PHP: break 2)
                        }
                        await Task.Delay(2000, ct);
                    }
                    response = new HgResumeResponse(HgResumeResponse.INPROGRESS);
                    break;

                case BundleHelper.State_Downloading:
                    byte[] chunk = await GetChunkAsync(bundleFilename, chunkSize, offset, ct);
                    long size = new FileInfo(bundleFilename).Length;
                    response.Values = new Dictionary<string, string>
                    {
                        ["bundleSize"] = size.ToString(),
                        ["chunkSize"] = chunk.Length.ToString(),
                        ["transId"] = transId,
                    };
                    response.Content = chunk;
                    if (offset > size)
                    {
                        throw new ValidationException(
                            $"offset {offset} is greater than or equal to bundleSize {size}");
                    }
                    break;
            }

            return response;
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            // Client disconnected: let the abort unwind rather than mapping it to a bogus FAIL.
            throw;
        }
        catch (Exception e)
        {
            return Fail(Truncate(e.Message));
        }
    }

    private static bool CanGetChunkBelowBundleSize(string filename, int chunkSize, int offset)
    {
        var fi = new FileInfo(filename);
        return fi.Exists && offset + chunkSize < fi.Length;
    }

    private static async Task<byte[]> GetChunkAsync(string filename, int chunkSize, int offset,
        CancellationToken ct)
    {
        var fi = new FileInfo(filename);
        if (!fi.Exists || offset >= fi.Length)
        {
            return Array.Empty<byte>();
        }
        await using var fs = new FileStream(filename, FileMode.Open, FileAccess.Read, FileShare.ReadWrite,
            bufferSize: 4096, FileOptions.Asynchronous);
        fs.Seek(offset, SeekOrigin.Begin);
        int toRead = (int)Math.Min(chunkSize, fs.Length - offset);
        var buffer = new byte[toRead];
        int read = 0;
        while (read < toRead)
        {
            int n = await fs.ReadAsync(buffer.AsMemory(read, toRead - read), ct);
            if (n == 0) break;
            read += n;
        }
        if (read < toRead) Array.Resize(ref buffer, read);
        return buffer;
    }

    // ---- misc -----------------------------------------------------------------------------------

    public async Task<HgResumeResponse> GetRevisionsAsync(string repoId, int offset, int quantity,
        CancellationToken ct = default)
    {
        var availability = await IsAvailableAsync(ct);
        if (availability.Code == HgResumeResponse.NOTAVAILABLE)
        {
            return availability;
        }
        try
        {
            string repoPath = GetRepoPath(repoId);
            if (string.IsNullOrEmpty(repoPath))
            {
                return new HgResumeResponse(HgResumeResponse.UNKNOWNID);
            }
            var hg = new HgRunner(repoPath);
            var revisionList = await hg.GetRevisionsAsync(offset, quantity, ct);
            return new HgResumeResponse(HgResumeResponse.SUCCESS, new Dictionary<string, string>(),
                string.Join("|", revisionList));
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception e)
        {
            return Fail(Truncate(e.Message));
        }
    }

    public Task<HgResumeResponse> FinishPushBundleAsync(string transId)
    {
        var bundle = new BundleHelper(_config, transId);
        return Task.FromResult(bundle.CleanUp()
            ? new HgResumeResponse(HgResumeResponse.SUCCESS)
            : new HgResumeResponse(HgResumeResponse.FAIL));
    }

    public async Task<HgResumeResponse> FinishPullBundleAsync(string transId, CancellationToken ct = default)
    {
        var bundle = new BundleHelper(_config, transId);
        if (bundle.HasProp("tip") && bundle.HasProp("repoId"))
        {
            string repoPath = GetRepoPath(bundle.GetProp("repoId"));
            if (Directory.Exists(repoPath))
            {
                var hg = new HgRunner(repoPath);
                // check that the repo has not been updated since the pull started
                if (bundle.GetProp("tip") != await hg.GetTipAsync(ct))
                {
                    bundle.CleanUp();
                    return new HgResumeResponse(HgResumeResponse.RESET);
                }
            }
        }
        return bundle.CleanUp()
            ? new HgResumeResponse(HgResumeResponse.SUCCESS)
            : new HgResumeResponse(HgResumeResponse.FAIL);
    }

    public async Task<HgResumeResponse> IsAvailableAsync(CancellationToken ct = default)
    {
        if (IsAvailableAsBool())
        {
            return new HgResumeResponse(HgResumeResponse.SUCCESS);
        }
        string message = await File.ReadAllTextAsync(_config.MaintenanceFilePath, Encoding.UTF8, ct);
        return new HgResumeResponse(HgResumeResponse.NOTAVAILABLE, new Dictionary<string, string>(), message);
    }

    private bool IsAvailableAsBool()
    {
        string file = _config.MaintenanceFilePath;
        if (File.Exists(file) && new FileInfo(file).Length > 0)
        {
            return false;
        }
        return true;
    }

    private string GetRepoPath(string repoId)
    {
        // Client-supplied repoId is joined onto each configured search root. Reject anything that is
        // not a single path segment (e.g. "../other") so callers cannot escape those roots.
        if (!IsSafeRepoId(repoId))
        {
            return "";
        }

        foreach (var basePath in _config.RepoSearchPaths)
        {
            string possibleRepoPath = Path.Combine(basePath, repoId);
            if (Directory.Exists(possibleRepoPath))
            {
                return possibleRepoPath;
            }
        }
        return "";
    }

    /// <summary>
    /// True only when <paramref name="repoId"/> is a single directory name under a search root.
    /// Uses Path.GetFileName: if stripping directory parts changes the value, the input had path data.
    /// </summary>
    private static bool IsSafeRepoId(string repoId)
    {
        if (string.IsNullOrEmpty(repoId) || repoId is "." or "..")
        {
            return false;
        }
        return Path.GetFileName(repoId) == repoId;
    }

    private static HgResumeResponse Fail(string error) =>
        new(HgResumeResponse.FAIL, new Dictionary<string, string> { ["Error"] = error });

    private static string Truncate(string s) => s.Length > 1000 ? s.Substring(0, 1000) : s;
}
