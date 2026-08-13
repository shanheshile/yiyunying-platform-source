import type { ChatGPTUser } from "../chatgpt-auth";

function configuredAllowlist(
  value: string | undefined,
  validator: (entry: string) => boolean,
  normalize: (entry: string) => string = (entry) => entry,
): ReadonlySet<string> {
  return new Set(
    (value ?? "")
      .split(",")
      .map((entry) => normalize(entry.trim()))
      .filter(validator),
  );
}

export function isAuthorizedInternalDownloadUser(user: ChatGPTUser): boolean {
  const allowedEmails = configuredAllowlist(
    process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS,
    (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email),
    (email) => email.toLowerCase(),
  );
  const allowedUserIds = configuredAllowlist(
    process.env.YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS,
    (userId) => /^[^\s,]{3,256}$/.test(userId),
  );

  if (allowedEmails.size === 0 || allowedUserIds.size === 0) return false;
  return (
    allowedEmails.has(user.email.trim().toLowerCase()) &&
    allowedUserIds.has(user.userId)
  );
}
