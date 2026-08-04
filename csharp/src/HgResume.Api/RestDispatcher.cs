using System.Text;
using Microsoft.AspNetCore.Http;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/RestServer.php. Dispatches on the last path segment, binds query/body params by
/// name (PHP-style, including baseHashes[] arrays), and writes the X-HgR-* response contract that the
/// Chorus client parses. Prefix-agnostic so /api/v03/&lt;method&gt; keeps working without hardcoding it.
/// </summary>
public sealed class RestDispatcher
{
    private readonly ApiConfig _config;
    private readonly HgResumeApi _api;

    public RestDispatcher(ApiConfig config, HgResumeApi api)
    {
        _config = config;
        _api = api;
    }

    public async Task HandleAsync(HttpContext context)
    {
        string methodName = LastPathSegment(context.Request.Path.Value ?? "");
        byte[] body = await ReadBodyAsync(context.Request);
        var query = context.Request.Query;

        // Always answer with the X-HgR-* contract. PHP RestServer::serverError did the same for
        // missing params / unknown methods; without this catch, BundleHelper's ValidationException
        // (empty/invalid transId) escapes as a bare ASP.NET 500 with no protocol headers.
        HgResumeResponse response;
        try
        {
            response = Dispatch(methodName, query, body);
        }
        catch (Exception e)
        {
            response = ServerError(Truncate(e.Message));
        }

        await SendResponseAsync(context, response);
    }

    private HgResumeResponse Dispatch(string methodName, IQueryCollection query, byte[] body)
    {
        switch (methodName)
        {
            case "pushBundleChunk":
                // PHP maps data <- postData (always present from the body); remaining params are required.
                RequireParams(methodName, query, "repoId", "bundleSize", "offset", "transId");
                return _api.PushBundleChunk(
                    Str(query, "repoId"),
                    PhpInt(Str(query, "bundleSize")),
                    PhpInt(Str(query, "offset")),
                    body,
                    Str(query, "transId"));

            case "pullBundleChunk":
                RequireParams(methodName, query, "repoId", "baseHashes", "offset", "chunkSize", "transId");
                return _api.PullBundleChunk(
                    Str(query, "repoId"),
                    BaseHashes(query),
                    PhpInt(Str(query, "offset")),
                    PhpInt(Str(query, "chunkSize")),
                    Str(query, "transId"));

            case "getRevisions":
                RequireParams(methodName, query, "repoId", "offset", "quantity");
                return _api.GetRevisions(
                    Str(query, "repoId"),
                    PhpInt(Str(query, "offset")),
                    PhpInt(Str(query, "quantity")));

            case "finishPushBundle":
                RequireParams(methodName, query, "transId");
                return _api.FinishPushBundle(Str(query, "transId"));

            case "finishPullBundle":
                RequireParams(methodName, query, "transId");
                return _api.FinishPullBundle(Str(query, "transId"));

            case "isAvailable":
                return _api.IsAvailable();

            default:
                return ServerError($"Unknown method '{methodName}'");
        }
    }

    /// <summary>
    /// Mirrors RestServer::buildOrderedParamArray's required-param check. Presence (not non-emptiness)
    /// is what PHP's array_key_exists tested; empty-but-present values still reach the API.
    /// </summary>
    private static void RequireParams(string method, IQueryCollection query, params string[] names)
    {
        foreach (var name in names)
        {
            bool present = name == "baseHashes"
                ? HasBaseHashesKey(query)
                : query.ContainsKey(name);
            if (!present)
            {
                throw new ValidationException($"param {name} is required for method {method}");
            }
        }
    }

    private static bool HasBaseHashesKey(IQueryCollection query)
    {
        if (query.ContainsKey("baseHashes[]") || query.ContainsKey("baseHashes")) return true;
        foreach (var key in query.Keys)
        {
            if (key.StartsWith("baseHashes[") && key.EndsWith("]")) return true;
        }
        return false;
    }

    /// <summary>Mirrors RestServer::serverError — FAIL with Error header and body text.</summary>
    private static HgResumeResponse ServerError(string msg) =>
        new(HgResumeResponse.FAIL, new Dictionary<string, string> { ["Error"] = msg }, msg);

    private static string Truncate(string s) => s.Length > 1000 ? s.Substring(0, 1000) : s;

