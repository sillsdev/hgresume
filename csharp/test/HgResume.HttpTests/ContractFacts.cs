using System.Net.Sockets;
using System.Text;
using Xunit;

namespace HgResume.HttpTests;

/// <summary>
/// Wire-contract regression tests that the higher-level ApiClient (which uses HttpClient) cannot catch,
/// because HttpClient transparently handles chunked transfer encoding. The real Chorus client uses a
/// hand-rolled response reader (Chorus WebResponseHelper.ReadResponseContent) that returns EMPTY content
/// when the response has no Content-Length header — so every body response MUST send Content-Length and
/// must NOT rely on chunked encoding, exactly as the PHP server did.
/// </summary>
[Collection("server")]
public sealed class ContractFacts
{
    private readonly ServerFixture _fx;

    public ContractFacts(ServerFixture fx) => _fx = fx;

    [Fact]
    public void ResponseWithBody_SetsContentLength_AndIsNotChunked()
    {
        _fx.SeedRepo("sample-hg-repo2.zip");

        var uri = new Uri(_fx.BaseUrl);
        string raw = RawGet(uri.Host, uri.Port,
            "/api/v03/getRevisions?offset=0&quantity=50&repoId=sample-hg-repo2");

        int sep = raw.IndexOf("\r\n\r\n", StringComparison.Ordinal);
        Assert.True(sep > 0, "malformed HTTP response: no header/body separator");
        string headers = raw[..sep].ToLowerInvariant();
        string body = raw[(sep + 4)..];

        // Without Content-Length the Chorus client reads an empty body, which breaks getRevisions/pull.
        Assert.Contains("content-length:", headers);
        Assert.DoesNotContain("transfer-encoding: chunked", headers);
        Assert.False(string.IsNullOrEmpty(body), "expected a non-empty revision list body");
    }

    private static string RawGet(string host, int port, string path)
    {
        using var client = new TcpClient(host, port);
        using var stream = client.GetStream();
        string req = $"GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n";
        byte[] bytes = Encoding.ASCII.GetBytes(req);
        stream.Write(bytes, 0, bytes.Length);
        using var ms = new MemoryStream();
        stream.CopyTo(ms);
        return Encoding.UTF8.GetString(ms.ToArray());
    }
}
