using System.Net;
using System.Text;

namespace HgResume.HttpTests;

/// <summary>Parsed HTTP response, exposing the X-HgR-* protocol headers the Chorus client reads.</summary>
public sealed record ApiResponse(HttpStatusCode Http, IReadOnlyDictionary<string, string> Headers, byte[] Content)
{
    public string Status => Hgr("status");
    public string Hgr(string name) => Headers.TryGetValue("x-hgr-" + name.ToLowerInvariant(), out var v) ? v : "";
    public int HgrInt(string name) => int.TryParse(Hgr(name), out var i) ? i : 0;
    public string Text => Encoding.UTF8.GetString(Content);
}

/// <summary>
/// Talks to a running hgresume server exactly like the Chorus client does: /api/v03/{method} with a
/// GET (no body) or POST text/plain (raw chunk), building the same query string (offset, chunkSize,
/// bundleSize, quantity, transId, repoId, baseHashes[]). Query values are intentionally NOT escaped
/// to match HgResumeApiParameters.BuildQueryString.
/// </summary>
public sealed class ApiClient
{
    private readonly HttpClient _http;
    private readonly string _baseUrl;

    public ApiClient(string baseUrl)
    {
        _baseUrl = baseUrl.TrimEnd('/');
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(120) };
    }

    private ApiResponse Send(HttpMethod verb, string method, string query, byte[]? body)
    {
        string url = $"{_baseUrl}/api/v03/{method}{query}";
        using var req = new HttpRequestMessage(verb, url);
        if (body is not null)
        {
            var content = new ByteArrayContent(body);
            content.Headers.TryAddWithoutValidation("Content-Type", "text/plain");
            req.Content = content;
        }
        using var resp = _http.Send(req, HttpCompletionOption.ResponseContentRead);
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var h in resp.Headers) headers[h.Key.ToLowerInvariant()] = string.Join(",", h.Value);
        foreach (var h in resp.Content.Headers) headers[h.Key.ToLowerInvariant()] = string.Join(",", h.Value);
        byte[] bytes = resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult();
        return new ApiResponse(resp.StatusCode, headers, bytes);
    }

    public ApiResponse IsAvailable() => Send(HttpMethod.Get, "isAvailable", "", null);

    public ApiResponse PushBundleChunk(string repoId, int bundleSize, int offset, byte[] data, string transId)
        => PushBundleChunkRaw(repoId, bundleSize.ToString(), offset, data, transId);

    /// <summary>Push with the bundleSize sent verbatim (used to exercise a non-numeric bundleSize).</summary>
    public ApiResponse PushBundleChunkRaw(string repoId, string bundleSize, int offset, byte[] data, string transId)
        => Send(HttpMethod.Post, "pushBundleChunk",
            $"?offset={offset}&bundleSize={bundleSize}&transId={transId}&repoId={repoId}", data);

    public ApiResponse PullBundleChunk(string repoId, IEnumerable<string> baseHashes, int offset, int chunkSize,
        string transId)
    {
        var sb = new StringBuilder($"?offset={offset}&chunkSize={chunkSize}&transId={transId}");
        foreach (var h in baseHashes) sb.Append($"&baseHashes[]={h}");
        sb.Append($"&repoId={repoId}");
        return Send(HttpMethod.Get, "pullBundleChunk", sb.ToString(), null);
    }

    public ApiResponse GetRevisions(string repoId, int offset, int quantity)
        => Send(HttpMethod.Get, "getRevisions", $"?offset={offset}&quantity={quantity}&repoId={repoId}", null);

    public ApiResponse FinishPushBundle(string transId)
        => Send(HttpMethod.Get, "finishPushBundle", $"?transId={transId}", null);

    public ApiResponse FinishPullBundle(string transId)
        => Send(HttpMethod.Get, "finishPullBundle", $"?transId={transId}", null);
}
