#!/usr/bin/env bash
set -euo pipefail
export O01_DISPOSABLE_ACCEPTANCE=YES
php o01-wordpress-http/publisher-receipt-check.php
