#!/usr/bin/env python3
"""Decrypt a one-shot DH envelope locally and submit a ChatGPT email OTP.

The OTP is never accepted on argv, never printed, and is sent to the browser
submitter only over stdin. The ephemeral private key is deleted after one use.
"""

from __future__ import annotations

import argparse
import base64
import pathlib
import re
import subprocess

P = int(
    "FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3BE39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF",
    16,
)
PRIVATE_KEY = pathlib.Path(
    "/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/.otp-dh-private"
)
PUBLIC_CACHE = pathlib.Path("/tmp/shopvivaliz-dh-p.hex")
PAYLOAD_RE = re.compile(r"^dh1:([A-Za-z0-9_-]{300,380}):([0-9]{1,6})$")


def decode_public(value: str) -> int:
    padded = value + "=" * ((4 - len(value) % 4) % 4)
    raw = base64.urlsafe_b64decode(padded.encode("ascii"))
    return int.from_bytes(raw, "big")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("payload")
    parser.add_argument("--submit-script", required=True)
    args = parser.parse_args()

    match = PAYLOAD_RE.fullmatch(args.payload)
    if not match:
        raise SystemExit("invalid encrypted relay payload")

    submit_script = pathlib.Path(args.submit_script)
    if not submit_script.is_file() or submit_script.is_symlink():
        raise SystemExit("submit script missing or unsafe")
    if not PRIVATE_KEY.is_file() or PRIVATE_KEY.is_symlink():
        raise SystemExit("ephemeral relay key missing")

    try:
        a_public = decode_public(match.group(1))
        cipher = int(match.group(2))
        if not 2 <= a_public <= P - 2:
            raise SystemExit("invalid DH public value")
        if not 0 <= cipher < 1_000_000:
            raise SystemExit("invalid relay ciphertext")

        private = int(PRIVATE_KEY.read_text(encoding="utf-8").strip(), 16)
        if private <= 1:
            raise SystemExit("invalid ephemeral relay key")

        shared = pow(a_public, private, P)
        code = f"{(cipher - (shared % 1_000_000)) % 1_000_000:06d}"
        if not (len(code) == 6 and code.isdigit()):
            raise SystemExit("relay decode failed")

        proc = subprocess.run(
            ["node", str(submit_script)],
            stdin=subprocess.PIPE,
            input=code + "\n",
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )
        safe_lines = [
            line
            for line in (proc.stdout or "").splitlines()
            if line.startswith("CHATGPT_OTP_")
        ]
        for line in safe_lines:
            print(line)
        if proc.returncode != 0:
            raise SystemExit(f"browser submit failed rc={proc.returncode}")
        if "CHATGPT_OTP_SUBMIT=PASS" not in safe_lines:
            raise SystemExit("browser submit did not prove success")
        print("CHATGPT_OTP_RELAY=PASS")
        return 0
    finally:
        PRIVATE_KEY.unlink(missing_ok=True)
        PUBLIC_CACHE.unlink(missing_ok=True)


if __name__ == "__main__":
    raise SystemExit(main())
