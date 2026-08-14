import currentReleaseManifest from "../../release-metadata.json";

export const INTERNAL_DOWNLOAD_NOTICE = "仅内部使用 · 仅限本地或受保护网络访问";

const roleOrder = ["user", "admin", "authorized", "owner"] as const;

export type InternalRole = (typeof roleOrder)[number];

export type InternalPackage = Readonly<{
  role: InternalRole;
  roleLabel: string;
  versionName: string;
  versionCode: number;
  status: "Debug 测试" | "Release 候选" | "正式发布";
  size: string;
  sizeBytes: number;
  sha256: string;
  fileName: string;
  downloadHref?: string;
  downloadExpiresAt?: number;
}>;

export type InternalReleaseGroup = Readonly<{
  id: "debug" | "candidate" | "final";
  title: string;
  summary: string;
  packages: readonly InternalPackage[];
  emptyMessage?: string;
}>;

type RawRelease = {
  id?: unknown;
  fileName?: unknown;
  packageName?: unknown;
  versionName?: unknown;
  versionCode?: unknown;
  size?: unknown;
  sizeBytes?: unknown;
  sha256?: unknown;
};

type RawManifest = {
  schemaVersion?: unknown;
  channel?: unknown;
  finalizationStatus?: unknown;
  versionName?: unknown;
  versionCode?: unknown;
  releases?: unknown;
};

const roleLabels: Readonly<Record<InternalRole, string>> = Object.freeze({
  user: "用户端",
  admin: "管理员端",
  authorized: "授权平台（代理）",
  owner: "平台总控（买断）",
});

const expectedFileStem: Readonly<Record<InternalRole, string>> = Object.freeze({
  user: "user",
  admin: "admin",
  authorized: "authorized-platform",
  owner: "platform-owner",
});

const expectedVersionSuffix: Readonly<Record<InternalRole, string>> = Object.freeze({
  user: "user",
  admin: "admin",
  authorized: "authorized-platform",
  owner: "platform-owner",
});

const expectedStablePackageName: Readonly<Record<InternalRole, string>> = Object.freeze({
  user: "xyz.jjmxg.yiyunying.user",
  admin: "xyz.jjmxg.yiyunying.admin",
  authorized: "xyz.jjmxg.yiyunying.authorized",
  owner: "xyz.jjmxg.yiyunying.platformowner",
});

type ExpectedArtifact = Readonly<{
  sizeBytes: number;
  sha256: string;
}>;

const expectedDebugArtifacts: Readonly<Record<InternalRole, ExpectedArtifact>> = Object.freeze({
  user: Object.freeze({ sizeBytes: 96707619, sha256: "4A16C9801726B68DA97F78AB1A740F58CFE8890018756D6DBB775D40B89A2BC7" }),
  admin: Object.freeze({ sizeBytes: 32306003, sha256: "474DAAE37974895988D3AED6D70C127D0438B6676C6D71C78A7799A1626CEA2A" }),
  authorized: Object.freeze({ sizeBytes: 32306007, sha256: "805FE14B89B808FD95EF834C0546337430147FBC6A6FFBF7B2BD47B8D77587F4" }),
  owner: Object.freeze({ sizeBytes: 32306003, sha256: "73489C179E9176E31105ED5003A8915011822E8482966B73EE374D59A1DB7776" }),
});

const expectedDebugReleaseIdentity = Object.freeze({ versionName: "2.7.15", versionCode: 60 });

function requireString(value: unknown, label: string): string {
  if (typeof value !== "string" || value.trim() !== value || value.length === 0) {
    throw new Error(`内部下载白名单字段无效：${label}`);
  }
  return value;
}

function requirePositiveInteger(value: unknown, label: string): number {
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value <= 0) {
    throw new Error(`内部下载白名单字段无效：${label}`);
  }
  return value;
}

function requireRole(value: unknown): InternalRole {
  if (typeof value !== "string" || !roleOrder.includes(value as InternalRole)) {
    throw new Error("内部下载白名单包含未知角色");
  }
  return value as InternalRole;
}

function assertSafeApkIdentity(version: string, fileName: string): void {
  if (!/^\d+\.\d+\.\d+$/.test(version)) {
    throw new Error("内部下载白名单版本格式无效");
  }
  if (!/^yiyunying-[a-z-]+-v\d+\.\d+\.\d+(?:-debug)?\.apk$/.test(fileName)) {
    throw new Error("内部下载白名单文件名无效");
  }
}

