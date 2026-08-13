export function isFormalPublicRelease(metadata) {
  const channel = String(metadata?.channel ?? "Debug");
  const releaseTag = String(metadata?.releaseTag ?? "").toLowerCase();
  const releases = Array.isArray(metadata?.releases) ? metadata.releases : [];
  const hasDebugMarker =
    releaseTag.includes("debug") ||
    releases.some((release) =>
      [release?.fileName, release?.packageName, release?.versionName].some(
        (value) => String(value ?? "").toLowerCase().includes("debug"),
      ),
    );
  return channel === "Stable" && !hasDebugMarker;
}
