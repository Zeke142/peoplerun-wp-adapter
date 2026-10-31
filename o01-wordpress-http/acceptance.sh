#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
O01_DISPOSABLE_ACCEPTANCE=YES php canonical-quote-check.php