function sanitizeRelease(
  raw: RawRelease,
  releaseVersion: string,
  releaseCode: number,
  status: InternalPackage["status"],
  debug: boolean,
): InternalPackage {
  const role = requireRole(raw.id);
  const fileName = requireString(raw.fileName, `${role}.fileName`);
  const packageName = requireString(raw.packageName, `${role}.packageName`);
  const versionName = requireString(raw.versionName, `${role}.versionName`);
  const versionCode = requirePositiveInteger(raw.versionCode, `${role}.versionCode`);
  const sizeBytes = requirePositiveInteger(raw.sizeBytes, `${role}.sizeBytes`);
  const size = requireString(raw.size, `${role}.size`);
  const sha256 = requireString(raw.sha256, `${role}.sha256`);
  const debugSuffix = debug ? "-debug" : "";
  const expectedName = `${releaseVersion}-${expectedVersionSuffix[role]}${debugSuffix}`;
  const expectedFile = `yiyunying-${expectedFileStem[role]}-v${releaseVersion}${debugSuffix}.apk`;
  const expectedPackage = expectedStablePackageName[role] + (debug ? ".debug" : "");
  const expectedArtifact = debug ? expectedDebugArtifacts[role] : undefined;
  const expectedSize = `${(sizeBytes / (1024 * 1024)).toFixed(2)} MB`;

  if (
    versionName !== expectedName ||
    versionCode !== releaseCode ||
    fileName !== expectedFile ||
    packageName !== expectedPackage ||
    (expectedArtifact !== undefined && (
      sizeBytes !== expectedArtifact.sizeBytes ||
      sha256 !== expectedArtifact.sha256
    ))
  ) {
    throw new Error(`内部下载白名单身份不一致：${role}`);
  }
  if (sizeBytes < 1024 * 1024 || size !== expectedSize) {
    throw new Error(`内部下载白名单大小无效：${role}`);
  }
  if (!/^[0-9A-F]{64}$/.test(sha256)) {
    throw new Error(`内部下载白名单 SHA-256 无效：${role}`);
  }
  assertSafeApkIdentity(releaseVersion, fileName);

  return Object.freeze({
    role,
    roleLabel: roleLabels[role],
    versionName,
    versionCode,
    status,
    size,
    sizeBytes,
    sha256,
    fileName,
  });
}

function sanitizeManifest(
  manifest: RawManifest,
  expectedChannel: "Debug" | "Stable",
  expectedFinalization: "pending" | "finalized",
  status: InternalPackage["status"],
): readonly InternalPackage[] {
  if (manifest.channel !== expectedChannel || manifest.finalizationStatus !== expectedFinalization) {
    throw new Error("内部下载白名单发布状态不一致");
  }
  const version = requireString(manifest.versionName, "versionName");
  const code = requirePositiveInteger(manifest.versionCode, "versionCode");
  if (!/^\d+\.\d+\.\d+$/.test(version) || !Array.isArray(manifest.releases)) {
    throw new Error("内部下载白名单发布清单无效");
  }
  if (
    expectedChannel === "Debug" &&
    (version !== expectedDebugReleaseIdentity.versionName || code !== expectedDebugReleaseIdentity.versionCode)
  ) {
    throw new Error("内部下载白名单发布身份不一致");
  }
  if (manifest.releases.length !== roleOrder.length) {
    throw new Error("内部下载白名单必须恰好包含四个角色");
  }
  const byRole = new Map<InternalRole, InternalPackage>();
  for (const raw of manifest.releases as RawRelease[]) {
    const item = sanitizeRelease(raw, version, code, status, expectedChannel === "Debug");
    if (byRole.has(item.role)) {
      throw new Error(`内部下载白名单角色重复：${item.role}`);
    }
    byRole.set(item.role, item);
  }
  return Object.freeze(roleOrder.map((role) => {
    const item = byRole.get(role);
    if (!item) throw new Error(`内部下载白名单缺少角色：${role}`);
    return item;
  }));
}

type StableProjection = Readonly<{
  finalizationStatus: "pending" | "finalized";
  packages: readonly InternalPackage[];
}>;

