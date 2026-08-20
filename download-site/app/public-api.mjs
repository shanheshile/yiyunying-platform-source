export const OFFICIAL_API_BASE_URL = "https://appht.jjmxg.xyz/";
export const SELF_HOSTED_API_BASE_URL_EXAMPLE = "https://api.your-company.example/";
export const SELF_HOSTED_HTTP_API_BASE_URL_EXAMPLE = "http://dev-api.your-company.example/";

export function officialApiUrl(path) {
  const relativePath = String(path ?? "").replace(/^\/+/, "");
  return new URL(relativePath, OFFICIAL_API_BASE_URL).toString();
}
