using HgResume.Api;

var builder = WebApplication.CreateBuilder(args);
var config = ApiConfig.FromEnvironment();

// Make the push body cap explicit (and env-overridable) rather than relying on Kestrel's implicit
// 30 MB default — oversize bodies are rejected with a bare 413 before RestDispatcher runs.
builder.WebHost.ConfigureKestrel(options =>
{
    options.Limits.MaxRequestBodySize = config.MaxRequestBodySize;
});

var app = builder.Build();

var api = new HgResumeApi(config);
var dispatcher = new RestDispatcher(config, api);

// Every request (any method, any path) is dispatched on its last path segment, so /api/v03/<method>
// keeps working exactly as the PHP app did. Auth is handled by the surrounding platform.
app.Run(dispatcher.HandleAsync);

app.Run();
