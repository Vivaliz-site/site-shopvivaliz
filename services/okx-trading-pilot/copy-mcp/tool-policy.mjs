export function isReadTool(tool) {
  const name=String(tool || '');
  return name.startsWith('market_') ||
    name.startsWith('account_get_') ||
    name.startsWith('smartmoney_get_') ||
    name === 'smartmoney_search_trader' ||
    name === 'trade_get_history' ||
    /^(spot|swap|futures|option)_get_/.test(name);
}

export function isWriteTool(tool, runtimeToolMeta = new Map()) {
  const name=String(tool || '');
  const meta=runtimeToolMeta instanceof Map ? runtimeToolMeta.get(name) : undefined;
  return Boolean(meta && (meta.isWrite === true || meta.annotations?.readOnlyHint === false)) &&
    /^(spot|swap|futures)_/.test(name);
}

export function authorizeTool({tool,runtimeTools,runtimeToolMeta,writeEnabled=false}) {
  if (!runtimeTools.has(tool)) return {allowed:false,code:'UPSTREAM_TOOL_UNAVAILABLE'};
  if (isReadTool(tool)) return {allowed:true,code:'READ_ALLOWED'};
  if (isWriteTool(tool,runtimeToolMeta) && writeEnabled) return {allowed:true,code:'WRITE_ALLOWED'};
  return {allowed:false,code:'READ_ONLY'};
}

export function authorizeReadTool({tool,runtimeTools}) {
  return authorizeTool({tool,runtimeTools,writeEnabled:false});
}
