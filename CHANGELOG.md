# Changelog

A concise engineering history for reviewers. Dates identify development updates, not deployments. No semantic-version tags or production release status are implied. Earlier overlapping patches are grouped by capability.

## 2026-11-10 — Public portfolio baseline

- Created a standalone public repository with a new root commit; no private Git history was imported.
- Included current implementation, synthetic regression tests, and English documentation.
- Excluded internal manuals, deployment archives, runtime data, and actual integration identifiers.

The entries below summarize earlier engineering milestones. They do not correspond to commits imported into this public repository.

## 2026-11-10

### Added
- English project overview, code guide, version-control workflow, and privacy documentation.
- Compact customer summaries with full details available on demand.

### Improved
- Shared customer records across application entry points, with refresh support and separate service history.
- Consistent action alignment and denser layouts for customer, supplier, and product directories.

### Repository hygiene
- Excluded internal requirements and operational manuals from the public snapshot.
- Kept deployment packages and delivery instructions outside the public repository.
- Kept application source and synthetic regression fixtures; no production data was imported.

## 2026-11-09

### Website
- Expanded category-specific series filters and a mobile catalogue layout.
- Added smooth, touch-driven gallery navigation with reduced-motion support.
- Corrected homepage category anchors around fixed navigation on mobile and desktop.
- Added template compatibility checks for incremental updates.

### Application
- Added searchable product selection and item-level quantity, price, discount, and warehouse configuration.
- Preserved delivery details with documents and supported multi-warehouse fulfilment.
- Introduced daily document numbers with concurrency and rollback checks.
- Unified compact document and inventory registers with filtering and export support.
- Extended account-ledger categories, allocation and refund workflows, and configurable account selection.
- Added stock-adjustment and quantity-based return workflows with transaction guards.
- Improved embedded navigation, empty-state product selection, and form alignment.

### Engineering
- Consolidated active source into `src/website/` and `src/service/`.
- Added archive provenance, reproducible baseline reconstruction, and isolated preview tooling.
- Expanded backend and browser regression coverage, including transaction failures and duplicate requests.

## 2026-11-08

### Interface
- Built a mobile workspace with grouped application entries and persistent bottom navigation.
- Unified navigation between application modules and fixed embedded-page policy conflicts.
- Improved browser back navigation, modal cleanup, and draft preservation.
- Added compact document lists, customer selection, and direct document-entry flows.
- Added product image previews, multi-category filtering, pagination, and structured product defaults.
- Corrected checkbox sizing, hidden labels, and mobile navigation overlap.

### Integrity and tests
- Added guarded product deletion and test-fixture cleanup tools.
- Added isolated sales, purchasing, returns, stocktaking, and browser workflow regressions.
- Covered repeat approval, insufficient stock, quarantine, and rollback conditions.

## 2026-11-07

- Established the repository baseline and incremental change workflow.
- Improved application navigation and member identity presentation.
- Added persistent member identifiers, migration support, and new-member response state.
- Added isolated member-maintenance tests and corrected rate-limit handling.
- Documented the requirement to preserve the official brand silhouette.

## Earlier baseline

- Public website and CMS; product catalogue and image galleries.
- Content editing, uploads, image conversion, homepage carousel, and navigation.
- Service application, inventory modules, and mini-program API integration.

## Validation scope

The repository includes source syntax checks, isolated SQLite regressions, JavaScript interaction tests, and Playwright desktop/mobile smoke tests. Each change uses the relevant subset. Browser viewport emulation does not replace physical-device testing. Test output and screenshots remain outside Git.
