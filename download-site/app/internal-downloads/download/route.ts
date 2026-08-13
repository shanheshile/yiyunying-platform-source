import { getChatGPTUser } from "../../chatgpt-auth";
import { isAuthorizedInternalDownloadUser } from "../authorization.server";
import {
  buildInternalDownloadCatalog,
  type InternalRole,
} from "../catalog.server";
import {
  createSignedInternalDownloadUrl,
  INTERNAL_DOWNLOAD_LINK_TTL_SECONDS,
} from "../signed-links.server";

const SAFE_ROLES = new Set<InternalRole>(["user", "admin", "authorized", "owner"]);

function closedResponse(): Response {
  return new Response(null, {
    status: 404,
    headers: { "Cache-Control": "private, no-store" },
  });
}

export async function GET(request: Request): Promise<Response> {
  const user = await getChatGPTUser();
  if (!user || !isAuthorizedInternalDownloadUser(user)) return closedResponse();

  const requestUrl = new URL(request.url);
  const channel = requestUrl.searchParams.get("channel");
  const role = requestUrl.searchParams.get("role");
  if (
    requestUrl.searchParams.size !== 2 ||
    (channel !== "debug" && channel !== "candidate") ||
    !role ||
    !SAFE_ROLES.has(role as InternalRole)
  ) return closedResponse();

  const group = buildInternalDownloadCatalog().find((entry) => entry.id === channel);
  const item = group?.packages.find((entry) => entry.role === role);
  if (!item) return closedResponse();

  const expiresAt = Math.floor(Date.now() / 1000) + INTERNAL_DOWNLOAD_LINK_TTL_SECONDS;
  const signedUrl = createSignedInternalDownloadUrl(item, channel, expiresAt);
  if (!signedUrl) return closedResponse();

  return new Response(null, {
    status: 302,
    headers: {
      "Cache-Control": "private, no-store",
      Location: signedUrl,
      "Referrer-Policy": "no-referrer",
      "X-Robots-Tag": "noindex, nofollow, noarchive",
    },
  });
}
