export function resolveAuditConcurrency(value = process.env.PUBLIC_AUDIT_CONCURRENCY) {
  const parsed = Number.parseInt(value ?? '6', 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 6;
}

export async function mapWithConcurrency(items, concurrency, worker) {
  if (!items.length) return [];

  const limit = Math.min(Math.max(1, Math.floor(Number(concurrency) || 1)), items.length);
  const results = new Array(items.length);
  let nextIndex = 0;

  const runWorker = async () => {
    while (true) {
      const index = nextIndex;
      nextIndex += 1;
      if (index >= items.length) return;
      results[index] = await worker(items[index], index);
    }
  };

  await Promise.all(Array.from({ length: limit }, () => runWorker()));
  return results;
}
