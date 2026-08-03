# syntax=docker/dockerfile:1

# ---- build -------------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src
COPY src/HgResume.Api/HgResume.Api.csproj src/HgResume.Api/
RUN dotnet restore src/HgResume.Api/HgResume.Api.csproj
COPY src/ src/
RUN dotnet publish src/HgResume.Api/HgResume.Api.csproj -c Release -o /app /p:UseAppHost=false

# ---- runtime -----------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/aspnet:10.0
# mercurial: required for all hg operations (shelled out to, same as the PHP app).
RUN apt-get update \
    && apt-get install -y --no-install-recommends mercurial \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Run as root so we can bind port 80 and freely read/write the repo + cache volumes
# (auth is handled by the surrounding platform, matching the PHP www-data deployment model).
USER root

RUN mkdir -p /var/cache/hgresume /var/vcs/public /var/vcs/private

ENV ASPNETCORE_URLS=http://+:80
ENV HGRESUME_CACHE_PATH=/var/cache/hgresume
ENV HGRESUME_REPO_PATHS="/var/vcs/public;/var/vcs/private"
ENV HGRESUME_MAINTENANCE_FILE=/var/cache/hgresume/maintenance_message.txt

EXPOSE 80
VOLUME /var/cache/hgresume
VOLUME /var/vcs/public

WORKDIR /app
COPY --from=build /app .
ENTRYPOINT ["dotnet", "HgResume.Api.dll"]
