#!/usr/bin/env python3
"""Offline-only validation of a proposed Actions-log public-key handshake.

No GitHub calls, network access, repository writes, or publisher operations.
Input is a JSON handshake extracted by an independently authenticated sender.
This does NOT prove that in-progress job logs are retrievable within 600 seconds.
"""
import argparse
import hashlib
import json
import pathlib
import re
import sys
from cryptography.hazmat.primitives.serialization import load_pem_public_key
from cryptography.hazmat.primitives.asymmetric import rsa
from datetime import datetime, timezone

FIELDS = ("repository", "run_id", "attempt", "source_commit",
          "archive_sha256", "public_key_pem", "public_key_sha256",
          "created_at_utc", "expires_in_seconds")

def validate(obj, repository, run_id, attempt, source_commit, archive_sha256, now):
    if not isinstance(obj, dict) or any(k not in obj for k in FIELDS):
        raise ValueError("missing_handshake_fields")
    if obj.get("schema") != "peoplerun.o01.ephemeral-private-input.v1":
        raise ValueError("schema_mismatch")
    expected = dict(repository=repository, run_id=run_id, attempt=attempt,
                    source_commit=source_commit, archive_sha256=archive_sha256)
    if any(str(obj[k]) != str(v) for k, v in expected.items()):
        raise ValueError("run_binding_mismatch")
    if not re.fullmatch(r"[a-f0-9]{40}", source_commit):
        raise ValueError("source_commit_invalid")
    if not re.fullmatch(r"[a-f0-9]{64}", archive_sha256):
        raise ValueError("archive_hash_invalid")
    public = obj["public_key_pem"]
    if not isinstance(public, str) or len(public) > 8192 or not public.startswith("-----BEGIN PUBLIC KEY-----\n") or not public.rstrip().endswith("-----END PUBLIC KEY-----"):
        raise ValueError("public_key_encoding_invalid")
    if hashlib.sha256(public.encode()).hexdigest() != obj["public_key_sha256"]:
        raise ValueError("public_key_hash_mismatch")
    try:
        key = load_pem_public_key(public.encode("ascii"))
    except (ValueError, TypeError, UnicodeError) as exc:
        raise ValueError("public_key_parse_failed") from exc
    if not isinstance(key, rsa.RSAPublicKey) or key.key_size < 2048 or key.key_size > 8192 or key.public_numbers().e != 65537:
        raise ValueError("rsa_key_policy_denied")
    if type(obj["expires_in_seconds"]) is not int or obj["expires_in_seconds"] != 600:
        raise ValueError("window_mismatch")
    try:
        created = datetime.strptime(obj["created_at_utc"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except (ValueError, TypeError) as exc:
        raise ValueError("timestamp_invalid") from exc
    age = (now - created).total_seconds()
    if age < 0 or age >= 600:
        raise ValueError("handshake_expired_or_future")
    return {"status": "OFFLINE_HANDSHAKE_RSA_PASS", "run_id": str(run_id),
            "attempt": str(attempt), "public_key_sha256": obj["public_key_sha256"]}

def main():
    p = argparse.ArgumentParser()
    p.add_argument("handshake", type=pathlib.Path)
    for field in ("repository", "run_id", "attempt", "source_commit", "archive_sha256"):
        p.add_argument("--" + field.replace("_", "-"), required=True)
    args = p.parse_args()
    if args.handshake.stat().st_size > 16384:
        raise ValueError("handshake_too_large")
    obj = json.loads(args.handshake.read_text(encoding="utf-8"))
    result = validate(obj, args.repository, args.run_id, args.attempt,
                      args.source_commit, args.archive_sha256, datetime.now(timezone.utc))
    print(json.dumps(result, sort_keys=True))

if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, KeyError, json.JSONDecodeError) as exc:
        print("OFFLINE_HANDSHAKE_DENIED: " + str(exc), file=sys.stderr)
        sys.exit(1)
