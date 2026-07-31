using HgResume.Api;

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

var config = ApiConfig.FromEnvironment();
var api = new HgResumeApi(config);
var dispatcher = new RestDispatcher(config, api);

// Every request (any method, any path) is dispatched on its last path segment, so /api/v03/<method>
// keeps working exactly as the PHP app did. Auth is handled by the surrounding platform.
app.Run(dispatcher.HandleAsync);

app.Run();
