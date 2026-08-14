import { readFile } from "node:fs/promises";

export const PUBLIC_RELEASE_PROJECTION_KEY =
  "__YIYUNYING_VALIDATED_PUBLIC_RELEASE_PROJECTION__";

const ROLE_ORDER = ["user", "admin", "authorized", "owner"];
const ROLE_IDENTITY = Object.freeze({
  user: ["user", "xyz.jjmxg.yiyunying.user"],
  admin: ["admin", "xyz.jjmxg.yiyunying.admin"],
  authorized: ["authorized-platform", "xyz.jjmxg.yiyunying.authorized"],
  owner: ["platform-owner", "xyz.jjmxg.yiyunying.platformowner"],
});
const IMMUTABLE_FIELDS = [
  "schemaVersion",
  "channel",
  "versionName",
  "versionCode",
  "deviceValidationPlan",
  "buildSourceCommit",
  "releaseTag",
  "releaseIdentitySha256",
  "connectionIdentity",
  "releaseDate",
  "generatedAt",
  "downloadRootBase",
  "releaseNotes",
  "releases",
  "projectAssets",
];
const SHA256_RE = /^[0-9a-f]{64}$/;
const COMMIT_RE = /^[0-9a-f]{40}$/;
const DEVICE_PENDING_NOTICE = "真机验证待用户完成（不得声明真机通过）";
const DEVICE_PASSED_NOTICE = "真机升级验证已由完整证据通过";

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, canonical(value[key])]),
    );
  }
  return value;
}

function exactlyEqual(left, right) {
  return JSON.stringify(canonical(left)) === JSON.stringify(canonical(right));
}

function requireDigest(value, label) {
  const digest = String(value ?? "").toLowerCase();
  if (!SHA256_RE.test(digest)) throw new Error(`${label} must be a SHA-256 digest`);
  return digest;
}

function validateConnectionIdentity(value) {
  const expected = [
    "apiBaseUrl",
    "appKeySha256",
    "authorizedPlatformKeySha256",
    "platformKeySha256",
  ];
  if (!value || typeof value !== "object" || !exactlyEqual(Object.keys(value).sort(), expected)) {
    throw new Error("Final public projection connection identity is invalid");
  }
  const apiUrl = new URL(String(value.apiBaseUrl ?? ""));
  if (apiUrl.protocol !== "https:" || apiUrl.username || apiUrl.password || apiUrl.search || apiUrl.hash) {
    throw new Error("Final public projection requires a canonical HTTPS API identity");
  }
  for (const field of expected.filter((field) => field !== "apiBaseUrl")) {
    requireDigest(value[field], `connectionIdentity.${field}`);
  }
}

function validateFourStableReleases(finalManifest) {
  const releases = finalManifest.releases;
  if (!Array.isArray(releases) || releases.length !== ROLE_ORDER.length) {
    throw new Error("Final public projection requires exactly four APK records");
  }
  const byRole = new Map(releases.map((release) => [release?.id, release]));
  if (byRole.size !== ROLE_ORDER.length) {
    throw new Error("Final public projection contains duplicate APK roles");
  }
  const signerSet = new Set();
  for (const role of ROLE_ORDER) {
    const release = byRole.get(role);
    if (!release || typeof release !== "object") {
      throw new Error(`Final public projection is missing APK role: ${role}`);
    }
    const [stem, packageName] = ROLE_IDENTITY[role];
    const version = finalManifest.versionName;
    if (
      release.fileName !== `yiyunying-${stem}-v${version}.apk` ||
      release.packageName !== packageName ||
      release.versionName !== `${version}-${stem}` ||
      release.versionCode !== finalManifest.versionCode ||
      !Number.isSafeInteger(release.sizeBytes) ||
      release.sizeBytes < 1024 * 1024 ||
      typeof release.size !== "string" ||
      !release.size
    ) {
      throw new Error(`Final public projection APK identity mismatch: ${role}`);
    }
    requireDigest(release.sha256, `${role}.sha256`);
    signerSet.add(requireDigest(release.signerSha256, `${role}.signerSha256`));
  }
  if (signerSet.size !== 1) {
    throw new Error("Final public projection APKs must use one production signer");
  }
  return ROLE_ORDER.map((role) => byRole.get(role));
}

