namespace HgResume.Api;

// Mirrors api/src/HgExceptions.php
public class HgException : Exception
{
    public HgException(string message) : base(message) { }
}

public class UnrelatedRepoException : HgException
{
    public UnrelatedRepoException(string message) : base(message) { }
}

public class AsyncRunnerException : Exception
{
    public AsyncRunnerException(string message) : base(message) { }
}

public class BundleHelperException : Exception
{
    public BundleHelperException(string message) : base(message) { }
}

public class ValidationException : Exception
{
    public ValidationException(string message) : base(message) { }
}

public class AlreadyExistsException : Exception
{
    public AlreadyExistsException(string message) : base(message) { }
}

public class ProjectResetException : Exception
{
    public string ErrorCode { get; }

    public static ProjectResetException ZipMissingHgFolder() =>
        new("Zip file does not contain a .hg folder", "ZIP_MISSING_HG_FOLDER");

    private ProjectResetException(string message, string errorCode) : base(message)
    {
        ErrorCode = errorCode;
    }
}
