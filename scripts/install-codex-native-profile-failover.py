from __future__ import annotations

import hashlib
import json
import os
import shutil
import shlex
import subprocess
from datetime import datetime, timezone
from pathlib import Path

PROFILES = ('fredmourao', 'marinaofaleiro')
SESSION_STORE_DIR = 'shared-session-state'


class SessionConflictError(RuntimeError):
    pass
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
        "$ErrorActionPreference='Continue'\n"
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


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def _same_location(left: Path, right: Path) -> bool:
    try:
        return left.resolve() == right.resolve()
    except OSError:
        return False


def _session_source_dirs(home: Path, shared_sessions: Path) -> tuple[Path, ...]:
    home = Path(home)
    shared_sessions = Path(shared_sessions)
    profile_paths = tuple(
        home / '.codex-business' / profile / 'sessions' for profile in PROFILES
    )
    if all(_same_location(path, shared_sessions) for path in profile_paths):
        return (shared_sessions,)
    candidates = [home / '.codex' / 'sessions']
    candidates.extend(
        path for path in profile_paths if not _same_location(path, shared_sessions)
    )
    candidates.append(shared_sessions)
    return tuple(candidates)


def _build_session_inventory(
    sources: tuple[Path, ...], shared_sessions: Path
) -> dict[str, dict]:
    del shared_sessions
    inventory: dict[str, dict] = {}
    for source in sources:
        source = Path(source)
        if not source.is_dir():
            continue
        for path in sorted(source.rglob('*')):
            if not path.is_file():
                continue
            relative = path.relative_to(source).as_posix()
            file_hash = _sha256_file(path)
            size = path.stat().st_size
            existing = inventory.get(relative)
            if existing is None:
                inventory[relative] = {
                    'sha256': file_hash,
                    'size': size,
                    'source_paths': [str(path)],
                }
                continue
            if existing['sha256'] != file_hash or existing['size'] != size:
                raise SessionConflictError(
                    f'conflicting session file for relative path: {relative}'
                )
            existing['source_paths'].append(str(path))
    return inventory


def _copy_inventory_to_shared(
    inventory: dict[str, dict], shared_sessions: Path
) -> dict[str, int]:
    shared_sessions = Path(shared_sessions)
    copied = 0
    for relative, entry in sorted(inventory.items()):
        destination = shared_sessions / Path(relative)
        if destination.exists():
            if (
                not destination.is_file()
                or _sha256_file(destination) != entry['sha256']
                or destination.stat().st_size != entry['size']
            ):
                raise SessionConflictError(
                    f'conflicting destination session file: {relative}'
                )
            continue
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(Path(entry['source_paths'][0]), destination)
        if (
            _sha256_file(destination) != entry['sha256']
            or destination.stat().st_size != entry['size']
        ):
            raise OSError(f'copied session verification failed: {relative}')
        copied += 1
    return {'session_count': len(inventory), 'copied': copied}


def _points_to(path: Path, target: Path) -> bool:
    path = Path(path)
    target = Path(target)
    if not (path.exists() or path.is_symlink()):
        return False
    try:
        return path.resolve(strict=True) == target.resolve(strict=True)
    except OSError:
        return False


def _is_junction(path: Path) -> bool:
    checker = getattr(path, 'is_junction', None)
    return bool(checker and checker())


def _create_directory_link(link: Path, target: Path, platform: str) -> None:
    link = Path(link)
    target = Path(target)
    target.mkdir(parents=True, exist_ok=True)
    link.parent.mkdir(parents=True, exist_ok=True)
    if link.exists() or link.is_symlink():
        raise FileExistsError(f'session link path already exists: {link}')
    if platform == 'linux':
        os.symlink(target, link, target_is_directory=True)
        return
    if platform == 'windows':
        subprocess.run(
            ['cmd.exe', '/d', '/c', 'mklink', '/J', str(link), str(target)],
            check=True,
            capture_output=True,
            text=True,
        )
        return
    raise ValueError(f'unsupported platform: {platform}')


def _manifest_files(inventory: dict[str, dict]) -> list[dict]:
    return [
        {
            'relative_path': relative,
            'sha256': entry['sha256'],
            'size': entry['size'],
            'source_paths': list(entry['source_paths']),
        }
        for relative, entry in sorted(inventory.items())
    ]


