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
