using System.Text.RegularExpressions;

namespace HgResume.Api.Manage;

/// <summary>
/// Validated Mercurial project code. Same rules as LexBox: lowercase letters, digits, and hyphens,
/// not starting with a hyphen, and not a reserved directory name.
/// </summary>
public readonly partial record struct ProjectCode
{
    public const string DeletedRepoFolder = "_____deleted_____";
    public const string TempRepoFolder = "_____temp_____";
    public static readonly string[] SpecialDirectoryNames = [DeletedRepoFolder, TempRepoFolder];

    private static readonly HashSet<string> InvalidRepoNames =
        new([.. SpecialDirectoryNames, "api"], StringComparer.OrdinalIgnoreCase);

    public ProjectCode(string value)
    {
        if (!TryParse(value, out var parsed))
        {
            throw new ArgumentException($"Invalid repo name: {value}.");
        }

        Value = parsed.Value;
    }

    public string Value { get; }

    public override string ToString() => Value;

    public static implicit operator ProjectCode(string code) => new(code);

    public static bool TryParse(string? value, out ProjectCode code)
    {
        if (string.IsNullOrEmpty(value) || InvalidRepoNames.Contains(value) || !CodeRegex().IsMatch(value))
        {
            code = default;
            return false;
        }

        code = new ProjectCode(value, alreadyValidated: true);
        return true;
    }

    private ProjectCode(string value, bool alreadyValidated)
    {
        _ = alreadyValidated;
        Value = value;
    }

    [GeneratedRegex(@"^[a-z\d][a-z-\d]*$")]
    private static partial Regex CodeRegex();
}
