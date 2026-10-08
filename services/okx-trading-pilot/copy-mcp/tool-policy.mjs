export function isReadTool(tool) {
  const name=String(tool || '');
  return name.startsWith('market_') ||
    name.startsWith('account_get_') ||
    name.startsWith('smartmoney_get_') ||
    name === 'smartmoney_search_trader' ||
    name === 'trade_get_history' ||
    /^(spot|swap|futures|option)_get_/.test(name);
}

export function authorizeReadTool({tool,runtimeTools}) {
  if (!isReadTool(tool)) return {allowed:false,code:'READ_ONLY'};
  if (!runtimeTools.has(tool)) return {allowed:false,code:'UPSTREAM_TOOL_UNAVAILABLE'};
  return {allowed:true,code:'READ_ALLOWED'};
}
