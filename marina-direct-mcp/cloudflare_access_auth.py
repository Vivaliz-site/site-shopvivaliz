"""Fail-closed Cloudflare Access JWT verification for the isolated Marina MCP.

A signed assertion is a transport identity, never a root or write grant.
Only an authenticated, configured Access application audience is accepted;
service-token and interactive identities are separated and explicitly allowlisted.
"""
from __future__ import annotations

import json
import os
import re
import stat
from pathlib import Path
from typing import NamedTuple

_TEAM = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cloudflareaccess\.com$")
_EMAIL = re.compile(r"^[^\s@]{1,64}@[^\s@]{1,190}$")
_SERVICE_ID = re.compile(r"^[a-zA-Z0-9._-]{8,128}$")
_MAX_CONFIG_BYTES = 4096
_MAX_JWT_BYTES = 8192


class AccessPrincipal(NamedTuple):
    kind: str
    identity: str


def load_access_config(path: Path) -> dict:
    """Read operator-owned configuration (not tokens) from one protected file."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        raise RuntimeError("access_config_open_refused") from None
    with os.fdopen(fd, "rb") as stream:
        meta = os.fstat(stream.fileno())
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid()
                or stat.S_IMODE(meta.st_mode) != 0o600):
            raise RuntimeError("access_config_metadata_invalid")
        payload = stream.read(_MAX_CONFIG_BYTES + 1)
    if not payload or len(payload) > _MAX_CONFIG_BYTES:
        raise RuntimeError("access_config_size_invalid")
    try:
        data = json.loads(payload)
    except (ValueError, UnicodeDecodeError):
        raise RuntimeError("access_config_json_invalid") from None
    if not isinstance(data, dict) or set(data) != {
            "team_domain", "audience", "allowed_emails", "allowed_service_tokens"}:
        raise RuntimeError("access_config_shape_invalid")
    domain = data["team_domain"]
    audience = data["audience"]
    emails = data["allowed_emails"]
    services = data["allowed_service_tokens"]
    if not isinstance(domain, str) or not _TEAM.fullmatch(domain):
        raise RuntimeError("access_config_domain_invalid")
    if (not isinstance(audience, str) or not 8 <= len(audience) <= 256
            or not audience.isascii() or any(ch.isspace() for ch in audience)):
        raise RuntimeError("access_config_audience_invalid")
    if (not isinstance(emails, list) or len(emails) > 16
            or any(not isinstance(x, str) or not _EMAIL.fullmatch(x)
                   or x != x.lower() for x in emails)
            or len(set(emails)) != len(emails)):
        raise RuntimeError("access_config_emails_invalid")
    if (not isinstance(services, list) or len(services) > 16
            or any(not isinstance(x, str) or not _SERVICE_ID.fullmatch(x)
                   for x in services) or len(set(services)) != len(services)):
        raise RuntimeError("access_config_services_invalid")
    if not emails and not services:
        raise RuntimeError("access_config_no_principals")
    return {
        "team_domain": domain,
        "audience": audience,
        "allowed_emails": frozenset(emails),
        "allowed_service_tokens": frozenset(services),
    }


class CloudflareAccessVerifier:
    """Validate Access's RS256 assertion, including a rotation-aware Cloudflare JWKS.

    JWKS URL is derived exclusively from validated operator config; never
    follow JWT-supplied jku or request-supplied endpoints. Every accepted
    principal has read-only MCP rights (not an administrative/root grant).
    """

    def __init__(self, config: dict) -> None:
        try:
            import jwt
        except ImportError:
            raise RuntimeError("access_jwt_crypto_unavailable") from None
        self._jwt = jwt
        self._issuer = "https://" + config["team_domain"]
        self._audience = config["audience"]
        self._emails = frozenset(config["allowed_emails"])
        self._services = frozenset(config["allowed_service_tokens"])
        self._jwks = jwt.PyJWKClient(
            self._issuer + "/cdn-cgi/access/certs", cache_jwk_set=True,
            lifespan=300, timeout=3,
        )

    def verify(self, assertion: str) -> AccessPrincipal | None:
        if (not isinstance(assertion, str) or not 64 <= len(assertion) <= _MAX_JWT_BYTES
                or assertion.count(".") != 2 or any(ch.isspace() for ch in assertion)):
            return None
        try:
            header = self._jwt.get_unverified_header(assertion)
            if (not isinstance(header, dict) or header.get("alg") != "RS256"
                    or not isinstance(header.get("kid"), str)
                    or not 1 <= len(header["kid"]) <= 256):
                return None
            key = self._jwks.get_signing_key_from_jwt(assertion)
            claims = self._jwt.decode(
                assertion, key.key, algorithms=["RS256"], audience=self._audience,
                issuer=self._issuer, leeway=0,
                options={"require": ["exp", "iat", "iss", "aud", "sub", "type"]},
            )
            if claims.get("type") != "app":
                return None
            sub = claims.get("sub")
            email = claims.get("email")
            common_name = claims.get("common_name")
            if sub == "" and email in (None, ""):
                if (isinstance(common_name, str)
                        and common_name in self._services):
                    return AccessPrincipal("service", common_name)
                return None
            if (isinstance(sub, str) and bool(sub)
                    and isinstance(email, str) and email == email.lower()
                    and email in self._emails and not common_name):
                return AccessPrincipal("user", email)
            return None
        except (self._jwt.PyJWTError, ValueError, TypeError, OSError):
            return None
