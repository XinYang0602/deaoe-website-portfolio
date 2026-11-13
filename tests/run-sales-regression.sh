#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "$0")" && pwd)"
SOURCE_DIR="${1:-$SCRIPT_DIR/../src/service}"
TEST_DIR="$(mktemp -d)"
trap 'rm -rf -- "$TEST_DIR"' EXIT
for name in inventory.php production.php storage.php phase2.php inventory-cleanup.php finance.php; do
  cp -- "$SOURCE_DIR/$name" "$TEST_DIR/$name"
done
# Block any accidental call to database(): the destination does not exist.
export DEAOE_TEST_INVENTORY="$TEST_DIR/inventory.php"
export DEAOE_TEST_BLOCKED_DATA="$TEST_DIR/no-production-data"
printf '%s\n' '<?php define("DATA_DIR", getenv("DEAOE_TEST_BLOCKED_DATA"));' > "$TEST_DIR/guard.php"
php -d auto_prepend_file="$TEST_DIR/guard.php" "$SCRIPT_DIR/sale-customer-default.php"
php -d auto_prepend_file="$TEST_DIR/guard.php" "$SCRIPT_DIR/sale-approval.php"
echo '全部通过。仅验证临时源码副本及内存测试数据库，未修改正式数据。'
