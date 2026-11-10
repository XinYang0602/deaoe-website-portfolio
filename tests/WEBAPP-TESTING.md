# Local browser and regression tests

Tests use temporary source copies, synthetic records, and isolated SQLite databases. They do not require production credentials or data.

## Browser setup

Requirements: Python, PHP with PDO SQLite, and Google Chrome.

```sh
python3 -m venv /tmp/deaoe-webapp-test-venv
/tmp/deaoe-webapp-test-venv/bin/pip install playwright==1.64.1
/tmp/deaoe-webapp-test-venv/bin/python tests/webapp_smoke.py
/tmp/deaoe-webapp-test-venv/bin/python tests/website_smoke.py
```

The application suite covers desktop and 390px mobile viewports: customer search and creation, draft preservation, product selection, document approval, inventory effects, ledger forms, compact registers, and customer-directory details. It checks API responses and JavaScript errors.

The website suite covers CMS authentication, draft products, synthetic image uploads and WebP conversion, publication within the temporary local preview, catalogue filtering, galleries, and mobile navigation. A missing default hero asset is a documented baseline limitation.

Browser requests are limited to local resources. Temporary data is cleaned up; screenshots and logs are written to the reported temporary directory and are not committed. Mobile viewport emulation is not physical Safari testing.

## Backend and interaction checks

```sh
python3 tools/check.py
bash tests/run-sales-regression.sh
bash tests/run-purchase-regression.sh
php tests/finance-four.php
node tests/customer-picker-lifecycle.cjs
```

Coverage includes repeated approvals, insufficient stock and rollback, multi-warehouse allocation, document-number concurrency, returns, stocktaking, settlement boundaries, and modal cleanup. The runner validates source syntax before executing isolated regressions. Private archive and deployment tests are not included.
