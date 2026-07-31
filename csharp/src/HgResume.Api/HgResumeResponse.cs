using System.Text;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/HgResumeResponse.php.
/// NOTE the const collision that exists in the PHP source: INPROGRESS and TIMEOUT are BOTH 9.
/// The PHP RestServer::mapHgResponse switch lists INPROGRESS (202) before TIMEOUT (408), so a
/// value of 9 always maps to 202 Accepted / "INPROGRESS" and the 408 branch is dead code.
/// PendingResponse() therefore produces a 202, and the v3 Chorus client reads its sow header and
/// resends the last 10 bytes as a poll. We reproduce that behaviour exactly.
/// </summary>
public sealed class HgResumeResponse
{
    public const int SUCCESS = 0;
    public const int RECEIVED = 1;
    public const int RESET = 3;
    public const int UNAUTHORIZED = 4;
    public const int FAIL = 5;
    public const int UNKNOWNID = 6;
    public const int NOCHANGE = 7;
    public const int NOTAVAILABLE = 8;
    public const int INPROGRESS = 9;
    public const int TIMEOUT = 9; // identical to INPROGRESS in c905288 (see class remarks)

    public int Code { get; set; }
    public Dictionary<string, string> Values { get; set; }
    public byte[] Content { get; set; }
    public int Version { get; set; }

    public HgResumeResponse(int code, Dictionary<string, string>? values = null, byte[]? content = null,
        int version = ApiConfig.ApiVersion)
    {
        Code = code;
        Values = values ?? new Dictionary<string, string>();
        Content = content ?? Array.Empty<byte>();
        Version = version;
    }

    public HgResumeResponse(int code, Dictionary<string, string> values, string content,
        int version = ApiConfig.ApiVersion)
        : this(code, values, Encoding.UTF8.GetBytes(content), version)
    {
    }

    /// <summary>
    /// Mirrors HgResumeResponse::PendingResponse. Returns a status the v3 client does not recognise
    /// (mapped to 202) and shrinks the client's next window to 10 bytes via sow = bundleSize - 10.
    /// </summary>
    public static HgResumeResponse PendingResponse(string transId, string note, int bundleSize)
    {
        return new HgResumeResponse(TIMEOUT, new Dictionary<string, string>
        {
            ["transId"] = transId,
            ["Note"] = note,
            // Make the client send only 10 bytes
            ["sow"] = (bundleSize - 10).ToString(),
        });
    }
}