// The tracked metadata is server-only input. This function reads only the APK
// delivery allowlist, validates it, and creates a new safe browser model. It
// deliberately never spreads or returns the raw manifest or raw release rows.
function projectCurrentStableManifest(manifest: RawManifest): StableProjection {
  if (manifest.schemaVersion !== 4 || manifest.channel !== "Stable") {
    throw new Error("内部下载白名单 Stable 清单无效");
  }
  if (manifest.finalizationStatus !== "pending" && manifest.finalizationStatus !== "finalized") {
    throw new Error("内部下载白名单 Stable 状态无效");
  }
  const finalizationStatus = manifest.finalizationStatus;
  const packages = sanitizeManifest(
    manifest,
    "Stable",
    finalizationStatus,
    finalizationStatus === "pending" ? "Release 候选" : "正式发布",
  );
  return Object.freeze({ finalizationStatus, packages });
}

// Frozen build-time allowlist projected from releases/2.7.15/release-manifest.json.
// Only APK delivery fields are copied here; connection identity and project assets
// are deliberately absent from this server-only catalog.
const debugManifest: RawManifest = Object.freeze({
  channel: "Debug",
  finalizationStatus: "finalized",
  versionName: "2.7.15",
  versionCode: 60,
  releases: Object.freeze([
    Object.freeze({ id: "user", fileName: "yiyunying-user-v2.7.15-debug.apk", packageName: "xyz.jjmxg.yiyunying.user.debug", versionName: "2.7.15-user-debug", versionCode: 60, sizeBytes: 96707619, size: "92.23 MB", sha256: "4A16C9801726B68DA97F78AB1A740F58CFE8890018756D6DBB775D40B89A2BC7" }),
    Object.freeze({ id: "admin", fileName: "yiyunying-admin-v2.7.15-debug.apk", packageName: "xyz.jjmxg.yiyunying.admin.debug", versionName: "2.7.15-admin-debug", versionCode: 60, sizeBytes: 32306003, size: "30.81 MB", sha256: "474DAAE37974895988D3AED6D70C127D0438B6676C6D71C78A7799A1626CEA2A" }),
    Object.freeze({ id: "authorized", fileName: "yiyunying-authorized-platform-v2.7.15-debug.apk", packageName: "xyz.jjmxg.yiyunying.authorized.debug", versionName: "2.7.15-authorized-platform-debug", versionCode: 60, sizeBytes: 32306007, size: "30.81 MB", sha256: "805FE14B89B808FD95EF834C0546337430147FBC6A6FFBF7B2BD47B8D77587F4" }),
    Object.freeze({ id: "owner", fileName: "yiyunying-platform-owner-v2.7.15-debug.apk", packageName: "xyz.jjmxg.yiyunying.platformowner.debug", versionName: "2.7.15-platform-owner-debug", versionCode: 60, sizeBytes: 32306003, size: "30.81 MB", sha256: "73489C179E9176E31105ED5003A8915011822E8482966B73EE374D59A1DB7776" }),
  ]),
});

export function buildInternalDownloadCatalog(): readonly InternalReleaseGroup[] {
  const current = currentReleaseManifest as RawManifest;
  const debugPackages = sanitizeManifest(debugManifest, "Debug", "finalized", "Debug 测试");
  const stable = projectCurrentStableManifest(current);
  const candidatePackages = stable.finalizationStatus === "pending" ? stable.packages : Object.freeze([]);
  const finalPackages = stable.finalizationStatus === "finalized" ? stable.packages : Object.freeze([]);

  return Object.freeze([
    Object.freeze({
      id: "debug" as const,
      title: "Debug 测试包",
      summary: "仅用于内部联调与回归，带 Debug 标识，禁止交付客户或作为正式升级来源。",
      packages: debugPackages,
    }),
    Object.freeze({
      id: "candidate" as const,
      title: "Stable Release 候选",
      summary: "已使用正式包名和生产签名构建，但发布证据仍为 pending，仅供内部验收。",
      packages: candidatePackages,
      emptyMessage: "当前没有待验收的 Stable Release 候选。",
    }),
    Object.freeze({
      id: "final" as const,
      title: "最终正式版",
      summary: "仅展示 finalizationStatus=finalized 的 Stable 四角色 APK；其他状态一律不进入本区。",
      packages: finalPackages,
      emptyMessage: "尚无完成最终签署的正式版；候选包不得对外宣称为正式发布。",
    }),
  ]);
}
