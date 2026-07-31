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

    public HgResumeResponse PushBundleChunk(string repoId, int bundleSize, int offset, byte[] data, string transId)
    {
        var availability = IsAvailable();
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
                using (var fs = new FileStream(bundle.BundleFileName, FileMode.Append, FileAccess.Write))
                {
                    fs.Write(data, 0, data.Length);
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
                return CompletePushBundle(bundle, hg, transId, bundleSize);
        }

        return new HgResumeResponse(HgResumeResponse.FAIL); // unreachable, mirrors PHP returning null
    }

    private HgResumeResponse CompletePushBundle(BundleHelper bundle, HgRunner hg, string transId, int bundleSize)
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
                    if (hg.FinishValidating(bundleFilePath))
                    {
                        bundle.State = BundleHelper.State_Unbundle;
                        hg.Unbundle(bundleFilePath);
                        goto case BundleHelper.State_Unbundle;
                    }
                    return HgResumeResponse.PendingResponse(transId, "Verification in progress...", bundleSize);

                case BundleHelper.State_Unbundle:
                    var asyncRunner = new AsyncRunner(bundleFilePath);
                    if (asyncRunner.WaitForIsComplete())
                    {
                        if (BundleHelper.BundleOutputHasErrors(asyncRunner.GetOutput()))
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

    public HgResumeResponse PullBundleChunk(string repoId, IReadOnlyList<string> baseHashes, int offset,
        int chunkSize, string transId)
        => PullBundleChunkInternal(repoId, baseHashes, offset, chunkSize, transId, false);

    public HgResumeResponse PullBundleChunkInternal(string repoId, IReadOnlyList<string> baseHashes, int offset,
        int chunkSize, string transId, bool waitForBundleToFinish)
    {
        try
        {
            var availability = IsAvailable();
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
            if (!hg.IsValidBase(baseHashes))
            {
                return Fail("invalid baseHash");
            }

            // If every requested baseHash is a branch tip then no pull is necessary
            var sortedBase = baseHashes.OrderBy(h => h, StringComparer.Ordinal).ToList();
            var branchTips = hg.GetBranchTips();
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
                    ? hg.MakeBundleAndWaitUntilFinished(sortedBase, bundleFilename)
                    : hg.MakeBundle(sortedBase, bundleFilename);
                bundle.SetProp("tip", hg.GetTip());
                bundle.SetProp("repoId", repoId);
                bundle.State = BundleHelper.State_Bundle;
            }

            var response = new HgResumeResponse(HgResumeResponse.SUCCESS);
            switch (bundle.State)
            {
                case BundleHelper.State_Bundle:
                    if (asyncRunner.IsComplete())
                    {
                        if (BundleHelper.BundleOutputHasErrors(asyncRunner.GetOutput()))
                        {
                            return new HgResumeResponse(HgResumeResponse.FAIL, new Dictionary<string, string>
                            {
                                ["Error"] = Truncate(asyncRunner.GetOutput()),
                            });
                        }
                        bundle.State = BundleHelper.State_Downloading;
                    }
                    for (int i = 0; i < 7; i++)
                    {
                        if (CanGetChunkBelowBundleSize(bundleFilename, chunkSize, offset))
                        {
                            byte[] data = GetChunk(bundleFilename, chunkSize, offset);
                            response.Values = new Dictionary<string, string>
                            {
                                ["bundleSize"] = new FileInfo(bundleFilename).Length.ToString(),
                                ["chunkSize"] = data.Length.ToString(),
                                ["transId"] = transId,
                            };
                            response.Content = data;
                            return response; // break out of loop and switch (PHP: break 2)
                        }
                        Thread.Sleep(2000);
                    }
                    response = new HgResumeResponse(HgResumeResponse.INPROGRESS);
                    break;

                case BundleHelper.State_Downloading:
                    byte[] chunk = GetChunk(bundleFilename, chunkSize, offset);
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

    private static byte[] GetChunk(string filename, int chunkSize, int offset)
    {
        var fi = new FileInfo(filename);
        if (!fi.Exists || offset >= fi.Length)
        {
            return Array.Empty<byte>();
        }
        using var fs = new FileStream(filename, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
        fs.Seek(offset, SeekOrigin.Begin);
        int toRead = (int)Math.Min(chunkSize, fs.Length - offset);
        var buffer = new byte[toRead];
        int read = 0;
        while (read < toRead)
        {
            int n = fs.Read(buffer, read, toRead - read);
            if (n == 0) break;
            read += n;
        }
        if (read < toRead) Array.Resize(ref buffer, read);
        return buffer;
    }

    // ---- misc -----------------------------------------------------------------------------------

    public HgResumeResponse GetRevisions(string repoId, int offset, int quantity)
    {
        var availability = IsAvailable();
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
            var revisionList = hg.GetRevisions(offset, quantity);
            return new HgResumeResponse(HgResumeResponse.SUCCESS, new Dictionary<string, string>(),
                string.Join("|", revisionList));
        }
        catch (Exception e)
        {
            return Fail(Truncate(e.Message));
        }
    }

    public HgResumeResponse FinishPushBundle(string transId)
    {
        var bundle = new BundleHelper(_config, transId);
        return bundle.CleanUp()
            ? new HgResumeResponse(HgResumeResponse.SUCCESS)
            : new HgResumeResponse(HgResumeResponse.FAIL);
    }

    public HgResumeResponse FinishPullBundle(string transId)
    {
        var bundle = new BundleHelper(_config, transId);
        if (bundle.HasProp("tip") && bundle.HasProp("repoId"))
        {
            string repoPath = GetRepoPath(bundle.GetProp("repoId"));
            if (Directory.Exists(repoPath))
            {
                var hg = new HgRunner(repoPath);
                // check that the repo has not been updated since the pull started
                if (bundle.GetProp("tip") != hg.GetTip())
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

    public HgResumeResponse IsAvailable()
    {
        if (IsAvailableAsBool())
        {
            return new HgResumeResponse(HgResumeResponse.SUCCESS);
        }
        string message = File.ReadAllText(_config.MaintenanceFilePath, Encoding.UTF8);
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
        if (!string.IsNullOrEmpty(repoId))
        {
            foreach (var basePath in _config.RepoSearchPaths)
            {
                string possibleRepoPath = $"{basePath}/{repoId}";
                if (Directory.Exists(possibleRepoPath))
                {
                    return possibleRepoPath;
                }
            }
        }
        return "";
    }

    private static HgResumeResponse Fail(string error) =>
        new(HgResumeResponse.FAIL, new Dictionary<string, string> { ["Error"] = error });

    private static string Truncate(string s) => s.Length > 1000 ? s.Substring(0, 1000) : s;
}
