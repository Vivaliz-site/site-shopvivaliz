"""
Compatibility wrapper for the retired FTP publisher.

The public API remains upload(local_path, remote_subdir) for older callers,
but publication now writes atomically to the persistent ShopVivaliz webroot.
"""
from pathlib import Path

from scripts.site_public_storage import publish_file


def upload(local_path: str, remote_subdir: str = "") -> str:
    local = Path(local_path)
    subdir = remote_subdir.strip("/")
    relative = "/".join(part for part in ["uploads", subdir, local.name] if part)
    return publish_file(local, relative)
