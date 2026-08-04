namespace HgResume.SendReceiveTests;

// Verbatim from LexBox backend/Testing/Services/ModifyProjectHelper.cs — byte-patches the fwdata's
// DateModified field so a subsequent send/receive has a real change to transfer.
public static class ModifyProjectHelper
{
    public static void ModifyProject(string projectFilePath)
    {
        using var fileStream = File.Open(projectFilePath, FileMode.Open, FileAccess.ReadWrite);
        var position = FindPosition(fileStream, "DateModified val=\""u8);
        if (position < 0) throw new Exception("could not find DateModified in fwdata");
        var timestampLength = "2023-08-16 09:28:29.436"u8.Length;
        Span<byte> span = stackalloc byte[timestampLength];
        if (fileStream.Read(span) != timestampLength)
        {
            throw new Exception("unable to read data");
        }

        span[3] = span[3] == "2"u8[0] ? "3"u8[0] : "2"u8[0];

        fileStream.Position -= timestampLength;
        fileStream.Write(span);
        fileStream.Flush(true);
    }

    private static long FindPosition(Stream stream, ReadOnlySpan<byte> pattern)
    {
        int b;
        int i = 0;
        while ((b = stream.ReadByte()) != -1)
        {
            if (b == pattern[i])
            {
                if (++i == pattern.Length)
                {
                    return stream.Position - pattern.Length;
                }
            }
            else
            {
                i = 0;
            }
        }

        return -1;
    }
}
