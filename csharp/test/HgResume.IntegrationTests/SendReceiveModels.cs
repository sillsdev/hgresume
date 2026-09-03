namespace HgResume.IntegrationTests;

// Trimmed copies of the LexBox test models (backend/Testing/Services), with the LexBox server/API
// coupling removed. Only the Resumable protocol is relevant here (that's what our hgresume serves).
public enum HgProtocol
{
    Hgweb,
    Resumable
}

public record SendReceiveAuth(string Username, string Password);

public record ProjectPath(string Code, string Dir)
{
    public string FwDataFile { get; } = Path.Join(Dir, $"{Code}.fwdata");
}

public record SendReceiveParams(string ProjectCode, string BaseUrl, string Dir) : ProjectPath(ProjectCode, Dir)
{
    public SendReceiveParams(HgProtocol protocol, string baseUrl, ProjectPath project)
        : this(project.Code, baseUrl, project.Dir) { }
}
