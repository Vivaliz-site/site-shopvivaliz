const REPORTABLE_TYPES = new Set(['document', 'stylesheet', 'script', 'image', 'font']);

export function reportableResourceFailure({ url, status, resourceType, baseUrl }) {
  if (!Number.isFinite(status) || status < 400) return false;
  if (!REPORTABLE_TYPES.has(resourceType)) return false;
  try {
    return new URL(url).origin === new URL(baseUrl).origin;
  } catch {
    return false;
  }
}