def _prepare_shared_sessions(home: Path, platform: str, backup: Path) -> dict:
    home = Path(home)
    backup = Path(backup)
    runtime = home / '.codex-business'
    shared_sessions = runtime / SESSION_STORE_DIR / 'sessions'
    sources = _session_source_dirs(home, shared_sessions)
    inventory = _build_session_inventory(sources, shared_sessions)

    shared_sessions.mkdir(parents=True, exist_ok=True)
    copy_result = _copy_inventory_to_shared(inventory, shared_sessions)
    if copy_result['session_count'] != len(inventory):
        raise OSError('shared session copy count mismatch')

    profile_backups: dict[str, str | None] = {}
    session_backup_root = backup / 'session-paths'
    for profile in PROFILES:
        session_path = runtime / profile / 'sessions'
        if _points_to(session_path, shared_sessions):
            profile_backups[profile] = None
            continue
        if session_path.is_symlink() or _is_junction(session_path):
            raise SessionConflictError(
                f'profile sessions already linked elsewhere: {profile}'
            )
        if session_path.exists() and not session_path.is_dir():
            raise SessionConflictError(
                f'profile sessions path is not a directory: {profile}'
            )
        if session_path.is_dir():
            profile_backups[profile] = str(
                session_backup_root / f'{profile}-sessions'
            )
        else:
            profile_backups[profile] = None

    backup.mkdir(parents=True, exist_ok=True)
    manifest_path = backup / 'session-migration-manifest.json'
    manifest = {
        'version': 1,
        'created_at': datetime.now(timezone.utc).isoformat(),
        'shared_sessions': str(shared_sessions),
        'session_count': len(inventory),
        'files': _manifest_files(inventory),
        'profile_backups': profile_backups,
    }
    manifest_path.write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + '\n',
        encoding='utf-8',
    )

    try:
        for profile in PROFILES:
            session_path = runtime / profile / 'sessions'
            if _points_to(session_path, shared_sessions):
                continue
            backup_path_text = profile_backups[profile]
            if backup_path_text:
                backup_path = Path(backup_path_text)
                backup_path.parent.mkdir(parents=True, exist_ok=True)
                if backup_path.exists():
                    raise FileExistsError(
                        f'profile session backup already exists: {backup_path}'
                    )
                shutil.move(str(session_path), str(backup_path))
            _create_directory_link(session_path, shared_sessions, platform)
            if not _points_to(session_path, shared_sessions):
                raise OSError(f'profile session link verification failed: {profile}')
    except Exception:
        rollback_shared_sessions(home, backup)
        raise

    return {
        'shared_sessions': str(shared_sessions),
        'session_count': len(inventory),
        'session_manifest': str(manifest_path),
    }


def _remove_directory_link(path: Path, target: Path) -> None:
    path = Path(path)
    if not _points_to(path, target):
        return
    if path.is_symlink():
        path.unlink()
        return
    if os.name == 'nt' or _is_junction(path):
        os.rmdir(path)
        return
    raise RuntimeError(f'expected filesystem link at {path}')


def rollback_shared_sessions(home: Path, backup: Path) -> dict:
    home = Path(home)
    backup = Path(backup)
    manifest_path = backup / 'session-migration-manifest.json'
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    shared_sessions = Path(manifest['shared_sessions'])
    runtime = home / '.codex-business'
    restored: list[str] = []
    for profile in PROFILES:
        session_path = runtime / profile / 'sessions'
        _remove_directory_link(session_path, shared_sessions)
        backup_text = manifest['profile_backups'].get(profile)
        if backup_text and Path(backup_text).exists():
            shutil.move(str(Path(backup_text)), str(session_path))
        elif not session_path.exists():
            session_path.mkdir(parents=True, exist_ok=True)
        restored.append(profile)
    return {
        'shared_sessions': str(shared_sessions),
        'restored_profiles': restored,
        'session_manifest': str(manifest_path),
    }


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
    session_result = _prepare_shared_sessions(home, platform, backup)

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

    return {
        'platform': platform,
        'backup': str(backup),
        'engine': str(engine_dest),
        **session_result,
    }
