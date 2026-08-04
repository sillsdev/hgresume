namespace HgResume.HttpTests;

/// <summary>
/// Client-side push/pull loops that mirror how Chorus's HgResumeTransport drives the protocol,
/// including the "poll" behaviour where a 202/INPROGRESS PendingResponse shrinks the window
/// (sow = bundleSize - 10) and the client resends the tail.
/// </summary>
public static class Protocol
{
    /// <summary>
    /// Pushes an entire bundle in chunks, following RECEIVED (advance to sow) and INPROGRESS
    /// (PendingResponse poll) until a terminal status. Returns the terminal response.
    /// </summary>
    public static ApiResponse PushEntireBundle(ApiClient client, string repoId, string transId, byte[] bundle,
        int chunkSize = 50)
    {
        int bundleSize = bundle.Length;
        int sow = 0;
        for (int guard = 0; guard < 2000; guard++)
        {
            byte[] chunk = Slice(bundle, sow, chunkSize);
            var r = client.PushBundleChunk(repoId, bundleSize, sow, chunk, transId);
            switch ((int)r.Http)
            {
                case 200:
                    return r; // SUCCESS
                case 202:
                    // RECEIVED advances sow to newSow; INPROGRESS/PendingResponse sets sow = bundleSize-10
                    sow = r.HgrInt("sow");
                    if (r.Status == "INPROGRESS") Thread.Sleep(300);
                    continue;
                default:
                    return r; // FAIL / RESET / UNKNOWNID / NOTAVAILABLE
            }
        }
        throw new Exception("PushEntireBundle did not terminate");
    }

    /// <summary>Follows INPROGRESS polls after an arbitrary push until a terminal (non-INPROGRESS) status.</summary>
    public static ApiResponse SettlePush(ApiClient client, string repoId, string transId, byte[] bundle,
        ApiResponse response)
    {
        int bundleSize = bundle.Length;
        for (int guard = 0; guard < 120 && response.Status == "INPROGRESS"; guard++)
        {
            Thread.Sleep(500);
            int sow = response.HgrInt("sow");
            response = client.PushBundleChunk(repoId, bundleSize, sow, Slice(bundle, sow, bundleSize - sow), transId);
        }
        return response;
    }

    /// <summary>
    /// Pulls until the assembled bundle reaches bundleSize, polling on INPROGRESS. Returns the assembled
    /// bytes and the last non-INPROGRESS response (e.g. so callers can assert NOCHANGE/FAIL).
    /// </summary>
    public static (byte[] Assembled, ApiResponse Last) PullEntireBundle(ApiClient client, string repoId,
        IReadOnlyList<string> baseHashes, string transId, int chunkSize = 50)
    {
        int offset = 0;
        int bundleSize = 1; // overwritten after the first successful response
        using var ms = new MemoryStream();
        ApiResponse last = default!;
        for (int guard = 0; guard < 100000 && ms.Length < bundleSize; guard++)
        {
            var r = client.PullBundleChunk(repoId, baseHashes, offset, chunkSize, transId);
            last = r;
            if (r.Status == "INPROGRESS")
            {
                Thread.Sleep(500);
                continue;
            }
            if ((int)r.Http != 200)
            {
                return (ms.ToArray(), r); // NOCHANGE / FAIL / UNKNOWNID / RESET
            }
            bundleSize = r.HgrInt("bundleSize");
            ms.Write(r.Content);
            offset += r.Content.Length;
            chunkSize = r.HgrInt("chunkSize") > 0 ? r.HgrInt("chunkSize") : chunkSize;
        }
        return (ms.ToArray(), last);
    }

    /// <summary>Issues a single pull, polling past INPROGRESS until a terminal response.</summary>
    public static ApiResponse PullFirstChunk(ApiClient client, string repoId, IReadOnlyList<string> baseHashes,
        int offset, int chunkSize, string transId)
    {
        for (int guard = 0; guard < 400; guard++)
        {
            var r = client.PullBundleChunk(repoId, baseHashes, offset, chunkSize, transId);
            if (r.Status == "INPROGRESS")
            {
                Thread.Sleep(500);
                continue;
            }
            return r;
        }
        throw new Exception("pull did not produce a terminal response");
    }

    public static byte[] Slice(byte[] data, int offset, int length)
    {
        if (offset >= data.Length || length <= 0) return Array.Empty<byte>();
        int take = Math.Min(length, data.Length - offset);
        var buf = new byte[take];
        Array.Copy(data, offset, buf, 0, take);
        return buf;
    }
}
