from __future__ import annotations

from collections.abc import Callable
from typing import Any

from .config import Settings


class PlaywrightFallbackDisabled(RuntimeError):
    pass


class PlaywrightFallbackUnavailable(RuntimeError):
    pass


class PlaywrightFallback:
    """Explicit last-resort adapter.

    The normal Stage 1 path never imports or launches Playwright. A concrete
    resolver is attached only after API/server evidence proves it is needed.
    """

    def __init__(
        self,
        settings: Settings,
        *,
        resolver: Callable[[dict[str, Any]], dict[str, Any]] | None = None,
    ) -> None:
        self.settings = settings
        self._resolver = resolver

    def lookup(self, product: dict[str, Any]) -> dict[str, Any]:
        if not self.settings.allow_playwright_fallback:
            raise PlaywrightFallbackDisabled("Playwright fallback is disabled")
        if self._resolver is None:
            raise PlaywrightFallbackUnavailable(
                "Playwright fallback was enabled but no audited resolver is configured"
            )
        return self._resolver(product)
