from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

DEFAULT_API_BASE_URL = "https://api.tiny.com.br/public-api/v3"
DEFAULT_VIDEO_BASE_URL = "https://shopvivaliz.com.br/uploads/videos-produtos/"
DEFAULT_LINUX_TOKEN_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json")


@dataclass(frozen=True)
class Settings:
    tiny_api_base_url: str
    video_base_url: str
    video_dir: Path
    token_file: Path
    timeout_seconds: float = 20.0
    max_retries: int = 3
    page_size: int = 100
    allow_playwright_fallback: bool = False

    @classmethod
    def from_env(cls) -> "Settings":
        project_root = Path(__file__).resolve().parents[2]
        default_token_file = (
            project_root / "storage/private/olist-tokens.json"
            if os.name == "nt"
            else DEFAULT_LINUX_TOKEN_FILE
        )
        base_url = os.getenv("PRODUCT_VIDEO_BASE_URL", DEFAULT_VIDEO_BASE_URL).rstrip("/") + "/"
        return cls(
            tiny_api_base_url=(
                os.getenv("TINY_API_BASE_URL")
                or os.getenv("OLIST_API_BASE_URL")
                or DEFAULT_API_BASE_URL
            ).rstrip("/"),
            video_base_url=base_url,
            video_dir=Path(os.getenv("PRODUCT_VIDEO_DIR", str(project_root / "uploads/videos-produtos"))),
            token_file=Path(os.getenv("SHOPVIVALIZ_OLIST_TOKEN_FILE", str(default_token_file))),
            timeout_seconds=float(os.getenv("PRODUCT_VIDEO_HTTP_TIMEOUT", "20")),
            max_retries=max(0, int(os.getenv("PRODUCT_VIDEO_MAX_RETRIES", "3"))),
            page_size=max(1, min(100, int(os.getenv("PRODUCT_VIDEO_PAGE_SIZE", "100")))),
            allow_playwright_fallback=os.getenv("PRODUCT_VIDEO_ALLOW_PLAYWRIGHT", "0").strip().lower()
            in {"1", "true", "yes", "on"},
        )
