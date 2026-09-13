from __future__ import annotations

import shutil
import shlex
from datetime import datetime, timezone
from pathlib import Path

PROFILES = ('fredmourao', 'marinaofaleiro')
WINDOWS_SENTINEL_BEGIN = '# BEGIN SHOPVIVALIZ CODEX NATIVE PROFILE FAILOVER'
WINDOWS_SENTINEL_END = '# END SHOPVIVALIZ CODEX NATIVE PROFILE FAILOVER'


def render_linux_auto_launcher(real_codex: str, engine_path: str) -> str:
    real = shlex.quote(real_codex)
    engine = shlex.quote(engine_path)
    return (
        '#!/bin/bash\n'
        'set -Eeuo pipefail\n'
        f'CODEX_REAL={real}\n'
        'export CODEX_REAL\n'
        f'exec python3 {engine} "$@"\n'
    )


def render_linux_manual_launcher(real_codex: str, profile_home: str) -> str:
    real = shlex.quote(real_codex)
    profile = shlex.quote(profile_home)
    return (
        '#!/bin/bash\n'
        'set -Eeuo pipefail\n'
        f'CODEX_HOME={profile}\n'
        'export CODEX_HOME\n'
        'unset OPENAI_API_KEY CODEX_API_KEY\n'
        f'exec {real} "$@"\n'
    )


def render_windows_auto_launcher(real_codex: str, engine_path: str) -> str:
    real = real_codex.replace("'", "''")
    engine = engine_path.replace("'", "''")
    return (
        "$ErrorActionPreference='Stop'\n"
        f"$env:CODEX_REAL='{real}'\n"
        f"& python.exe '{engine}' @args\n"
        'exit $LASTEXITCODE\n'
    )


def render_windows_manual_launcher(real_codex: str, profile_home: str) -> str:
    real = real_codex.replace("'", "''")
    profile = profile_home.replace("'", "''")
    return (
        "$ErrorActionPreference='Continue'\n"
        f"$env:CODEX_HOME='{profile}'\n"
        "Remove-Item Env:OPENAI_API_KEY -ErrorAction SilentlyContinue\n"
        "Remove-Item Env:CODEX_API_KEY -ErrorAction SilentlyContinue\n"
        f"& '{real}' @args\n"
        'exit $LASTEXITCODE\n'
    )


def patch_windows_scope_guard(text: str, launcher_path: str) -> str:
    if WINDOWS_SENTINEL_BEGIN in text:
        return text
    launcher = launcher_path.replace("'", "''")
    block = (
        f"{WINDOWS_SENTINEL_BEGIN}\n"
        "if($Tool -eq 'codex'){\n"
        f"  & '{launcher}' @ToolArgs\n"
        "  exit $LASTEXITCODE\n"
        "}\n"
        f"{WINDOWS_SENTINEL_END}\n"
    )
    marker = "if($Tool -eq 'codex'){"
    if marker not in text:
        raise ValueError('Windows scope guard Codex block not found')
    return text.replace(marker, block + marker, 1)


def patch_windows_scope_guard_file(path: Path, launcher_path: str) -> None:
    raw = path.read_bytes()
    has_bom = raw.startswith(b'\xef\xbb\xbf')
    text = raw.decode('utf-8-sig')
    newline = '\r\n' if '\r\n' in text else '\n'
    patched = patch_windows_scope_guard(text, launcher_path)
    if newline == '\r\n':
        patched = patched.replace('\r\n', '\n').replace('\n', '\r\n')
    encoded = patched.encode('utf-8')
    if has_bom:
        encoded = b'\xef\xbb\xbf' + encoded
    path.write_bytes(encoded)


def _backup_files(paths: list[Path], backup_dir: Path) -> None:
    backup_dir.mkdir(parents=True, exist_ok=True)
    for path in paths:
        if path.exists() and path.is_file():
            shutil.copy2(path, backup_dir / path.name)


def _validate(home: Path, real_codex: str, source_engine: Path) -> None:
    for profile in PROFILES:
        profile_dir = home / '.codex-business' / profile
        if not profile_dir.is_dir():
            raise FileNotFoundError(f'missing profile directory: {profile}')
    if not Path(real_codex).is_file():
        raise FileNotFoundError('real Codex executable not found')
    if not source_engine.is_file():
        raise FileNotFoundError('native failover engine not found')


def install(
    home: Path,
    platform: str,
    real_codex: str,
    source_engine: Path,
    scope_guard: Path | None = None,
) -> dict:
    home = Path(home)
    source_engine = Path(source_engine)
    _validate(home, real_codex, source_engine)
    runtime = home / '.codex-business'
    runtime.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = runtime / 'backups' / f'native-failover-{stamp}'
    engine_dest = runtime / 'codex-native-profile-failover.py'
    real_path_file = runtime / 'codex-real-path'

    if platform == 'linux':
        bindir = home / '.local' / 'bin'
        bindir.mkdir(parents=True, exist_ok=True)
        active = [bindir / name for name in ('codex', 'codex-auto', 'codex-fred', 'codex-marina')]
        _backup_files(active, backup)
        shutil.copy2(source_engine, engine_dest)
        real_path_file.write_text(real_codex + '\n', encoding='utf-8')
        auto = render_linux_auto_launcher(real_codex, str(engine_dest))
        manual_fred = render_linux_manual_launcher(real_codex, str(runtime / 'fredmourao'))
        manual_marina = render_linux_manual_launcher(real_codex, str(runtime / 'marinaofaleiro'))
        for name in ('codex', 'codex-auto'):
            (bindir / name).write_text(auto, encoding='utf-8')
        (bindir / 'codex-fred').write_text(manual_fred, encoding='utf-8')
        (bindir / 'codex-marina').write_text(manual_marina, encoding='utf-8')
        for path in active:
            path.chmod(0o755)
    elif platform == 'windows':
        bindir = home / '.local' / 'bin'
        bindir.mkdir(parents=True, exist_ok=True)
        auto_path = bindir / 'codex-auto.ps1'
        fred_path = bindir / 'codex-fred.ps1'
        marina_path = bindir / 'codex-marina.ps1'
        guard = scope_guard or (home / 'AppData' / 'Local' / 'ShopVivaliz' / 'ai-cli-scope-guard.ps1')
        _backup_files([auto_path, fred_path, marina_path, guard], backup)
        shutil.copy2(source_engine, engine_dest)
        real_path_file.write_text(real_codex + '\n', encoding='utf-8')
        auto_path.write_text(render_windows_auto_launcher(real_codex, str(engine_dest)), encoding='utf-8')
        fred_path.write_text(render_windows_manual_launcher(real_codex, str(runtime / 'fredmourao')), encoding='utf-8')
        marina_path.write_text(render_windows_manual_launcher(real_codex, str(runtime / 'marinaofaleiro')), encoding='utf-8')
        if guard.exists():
            patch_windows_scope_guard_file(guard, str(auto_path))
    else:
        raise ValueError(f'unsupported platform: {platform}')

    return {'platform': platform, 'backup': str(backup), 'engine': str(engine_dest)}
