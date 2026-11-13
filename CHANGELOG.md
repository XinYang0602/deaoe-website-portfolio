## 2026-11-12 — Existing-database category upgrade fix

- Ensure the additive category table exists before authenticated inventory API reads/writes, without repeating inventory migration.
- Added regression coverage for an existing database missing the new table; verified category editing, desktop/mobile browsing and business checks.

## 2026-11-12 — Hierarchical product categories

- Added a collapsible desktop category tree, descendant filtering and full product paths.
- Editable paths support up to eight levels with existing-path suggestions; legacy product defaults remain unchanged.
- An additive category table preserves existing records. Validated path constraints, stale writes, desktop editing/filtering and mobile regression.

## 2026-11-12 — Consistent business icons

- Added original 24px outline SVG artwork for navigation, application menus, workflows and favorite controls.
- Module accents and existing navigation behavior are retained, without external fonts or icon requests.
- Validated JavaScript syntax, desktop/mobile navigation regression and desktop screenshots.

## 2026-11-12 — Favorite navigation shortcuts

- Favorite stars sit beside module names; toggles immediately show or hide desktop navigation shortcuts.
- Preferences survive reloads; All Applications and Dashboard stay accessible even with no favorites. Mobile navigation is unchanged.
- Validated with local business checks and browser navigation tests at desktop and mobile widths.

## 2026-11-12 — Service orders and product directory

- Compact desktop service-order list and entry form, with searchable customer/product selection and existing status workflows.
- Category sidebar, dense product table and selected-product image panel reuse product maintenance and pagination.
- Standardized Product Records labels while preserving mobile layouts and existing persistence rules.
- Validation: synthetic service save/status/selector flows and product pagination/filter/edit/delete-protection/export checks at 1440/1024/720/390 widths, plus existing sales/business regressions.

## 2026-11-12 — Desktop inventory workspace

- Added a compact filter sidebar, product stock totals and selected-product warehouse details using existing inventory records.
- Retained stock adjustment, legacy allocation, export and mobile inventory behavior.
- Unified Service Management and Customer Management labels and removed data-storage status text from routine screens.
- Validation: synthetic stock calculations, filters, warehouse selection, export and resizing at 1440/1024/720/390 widths; existing navigation and sales workflows pass.

## 2026-11-12 — Desktop purchasing and goods receipt selectors

- Searchable supplier and warehouse tables and compact product selection follow the shared desktop selector style.
- Receipts retain approved purchase-order linkage, source supplier/warehouse constraints and partial-receipt quantities. Mobile entry behavior is preserved.
- Updated the workspace footer to the management-platform label.
- Validation: synthetic purchase/partial-receipt approval and serial preservation, desktop modal bounds, unchanged mobile controls, and existing sales/business regressions.

## 2026-11-12 — Desktop sales selectors and compact workflow maps

- Added searchable customer tables with explicit selection confirmation, a product filter/table workspace with warehouse availability and selected items, and a per-item warehouse selector.
- Retained multi-warehouse sales, quantity/pricing edits, cancellation behavior and the existing mobile UI.
- Reduced desktop workflow dimensions and emphasized primary paths over related-record links.
- Validation: local business regressions, desktop/mobile sale submission and stock deduction, selector flows with synthetic data, and seven-module navigation checks.

## 2026-11-12 — Module navigation and workflow maps

- Grouped module panels, an application directory and local module favorites.
- Seven responsive workflow maps linked to existing operations; service nodes apply real status filters. No new accounting or approval rules.
- Synthetic browser checks cover three viewport sizes, menu dismissal, favorites, filters and sales form navigation.

## 2026-11-12 — Compact ERP workspace

- Added horizontal desktop modules, memory-only recent route shortcuts and business workflow links. Tabs do not retain unsaved drafts.
- Compact aligned document entry and shared panels; preserved mobile filters and improved input sizing.
- Validated with synthetic desktop/mobile sales workflows and stable panel geometry.

# Changelog

A concise engineering history for reviewers. Dates identify development updates, not deployments. No semantic-version tags or production release status are implied. Earlier overlapping patches are grouped by capability.

## 2026-11-12 — Collapsible mobile sales filters

- Collapse mobile sales filters by default, show selected criteria in the summary, and place sorting controls side by side.
- Keep search visible and preserve expansion during filter updates. Desktop layout is unchanged; date and sorting browser regressions cover expansion.

## 2026-11-12 — Document date sorting

- Shared ascending/descending business-date sorting for desktop/mobile sales and purchases, with document-number tie breaks and highlighted controls.
- Preserve filters and per-type selection in memory; reload restores newest-first. Synthetic browser checks cover both types and directions.

## 2026-11-12 — Sales date range filtering

- Filter sales by inclusive business-date ranges on desktop and mobile; support open-ended ranges and clearing dates.
- Synthetic sales verify same-day, open-ended, invalid ranges and reset.

## 2026-11-12 — Stable desktop workspace layout

- Align compact and standard workspace panels, toolbars and headings; reset desktop scroll on route changes only.
- Local layout coverage compares eight business routes and desktop scroll resets.

## 2026-11-12 — Consistent lists and aligned amounts

- Unify desktop/mobile list typography, separators and buttons; separate amount labels from right-aligned tabular numbers.
- Synthetic 41-party layout coverage checks vertical flow, scrolling and viewport overflow.

## 2026-11-12 — Compact desktop balance lists

- Display one party per row with aligned names, amounts and actions; preserve summary tiles and mobile layout.
- Synthetic 41-party layout coverage checks vertical flow, scrolling and viewport overflow.

## 2026-11-12 — Faster workspace navigation

- Preload one hidden ERP workspace after the dashboard is ready.
- Reuse memory snapshots for 15 seconds, revalidate on navigation/focus, and bypass expiry after writes or explicit refresh. Deduplicate reads and retry snapshots overlapping writes. No persistent business-data cache.
- Passed synthetic cache race/failure tests and desktop/mobile business regressions. With an injected 300ms API delay, three warm navigations issued no extra reads (7–22ms locally); this is not a production latency claim.

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
