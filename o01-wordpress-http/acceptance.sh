#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
O01_DISPOSABLE_ACCEPTANCE=YES php binding-check.php
