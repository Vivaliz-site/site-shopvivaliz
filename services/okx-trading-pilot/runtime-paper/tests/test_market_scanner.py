from decimal import Decimal
from datetime import datetime, timezone, timedelta
from okx_pilot.market import OkxPublicMarketClient
from okx_pilot.scanner import MarketScanner


def test_market_parser_uses_decimal_and_scanner_is_dynamic():
    now = datetime.now(timezone.utc)
    payload = {"data":[
        {"instId":"AAA-USDT","instType":"SPOT","last":"10","bidPx":"9.99","askPx":"10.01","volCcy24h":"100000","ts":str(int(now.timestamp()*1000))},
        {"instId":"BBB-USDT","instType":"SPOT","last":"20","bidPx":"19.8","askPx":"20.2","volCcy24h":"5000","ts":str(int(now.timestamp()*1000))},
    ]}
    snaps = OkxPublicMarketClient.parse_tickers(payload)
    assert isinstance(snaps[0].last, Decimal)
    scanner = MarketScanner(max_candidates=12, max_age_seconds=5)
    ranked = scanner.rank(snaps, now=now)
    assert [x.instrument for x in ranked] == ["AAA-USDT", "BBB-USDT"]


def test_scanner_rejects_stale_or_zero_volume():
    now = datetime.now(timezone.utc)
    old = now - timedelta(seconds=10)
    payload = {"data":[
        {"instId":"OLD-USDT","instType":"SPOT","last":"10","bidPx":"9.99","askPx":"10.01","volCcy24h":"1000","ts":str(int(old.timestamp()*1000))},
        {"instId":"ZERO-USDT","instType":"SPOT","last":"10","bidPx":"9.99","askPx":"10.01","volCcy24h":"0","ts":str(int(now.timestamp()*1000))},
    ]}
    snaps = OkxPublicMarketClient.parse_tickers(payload)
    assert MarketScanner(max_age_seconds=5).rank(snaps, now=now) == ()
