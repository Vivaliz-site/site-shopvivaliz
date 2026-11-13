import { createHash } from 'node:crypto';

// Preserve failure evidence without leaking signed URLs or embedded credentials.
export function safeImageFailure({ url, status, error } = {}) {
  let host = 'invalid-url';
  let pathSha256 = '';
  let extension = '';
  try {
    const parsed = new URL(String(url));
    if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('invalid-scheme');
    host = parsed.hostname;
    pathSha256 = createHash('sha256').update(parsed.pathname).digest('hex').slice(0, 16);
    extension = (parsed.pathname.match(/\.(png|jpe?g|webp|avif|gif|svg)$/i) || [])[1] || '';
  } catch {
    // Raw URL may carry credentials, so do not include it on malformed input.
  }
  const result = { host, pathSha256, extension };
  if (Number.isInteger(status)) result.status = status;
  if (error) result.error = /^net::ERR_[A-Z0-9_]+$/.test(error) ? error : 'request-failed';
  return result;
}
