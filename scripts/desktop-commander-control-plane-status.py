#!/usr/bin/env python3
import argparse
import json
from pathlib import Path

SAFE_KEYS = (
    'host', 'state', 'watchdog', 'canonical_agent_count', 'auth_required',
    'last_success', 'last_recovery', 'run_id'
)
VALID_STATES = {'healthy', 'degraded', 'unreachable'}


def normalize_row(raw):
    if not isinstance(raw, dict):
        raise ValueError('host status must be an object')
    row = {key: raw.get(key) for key in SAFE_KEYS}
    row['host'] = str(row.get('host') or 'unknown')[:80]
    state = str(row.get('state') or 'degraded').lower()
    row['state'] = state if state in VALID_STATES else 'degraded'
    row['watchdog'] = str(row.get('watchdog') or 'unknown')[:80]
    try:
        row['canonical_agent_count'] = max(0, int(row.get('canonical_agent_count') or 0))
    except (TypeError, ValueError):
        row['canonical_agent_count'] = 0
    value = row.get('auth_required')
    if isinstance(value, str):
        value = value.strip().lower() in {'true', '1', 'yes'}
    row['auth_required'] = bool(value)
    for key in ('last_success', 'last_recovery', 'run_id'):
        row[key] = str(row.get(key) or 'none')[:120]
    return row


def normalize(payload):
    if not isinstance(payload, list):
        raise ValueError('input must be a list')
    return [normalize_row(item) for item in payload]


def render_markdown(rows):
    lines = [
        '## Desktop Commander 24h Control Plane Status',
        '',
        '| Host | State | Watchdog | Agents | Auth required | Last success | Last recovery | Run |',
        '| --- | --- | --- | ---: | --- | --- | --- | --- |',
    ]
    for row in rows:
        cells = [
            row['host'], row['state'], row['watchdog'], str(row['canonical_agent_count']),
            str(row['auth_required']).lower(), row['last_success'], row['last_recovery'], row['run_id']
        ]
        cells = [cell.replace('|', '/') for cell in cells]
        lines.append('| ' + ' | '.join(cells) + ' |')
    return '\n'.join(lines) + '\n'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--json-out', required=True)
    parser.add_argument('--markdown-out')
    args = parser.parse_args()
    payload = json.loads(Path(args.input).read_text(encoding='utf-8'))
    rows = normalize(payload)
    Path(args.json_out).write_text(json.dumps(rows, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    if args.markdown_out:
        Path(args.markdown_out).write_text(render_markdown(rows), encoding='utf-8')


if __name__ == '__main__':
    main()
