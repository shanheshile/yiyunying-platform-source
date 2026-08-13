export function isFormalPublicRelease(metadata) {
  const channel = String(metadata?.channel ?? "Debug");
  const finalizationStatus = String(metadata?.finalizationStatus ?? "pending");
  const releaseEvidenceCommit = String(metadata?.releaseEvidenceCommit ?? "");
  const releaseTag = String(metadata?.releaseTag ?? "").toLowerCase();
  const releases = Array.isArray(metadata?.releases) ? metadata.releases : [];
  const hasDebugMarker =
    releaseTag.includes("debug") ||
    releases.some((release) =>
      [release?.fileName, release?.packageName, release?.versionName].some(
        (value) => String(value ?? "").toLowerCase().includes("debug"),
      ),
    );
  return (
    channel === "Stable" &&
    finalizationStatus === "finalized" &&
    /^[0-9a-f]{40}$/.test(releaseEvidenceCommit.toLowerCase()) &&
    /^v\d+\.\d+\.\d+$/.test(releaseTag) &&
    !hasDebugMarker
  );
}
