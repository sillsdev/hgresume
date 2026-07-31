using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace HgResume.Api;

/// <summary>
/// Mirrors api/src/BundleHelper.php. Per-transaction state machine plus on-disk metadata.
/// Metadata is stored as JSON (the PHP original used serialize()); only in-flight transactions
/// are affected by the format, and clients recover from a lost transaction via RESET.
/// </summary>
public sealed class BundleHelper
{
    public const string State_Start = "Start";
    public const string State_Bundle = "Bundle";
    public const string State_Downloading = "Downloading";
    public const string State_Uploading = "Uploading";
    public const string State_Validating = "Validating";
    public const string State_Unbundle = "Unbundle";

    private static readonly Regex AlphaNumeric = new("^[a-zA-Z0-9_\\-]+$", RegexOptions.Compiled);

    private readonly string _transactionId;
    private readonly string _basePath;

    public BundleHelper(ApiConfig config, string id)
    {
        if (!ValidateAlphaNumeric(id))
        {
            throw new ValidationException($"transId {id} did not validate as alpha numeric!");
        }
        _transactionId = id;
        _basePath = config.CachePath;
    }

    private string GetBundleDir()
    {
        if (!Directory.Exists(_basePath))
        {
            try
            {
                Directory.CreateDirectory(_basePath);
            }
            catch (Exception e)
            {
                throw new BundleHelperException($"Failed to create repo dir: {_basePath} ({e.Message})");
            }
        }
        return _basePath;
    }

    public bool Exists() => File.Exists(BundleFileName);

    /// <summary>Removes the bundle file and the meta file. Always returns true (matches PHP).</summary>
    public bool CleanUp()
    {
        if (File.Exists(BundleFileName)) File.Delete(BundleFileName);
        if (File.Exists(MetaDataFileName)) File.Delete(MetaDataFileName);
        return true;
    }

    /// <summary>Checks hg output. Returns true if error indicators are found. Mirrors PHP verbatim.</summary>
    public static bool BundleOutputHasErrors(string output)
    {
        return output.Contains("abort")
               || output.Contains("invalid")
               || output.Contains("exited with non-zero status 255");
    }

    public string GetBundleBaseFilePath() => Path.Combine(GetBundleDir(), _transactionId);

    public string BundleFileName => GetBundleBaseFilePath() + ".bundle";

    private string MetaDataFileName => GetBundleBaseFilePath() + ".metadata";

    public static bool ValidateAlphaNumeric(string? str) => str != null && AlphaNumeric.IsMatch(str);

    /// <summary>Start of window (aka offset).</summary>
    public int GetOffset()
    {
        var metadata = GetMetadata();
        if (metadata.TryGetValue("offset", out var v) && int.TryParse(v, out var i))
        {
            return i;
        }
        return 0;
    }

    public void SetOffset(int val)
    {
        var metadata = GetMetadata();
        metadata["offset"] = val.ToString();
        SetMetadata(metadata);
    }

    public string State
    {
        get
        {
            var result = GetProp("state");
            return string.IsNullOrEmpty(result) ? State_Start : result;
        }
        set => SetProp("state", value);
    }

    private Dictionary<string, string> GetMetadata()
    {
        if (!File.Exists(MetaDataFileName))
        {
            return new Dictionary<string, string>();
        }
        string json = File.ReadAllText(MetaDataFileName, Encoding.UTF8);
        if (string.IsNullOrWhiteSpace(json))
        {
            return new Dictionary<string, string>();
        }
        return JsonSerializer.Deserialize<Dictionary<string, string>>(json)
               ?? new Dictionary<string, string>();
    }

    private void SetMetadata(Dictionary<string, string> arr)
    {
        GetBundleDir();
        File.WriteAllText(MetaDataFileName, JsonSerializer.Serialize(arr), Encoding.UTF8);
    }

    public string GetProp(string key)
    {
        var metadata = GetMetadata();
        return metadata.TryGetValue(key, out var v) ? v : "";
    }

    public void SetProp(string key, string value)
    {
        var metadata = GetMetadata();
        metadata[key] = value;
        SetMetadata(metadata);
    }

    public bool HasProp(string key) => GetMetadata().ContainsKey(key);
}
