# Changelog

A concise engineering history for reviewers. Dates identify development updates, not deployments. No semantic-version tags or production release status are implied. Earlier overlapping patches are grouped by capability.

## 2026-11-12 — Mobile login layout

- Set mobile login inputs to 16px and keep the card shrinkable and horizontally centered without disabling user zoom.
- Added local focus/layout checks for six viewport sizes. Real-device iOS autofill remains a manual verification step.

## 2026-11-11 — Compact footers and README updates

- Aligned brand, copyright, demo filing links, and auxiliary information in a compact desktop footer shared by homepage and product pages.
- Added responsive wrapping, keyboard focus styling, and layout checks from 320 to 1920 pixels.
- Added a dated Updates table to the README; every future small update includes both a short README entry and a detailed changelog entry.

## 2026-11-11 — Incremental feature synchronization

- Added a desktop shortcut that opens the sales-entry form directly.
- Inset carousel controls to avoid the scrollbar edge, with viewport and keyboard checks.
- Added a responsive dual-filing footer with an embedded icon; this public snapshot uses explicit demo labels and a placeholder query identifier.
- Updated mini-program API rate limits and included isolated regression coverage.
- Public source and English release notes now follow every reviewed update, including small fixes; private data and deployment materials remain excluded.

## 2026-11-10 — ERP overview and diagrams

- Explained the product purpose and its sales, purchasing, inventory, settlement, and after-sales modules.
- Added business-flow and technical-structure diagrams, plus a concrete sales workflow example.
- Clarified the scope of the lightweight ERP and the separation between public website content and application records.

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