function validateDeviceValidation(finalManifest) {
  const plan = String(finalManifest.deviceValidationPlan ?? "");
  if (!plan) {
    if (finalManifest.versionCode >= 66) {
      throw new Error("Final public projection requires a device validation plan");
    }
    return;
  }
  if (plan !== "device-evidence" && plan !== "risk-waiver") {
    throw new Error("Final public projection device validation plan is invalid");
  }
  const value = finalManifest.deviceValidation;
  const expectedFields = ["evidenceFileName", "evidenceSha256", "plan", "publicNotice", "status"];
  if (
    !value ||
    typeof value !== "object" ||
    !exactlyEqual(Object.keys(value).sort(), expectedFields) ||
    value.plan !== plan
  ) {
    throw new Error("Final public projection device validation evidence is invalid");
  }
  requireDigest(value.evidenceSha256, "deviceValidation.evidenceSha256");
  if (plan === "risk-waiver") {
    if (
      finalManifest.versionName !== "1.0.0" ||
      finalManifest.versionCode !== 66 ||
      value.status !== "pending-user-validation" ||
      value.evidenceFileName !== "release-risk-waiver.json" ||
      value.publicNotice !== DEVICE_PENDING_NOTICE ||
      !Array.isArray(finalManifest.releaseNotes) ||
      !finalManifest.releaseNotes.includes(DEVICE_PENDING_NOTICE) ||
      finalManifest.releaseNotes.some(
        (note) => String(note).includes("真机验证已通过") || note === DEVICE_PASSED_NOTICE,
      )
    ) {
      throw new Error("Risk-waiver release must remain pending user device validation");
    }
    return;
  }
  if (
    value.status !== "passed" ||
    value.evidenceFileName !== "device-upgrade-evidence.json" ||
    finalManifest.releaseNotes?.includes(DEVICE_PENDING_NOTICE)
  ) {
    throw new Error("Device-evidence release validation status is invalid");
  }
}

export function createPublicReleaseProjection(pendingMetadata, finalManifest) {
  if (
    pendingMetadata?.schemaVersion !== 4 ||
    pendingMetadata?.channel !== "Stable" ||
    pendingMetadata?.finalizationStatus !== "pending" ||
    pendingMetadata?.releaseEvidenceCommit !== null ||
    !SHA256_RE.test(String(pendingMetadata?.pendingManifestSha256 ?? ""))
  ) {
    throw new Error("Tracked release metadata is not canonical pending evidence");
  }
  if (
    finalManifest?.schemaVersion !== 4 ||
    finalManifest?.channel !== "Stable" ||
    finalManifest?.finalizationStatus !== "finalized"
  ) {
    return null;
  }
  const version = String(finalManifest.versionName ?? "");
  const buildCommit = String(finalManifest.buildSourceCommit ?? "").toLowerCase();
  const evidenceCommit = String(finalManifest.releaseEvidenceCommit ?? "").toLowerCase();
  if (
    !/^\d+\.\d+\.\d+$/.test(version) ||
    !Number.isSafeInteger(finalManifest.versionCode) ||
    finalManifest.versionCode < 1 ||
    !COMMIT_RE.test(buildCommit) ||
    !COMMIT_RE.test(evidenceCommit) ||
    buildCommit === evidenceCommit ||
    finalManifest.releaseTag !== `v${version}` ||
    finalManifest.downloadRootBase !== "/downloads"
  ) {
    throw new Error("Final public projection release evidence is invalid");
  }
  for (const field of IMMUTABLE_FIELDS) {
    if (!exactlyEqual(pendingMetadata[field], finalManifest[field])) {
      throw new Error(`Final public projection immutable field mismatch: ${field}`);
    }
  }
  validateConnectionIdentity(finalManifest.connectionIdentity);
  const releases = validateFourStableReleases(finalManifest);
  validateDeviceValidation(finalManifest);

  return Object.freeze({
    versionName: finalManifest.versionName,
    releaseDate: finalManifest.releaseDate,
    downloadRootBase: finalManifest.downloadRootBase,
    channel: finalManifest.channel,
    finalizationStatus: finalManifest.finalizationStatus,
    releaseEvidenceCommit: evidenceCommit,
    releaseTag: finalManifest.releaseTag,
    releaseNotes: [...finalManifest.releaseNotes],
    releases: releases.map((release) => ({
      id: release.id,
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
  });
}

export async function loadPublicReleaseProjection(metadataPath, finalManifestPath) {
  const pendingMetadata = JSON.parse(await readFile(metadataPath, "utf8"));
  let finalManifest;
  try {
    finalManifest = JSON.parse(await readFile(finalManifestPath, "utf8"));
  } catch (error) {
    if (error?.code === "ENOENT") return { pendingMetadata, publicRelease: null };
    throw error;
  }
  return {
    pendingMetadata,
    publicRelease: createPublicReleaseProjection(pendingMetadata, finalManifest),
  };
}