    private async Task SendResponseAsync(HttpContext context, HgResumeResponse response)
    {
        var (httpCode, hgrStatus) = MapHgResponse(response.Code);

        var res = context.Response;
        res.StatusCode = httpCode;
        res.Headers["X-HgR-Version"] = response.Version.ToString();
        res.Headers["X-HgR-Status"] = hgrStatus;
        foreach (var kv in response.Values)
        {
            res.Headers["X-HgR-" + UcFirst(kv.Key)] = SanitizeHeader(kv.Value);
        }
        res.ContentType = "application/octet-stream";

        if (response.Content.Length > 0)
        {
            // Set Content-Length explicitly (as the PHP RestServer did). The Chorus client reads the
            // body with a hand-rolled reader that returns EMPTY when there is no Content-Length header
            // (it never falls back to chunked/Transfer-Encoding), so relying on Kestrel's default
            // chunked encoding silently breaks getRevisions and pullBundleChunk for the real client.
            res.ContentLength = response.Content.Length;
            await res.Body.WriteAsync(response.Content);
        }
    }

    // Mirrors RestServer::mapHgResponse. Because INPROGRESS and TIMEOUT share value 9 and INPROGRESS is
    // listed first in the PHP switch, code 9 always maps to 202 / "INPROGRESS" (the 408 branch is dead).
    private static (int HttpCode, string Status) MapHgResponse(int code) => code switch
    {
        HgResumeResponse.SUCCESS => (200, "SUCCESS"),
        HgResumeResponse.RECEIVED => (202, "RECEIVED"),
        HgResumeResponse.RESET => (400, "RESET"),
        HgResumeResponse.UNAUTHORIZED => (401, "UNAUTHORIZED"),
        HgResumeResponse.FAIL => (400, "FAIL"),
        HgResumeResponse.UNKNOWNID => (400, "UNKNOWNID"),
        HgResumeResponse.NOCHANGE => (304, "NOCHANGE"),
        HgResumeResponse.NOTAVAILABLE => (503, "NOTAVAILABLE"),
        HgResumeResponse.INPROGRESS => (202, "INPROGRESS"), // also covers TIMEOUT (== 9)
        _ => throw new Exception($"Unknown response code {code}"),
    };

    private static string LastPathSegment(string path)
    {
        var segments = path.Split('/', StringSplitOptions.RemoveEmptyEntries);
        return segments.Length == 0 ? "" : segments[^1];
    }

    private static async Task<byte[]> ReadBodyAsync(HttpRequest request)
    {
        using var ms = new MemoryStream();
        await request.Body.CopyToAsync(ms);
        return ms.ToArray();
    }

    private static string Str(IQueryCollection query, string key)
    {
        return query.TryGetValue(key, out var v) ? v.ToString() : "";
    }

    // Client emits baseHashes[]=a&baseHashes[]=b (PHP array style). Accept that, plus scalar and
    // indexed forms defensively.
    private static List<string> BaseHashes(IQueryCollection query)
    {
        var result = new List<string>();
        if (query.TryGetValue("baseHashes[]", out var bracketed))
        {
            result.AddRange(bracketed.Where(s => s != null)!.Select(s => s!));
        }
        if (result.Count == 0 && query.TryGetValue("baseHashes", out var scalar))
        {
            result.AddRange(scalar.Where(s => s != null)!.Select(s => s!));
        }
        if (result.Count == 0)
        {
            foreach (var kv in query)
            {
                if (kv.Key.StartsWith("baseHashes[") && kv.Key.EndsWith("]"))
                {
                    result.AddRange(kv.Value.Where(s => s != null)!.Select(s => s!));
                }
            }
        }
        return result;
    }

    /// <summary>Uppercases the first character only, mirroring PHP ucfirst().</summary>
    private static string UcFirst(string s)
    {
        if (string.IsNullOrEmpty(s)) return s;
        return char.ToUpperInvariant(s[0]) + s.Substring(1);
    }

    private static string SanitizeHeader(string value) => value.Replace("\r", " ").Replace("\n", " ");

    /// <summary>
    /// Lenient integer parse mirroring PHP intval() on a query string: leading sign + digits, else 0.
    /// This makes a non-numeric bundleSize collapse to 0 (so offset 0 >= 0 -> FAIL, matching the
    /// PHP invalid-bundleSize test).
    /// </summary>
    private static int PhpInt(string s)
    {
        if (string.IsNullOrEmpty(s)) return 0;
        int i = 0;
        var sb = new StringBuilder();
        if (s[0] == '+' || s[0] == '-')
        {
            sb.Append(s[0]);
            i = 1;
        }
        for (; i < s.Length && char.IsDigit(s[i]); i++)
        {
            sb.Append(s[i]);
        }
        return int.TryParse(sb.ToString(), out var result) ? result : 0;
    }
}
