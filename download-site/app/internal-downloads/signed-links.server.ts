import { createHmac } from "node:crypto";
import type { InternalPackage, InternalReleaseGroup } from "./catalog.server";

export const INTERNAL_DOWNLOAD_LINK_TTL_SECONDS = 5 * 60;

const INTERNAL_DOWNLOAD_PATH_PREFIX = "/__internal-apks";
const signingSecretPattern = /^[0-9a-f]{64}$/;

type SigningConfiguration = Readonly<{
  origin: string;
  secret: string;
}>;

function readSigningConfiguration(): SigningConfiguration | undefined {
  const secret = process.env.YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET ?? "";
  const origin = process.env.YIYUNYING_INTERNAL_DOWNLOAD_ORIGIN ?? "";

  if (!signingSecretPattern.test(secret) || origin.trim() !== origin) return undefined;

  try {
    const parsed = new URL(origin);
    if (
      parsed.protocol !== "https:" ||
      parsed.username !== "" ||
      parsed.password !== "" ||
      parsed.pathname !== "/" ||
      parsed.search !== "" ||
      parsed.hash !== ""
    ) return undefined;

    return Object.freeze({ origin: parsed.origin, secret });
  } catch {
    return undefined;
  }
}

export function hasInternalDownloadSigningConfiguration(): boolean {
  return readSigningConfiguration() !== undefined;
}

export function createSignedInternalDownloadUrl(
  item: InternalPackage,
  channel: "debug" | "candidate",
  expiresAt: number,
): string | undefined {
  const configuration = readSigningConfiguration();
  if (!configuration || !Number.isSafeInteger(expiresAt) || expiresAt <= 0) return undefined;
  const releaseVersion = item.fileName.match(/-v(\d+\.\d+\.\d+)(?:-debug)?\.apk$/)?.[1];
  if (!releaseVersion || item.versionName.split("-")[0] !== releaseVersion) {
    throw new Error("内部下载签名身份不一致");
  }

  const pathname = `${INTERNAL_DOWNLOAD_PATH_PREFIX}/${channel}/${releaseVersion}/${item.fileName}`;
  const signature = createHmac("sha256", Buffer.from(configuration.secret, "hex"))
    .update(`${expiresAt}\n${pathname}`, "utf8")
    .digest("base64url");
  const downloadUrl = new URL(pathname, configuration.origin);
  downloadUrl.searchParams.set("sig", signature);
  downloadUrl.searchParams.set("expires", String(expiresAt));

  return downloadUrl.href;
}

export function attachInternalDownloadActionLinks(
  groups: readonly InternalReleaseGroup[],
): readonly InternalReleaseGroup[] {
  if (!hasInternalDownloadSigningConfiguration()) return groups;

  return Object.freeze(groups.map((group) => {
    if (group.id !== "debug" && group.id !== "candidate") return group;
    return Object.freeze({
      ...group,
      packages: Object.freeze(group.packages.map((item) => Object.freeze({
        ...item,
        downloadHref: `/internal-downloads/download?channel=${group.id}&role=${item.role}`,
      }))),
    });
  }));
}
