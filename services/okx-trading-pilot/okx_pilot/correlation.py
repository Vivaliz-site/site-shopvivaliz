def cluster_id(instrument_id: str, direction: str) -> str:
    parts = instrument_id.split('-')
    underlying = parts[0] if parts else instrument_id
    d = direction.lower().strip()
    if d not in {'long','short'}:
        raise ValueError('direction must be long or short')
    return f'{underlying}:{d}'
