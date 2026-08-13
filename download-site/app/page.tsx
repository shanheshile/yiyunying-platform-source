import Home, { type PublicReleaseMetadata } from "./home-client";
import releaseMetadata from "../release-metadata.json";

const PUBLIC_RELEASE_IDS = ["user", "admin", "authorized", "owner"] as const;
type PublicReleaseId = (typeof PUBLIC_RELEASE_IDS)[number];

const publicReleases = PUBLIC_RELEASE_IDS.map((id) => {
  const release = releaseMetadata.releases.find((candidate) => candidate.id === id);
  if (!release) {
    throw new Error(`Missing required public release metadata: ${id}`);
  }
  return release;
});

const publicReleaseMetadata: PublicReleaseMetadata = {
  versionName: releaseMetadata.versionName,
  releaseDate: releaseMetadata.releaseDate,
  downloadRootBase: releaseMetadata.downloadRootBase,
  channel: String(releaseMetadata.channel ?? "Debug"),
  finalizationStatus: String(releaseMetadata.finalizationStatus ?? "pending"),
  releaseTag: String(releaseMetadata.releaseTag ?? ""),
  releaseNotes: Array.isArray(releaseMetadata.releaseNotes)
    ? releaseMetadata.releaseNotes.map(String)
    : String(releaseMetadata.releaseNotes ?? "")
        .split(/[；。]\s*/)
        .map((note) => note.trim())
        .filter(Boolean),
  releases: publicReleases.map((release) => ({
      id: release.id as PublicReleaseId,
      name: release.name,
      shortName: release.shortName,
      audience: release.audience,
      description: release.description,
      fileName: release.fileName,
      packageName: release.packageName,
      versionName: release.versionName,
      sizeBytes: release.sizeBytes,
      size: release.size,
      sha256: release.sha256,
    })),
};

export default function Page() {
  return <Home releaseMetadata={publicReleaseMetadata} />;
}
