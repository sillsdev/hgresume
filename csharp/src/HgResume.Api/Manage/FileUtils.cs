using System.Globalization;
using System.Runtime.InteropServices;

namespace HgResume.Api.Manage;

internal static class FileUtils
{
    private static readonly string TimestampPattern =
        DateTimeFormatInfo.InvariantInfo.SortableDateTimePattern.Replace(':', '-');

    public static string ToTimestamp(DateTimeOffset dateTime) =>
        dateTime.ToUniversalTime().ToString(TimestampPattern);

    public static DateTimeOffset? ToDateTimeOffset(string timestamp) =>
        DateTimeOffset.TryParseExact(timestamp, TimestampPattern, null, DateTimeStyles.AssumeUniversal, out var dateTime)
            ? dateTime
            : null;

    public static void CopyFilesRecursively(DirectoryInfo source, DirectoryInfo target, UnixFileMode? permissions = null)
    {
        if (permissions.HasValue && RuntimeInformation.IsOSPlatform(OSPlatform.Linux))
            target.UnixFileMode = permissions.Value;

        foreach (var dir in source.EnumerateDirectories())
        {
            CopyFilesRecursively(dir, target.CreateSubdirectory(dir.Name), permissions);
        }

        foreach (var file in source.EnumerateFiles())
        {
            var destFile = file.CopyTo(Path.Combine(target.FullName, file.Name));
            if (permissions.HasValue && RuntimeInformation.IsOSPlatform(OSPlatform.Linux))
                destFile.UnixFileMode = permissions.Value;
        }
    }
}
