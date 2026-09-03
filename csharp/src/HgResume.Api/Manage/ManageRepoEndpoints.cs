using System.Security.Cryptography;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.AspNetCore.Http.HttpResults;

namespace HgResume.Api.Manage;

public static class ManageRepoEndpoints
{
    private const string SecretHeader = "X-Manage-Secret";

    /// <summary>
    /// Shared-secret gate for the whole group. If <see cref="ApiConfig.ManageSecret"/> isn't
    /// configured, the endpoints are left open (only valid when RequireManageSecret is false, which
    /// is checked once at startup in Program.cs, not per-request here).
    /// </summary>
    private static async ValueTask<object?> RequireManageSecret(
        EndpointFilterInvocationContext context,
        EndpointFilterDelegate next)
    {
        var config = context.HttpContext.RequestServices.GetRequiredService<ApiConfig>();
        if (config.ManageSecret is { Length: > 0 } expected)
        {
            var provided = context.HttpContext.Request.Headers[SecretHeader].ToString();
            if (!CryptographicOperations.FixedTimeEquals(
                    System.Text.Encoding.UTF8.GetBytes(provided),
                    System.Text.Encoding.UTF8.GetBytes(expected)))
            {
                return Results.Unauthorized();
            }
        }

        return await next(context);
    }

    public static RouteGroupBuilder MapManageRepos(this WebApplication app)
    {
        var group = app.MapGroup("/api/manage")
            .WithTags("Manage")
            .AddEndpointFilter(RequireManageSecret);

        group.MapPost("/repos/{code}", InitRepo)
            .WithName("InitRepo")
            .WithSummary("Create an empty Mercurial repo")
            .WithDescription("Runs hg init at {first-letter}/{code} under the primary repo path.");

        group.MapGet("/repos/{code}", GetRepoStatus)
            .WithName("GetRepoStatus")
            .WithSummary("Repo lock and abandoned-transaction flags");

        group.MapPost("/repos/{code}/copy", CopyRepo)
            .WithName("CopyRepo")
            .WithSummary("Replace the destination repo with a copy of the source");

        group.MapDelete("/repos/{code}", DeleteRepo)
            .WithName("DeleteRepo")
            .WithSummary("Delete the repo directory if it exists");

        group.MapGet("/repos/{code}/backup", BackupRepo)
            .WithName("BackupRepo")
            .WithSummary("Zip the repo directory and stream it");

        group.MapPost("/repos/{code}/reset", ResetRepo)
            .WithName("ResetRepo")
            .WithSummary("Soft-delete the repo and replace it with an empty one");

        group.MapPost("/repos/{code}/finish-reset", FinishReset)
            .WithName("FinishReset")
            .WithSummary("Extract a zip over the repo, keeping only the .hg folder")
            .DisableAntiforgery();

        group.MapPost("/repos/{code}/soft-delete", SoftDeleteRepo)
            .WithName("SoftDeleteRepo")
            .WithSummary("Move the repo into the deleted folder");

        group.MapPost("/repos/{code}/invalidate-dir-cache", InvalidateDirCache)
            .WithName("InvalidateDirCache")
            .WithSummary("Nudge the repo directory so NFS clients drop a stale listing");

        group.MapPost("/reset-backups/cleanup", CleanupResetBackups)
            .WithName("CleanupResetBackups")
            .WithSummary("Delete old reset backups from the deleted folder");

        return group;
    }

    private static async Task<Results<Created<RepoStatusResponse>, Conflict, ValidationProblem>> InitRepo(
        string code,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;

        await repos.InitRepo(projectCode, cancellationToken);
        return TypedResults.Created($"/api/manage/repos/{projectCode.Value}", Status(repos, projectCode));
    }

    private static Results<Ok<RepoStatusResponse>, NotFound, ValidationProblem> GetRepoStatus(
        string code,
        IRepoManageService repos)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        if (!repos.RepoExists(projectCode)) return TypedResults.NotFound();
        return TypedResults.Ok(Status(repos, projectCode));
    }

    private static async Task<Results<Created<RepoStatusResponse>, NotFound, Conflict, ValidationProblem>> CopyRepo(
        string code,
        CopyRepoRequest request,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var dest, out var destError)) return destError;
        if (!TryCode(request.SourceCode, out var source, out var sourceError)) return sourceError;

        await repos.CopyRepo(source, dest, cancellationToken);
        return TypedResults.Created($"/api/manage/repos/{dest.Value}", Status(repos, dest));
    }

    private static async Task<Results<NoContent, ValidationProblem>> DeleteRepo(
        string code,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        await repos.DeleteRepoIfExists(projectCode, cancellationToken);
        return TypedResults.NoContent();
    }

    private static Results<PushStreamHttpResult, NotFound, ValidationProblem> BackupRepo(
        string code,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        var executor = repos.BackupRepo(projectCode);
        if (executor is null) return TypedResults.NotFound();

        return TypedResults.Stream(
            stream => executor.ExecuteBackup(stream, cancellationToken),
            contentType: "application/zip",
            fileDownloadName: $"{projectCode.Value}.zip");
    }

    private static async Task<Results<NoContent, ValidationProblem>> ResetRepo(
        string code,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        await repos.ResetRepo(projectCode, cancellationToken);
        return TypedResults.NoContent();
    }

    private static async Task<Results<NoContent, ValidationProblem>> FinishReset(
        string code,
        HttpRequest request,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;

        var max = request.HttpContext.Features.Get<IHttpMaxRequestBodySizeFeature>();
        if (max is { IsReadOnly: false })
        {
            max.MaxRequestBodySize = null;
        }

        // ZipArchive needs a seekable stream (to read the central directory), and request.Body is
        // neither seekable nor safe to read synchronously, so buffer it first.
        using var zipBuffer = new MemoryStream();
        await request.Body.CopyToAsync(zipBuffer, cancellationToken);
        zipBuffer.Position = 0;

        await repos.FinishReset(projectCode, zipBuffer, cancellationToken);
        return TypedResults.NoContent();
    }

    private static async Task<Results<NoContent, ValidationProblem>> SoftDeleteRepo(
        string code,
        SoftDeleteRepoRequest request,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        await repos.SoftDeleteRepo(projectCode, request.Suffix, cancellationToken);
        return TypedResults.NoContent();
    }

    private static async Task<Results<NoContent, ValidationProblem>> InvalidateDirCache(
        string code,
        IRepoManageService repos,
        CancellationToken cancellationToken)
    {
        if (!TryCode(code, out var projectCode, out var error)) return error;
        await repos.InvalidateDirCache(projectCode, cancellationToken);
        return TypedResults.NoContent();
    }

    private static async Task<Ok<CleanupResetBackupsResponse>> CleanupResetBackups(
        IRepoManageService repos,
        CancellationToken cancellationToken,
        bool dryRun = false)
    {
        var deleted = await repos.CleanupResetBackups(dryRun, cancellationToken);
        return TypedResults.Ok(new CleanupResetBackupsResponse(deleted));
    }

    private static RepoStatusResponse Status(IRepoManageService repos, ProjectCode code) =>
        new(repos.HasAbandonedTransactions(code), repos.RepoIsLocked(code));

    private static bool TryCode(string code, out ProjectCode projectCode, out ValidationProblem error)
    {
        if (ProjectCode.TryParse(code, out projectCode))
        {
            error = default!;
            return true;
        }

        error = TypedResults.ValidationProblem(new Dictionary<string, string[]>
        {
            ["code"] = [$"Invalid repo name: {code}."]
        });
        return false;
    }
}
