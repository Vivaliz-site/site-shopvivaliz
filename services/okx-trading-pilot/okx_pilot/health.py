def build_health(*,mode,authenticated,market_fresh,risk_gateway_ok,reconciled,open_risk,daily_stop,kill_switch,error=None):
    return {'mode':mode.value,'authenticated':bool(authenticated),'market_fresh':bool(market_fresh),'risk_gateway_ok':bool(risk_gateway_ok),'reconciled':bool(reconciled),'open_risk':str(open_risk),'daily_stop':bool(daily_stop),'kill_switch':bool(kill_switch),'error':error}
