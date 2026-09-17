#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import mimetypes
import os
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

TOKEN_URL = "https://oauth2.googleapis.com/token"
TOKENINFO_URL = "https://oauth2.googleapis.com/tokeninfo"
YOUTUBE_UPLOAD_URL = "https://www.googleapis.com/upload/youtube/v3/videos"
YOUTUBE_VIDEOS_URL = "https://www.googleapis.com/youtube/v3/videos"
YOUTUBE_UPLOAD_SCOPE = "https://www.googleapis.com/auth/youtube.upload"
DEFAULT_REGISTRY = Path("storage/product-video-sources.json")


def has_youtube_upload_scope(scope_text: str) -> bool:
    return YOUTUBE_UPLOAD_SCOPE in set(scope_text.split())


def load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8-sig", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key not in {"GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_OAUTH_REFRESH_TOKEN"}:
            continue
        if not os.getenv(key):
            os.environ[key] = value.strip().strip('"').strip("'")


def oauth_config() -> tuple[str, str, str]:
    values = tuple(os.getenv(key, "").strip() for key in (
        "GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_OAUTH_REFRESH_TOKEN"
    ))
    if not all(values):
        raise RuntimeError("google_oauth_missing")
    return values  # type: ignore[return-value]


def request_json(request: urllib.request.Request, timeout: int = 60) -> tuple[dict[str, Any], Any]:
    with urllib.request.urlopen(request, timeout=timeout) as response:
        raw = response.read().decode("utf-8")
        payload = json.loads(raw) if raw else {}
        return (payload if isinstance(payload, dict) else {}), response.headers


def refresh_access_token() -> str:
    client_id, client_secret, refresh_token = oauth_config()
    body = urllib.parse.urlencode({
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh_token,
        "grant_type": "refresh_token",
    }).encode()
    request = urllib.request.Request(TOKEN_URL, data=body, method="POST")
    payload, _ = request_json(request)
    token = str(payload.get("access_token") or "").strip()
    if not token:
        raise RuntimeError("google_access_token_missing")
    return token


def access_token_scopes(access_token: str) -> str:
    url = TOKENINFO_URL + "?" + urllib.parse.urlencode({"access_token": access_token})
    payload, _ = request_json(urllib.request.Request(url, method="GET"))
    return str(payload.get("scope") or "")


def youtube_preflight() -> str:
    access_token = refresh_access_token()
    if not has_youtube_upload_scope(access_token_scopes(access_token)):
        raise RuntimeError("youtube_scope_missing")
    return access_token


def start_resumable_upload(access_token: str, file_path: Path, title: str, description: str, privacy: str) -> str:
    metadata = {
        "snippet": {"title": title[:100], "description": description[:5000]},
        "status": {"privacyStatus": privacy, "selfDeclaredMadeForKids": False},
    }
    body = json.dumps(metadata, ensure_ascii=False).encode("utf-8")
    url = YOUTUBE_UPLOAD_URL + "?" + urllib.parse.urlencode({"uploadType": "resumable", "part": "snippet,status"})
    mime = mimetypes.guess_type(file_path.name)[0] or "video/mp4"
    request = urllib.request.Request(url, data=body, method="POST", headers={
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json; charset=UTF-8",
        "X-Upload-Content-Length": str(file_path.stat().st_size),
        "X-Upload-Content-Type": mime,
    })
    with urllib.request.urlopen(request, timeout=60) as response:
        location = str(response.headers.get("Location") or "").strip()
    if not location:
        raise RuntimeError("youtube_resumable_location_missing")
    return location


def upload_video_bytes(session_url: str, access_token: str, file_path: Path) -> str:
    data = file_path.read_bytes()
    mime = mimetypes.guess_type(file_path.name)[0] or "video/mp4"
    request = urllib.request.Request(session_url, data=data, method="PUT", headers={
        "Authorization": f"Bearer {access_token}", "Content-Type": mime,
        "Content-Length": str(len(data)),
    })
    payload, _ = request_json(request, timeout=300)
    video_id = str(payload.get("id") or "").strip()
    if not video_id:
        raise RuntimeError("youtube_video_id_missing")
    return video_id


def verify_video(access_token: str, video_id: str) -> dict[str, Any]:
    url = YOUTUBE_VIDEOS_URL + "?" + urllib.parse.urlencode({"part": "snippet,status", "id": video_id})
    request = urllib.request.Request(url, method="GET", headers={"Authorization": f"Bearer {access_token}"})
    payload, _ = request_json(request)
    items = payload.get("items") if isinstance(payload.get("items"), list) else []
    if not items or str(items[0].get("id") or "") != video_id:
        raise RuntimeError("youtube_readback_failed")
    return items[0]


def persist_video_source(path: Path, product_id: str, direct_url: str, youtube_id: str) -> None:
    try:
        current = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
    except json.JSONDecodeError:
        current = {}
    if not isinstance(current, dict):
        current = {}
    current[str(product_id)] = {
        "direct_url": direct_url,
        "youtube_url": f"https://youtu.be/{youtube_id}",
        "youtube_video_id": youtube_id,
        "youtube_verified_at": datetime.now(timezone.utc).isoformat(),
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(current, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(path)


def upload_product_video(product_id: str, file_path: Path, direct_url: str, title: str, description: str, privacy: str, registry: Path) -> str:
    access_token = youtube_preflight()
    session_url = start_resumable_upload(access_token, file_path, title, description, privacy)
    video_id = upload_video_bytes(session_url, access_token, file_path)
    verify_video(access_token, video_id)
    persist_video_source(registry, product_id, direct_url, video_id)
    return video_id


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--preflight", action="store_true")
    parser.add_argument("--env-file", default=".env")
    parser.add_argument("--product-id")
    parser.add_argument("--file")
    parser.add_argument("--direct-url", default="")
    parser.add_argument("--title", default="Produto ShopVivaliz")
    parser.add_argument("--description", default="")
    parser.add_argument("--privacy", choices=["private", "unlisted", "public"], default="unlisted")
    parser.add_argument("--registry", default=str(DEFAULT_REGISTRY))
    args = parser.parse_args()
    load_env_file(Path(args.env_file))
    if args.preflight:
        try:
            youtube_preflight()
            print("youtube_preflight=ok")
            return 0
        except RuntimeError as exc:
            print(f"youtube_preflight={exc}")
            return 2
    if not args.product_id or not args.file:
        parser.error("--product-id and --file are required unless --preflight is used")
    file_path = Path(args.file)
    if not file_path.is_file():
        raise SystemExit("video_file_missing")
    try:
        video_id = upload_product_video(
            args.product_id, file_path, args.direct_url, args.title,
            args.description, args.privacy, Path(args.registry),
        )
    except urllib.error.HTTPError as exc:
        print(f"youtube_upload=http_{exc.code}")
        return 3
    except RuntimeError as exc:
        print(f"youtube_upload={exc}")
        return 4
    print(f"youtube_upload=ok video_id={video_id}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
