using System.Text.Json.Serialization;
using HgResume.Api;
using HgResume.Api.Manage;
using HgResume.Api.Middleware;

var builder = WebApplication.CreateBuilder(args);
var config = ApiConfig.FromEnvironment(builder.Environment.IsDevelopment());

if (config.RequireManageSecret && string.IsNullOrEmpty(config.ManageSecret))
{
    throw new InvalidOperationException(
        "HGRESUME_MANAGE_SECRET must be set (or HGRESUME_REQUIRE_MANAGE_SECRET=false) to expose /api/manage/*.");
}

// Make the push body cap explicit (and env-overridable) rather than relying on Kestrel's implicit
// 30 MB default — oversize bodies are rejected with a bare 413 before RestDispatcher runs.
builder.WebHost.ConfigureKestrel(options =>
{
    options.Limits.MaxRequestBodySize = config.MaxRequestBodySize;
});

builder.Services.AddSingleton(config);
builder.Services.AddSingleton<HgResumeApi>();
builder.Services.AddSingleton<RestDispatcher>();
builder.Services.AddSingleton<RepoManageService>();
builder.Services.AddSingleton<IRepoManageService>(sp => sp.GetRequiredService<RepoManageService>());
builder.Services.AddHostedService(sp => sp.GetRequiredService<RepoManageService>());
builder.Services.AddExceptionHandler<ApiExceptionHandler>();
builder.Services.AddProblemDetails();
builder.Services.AddValidation();
builder.Services.ConfigureHttpJsonOptions(options =>
    options.SerializerOptions.Converters.Add(new JsonStringEnumConverter()));

var app = builder.Build();

app.UseExceptionHandler();
app.UseStatusCodePages();

app.MapManageRepos();
app.Map("/api/manage/{**rest}", () => TypedResults.NotFound());

var dispatcher = app.Services.GetRequiredService<RestDispatcher>();
// Resume protocol is prefix-agnostic (/api/v03/<method>). Manage routes above take precedence.
app.MapFallback(dispatcher.HandleAsync);

app.Run();

public partial class Program;
