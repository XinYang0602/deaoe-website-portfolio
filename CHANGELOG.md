## 2026-11-13 · Separate work order fields into columns and add paging and selected-order details.

The desktop register groups actions and filters in the toolbar. Users can page through orders, inspect individual fields, and switch between details and service history.

## 2026-11-13 · Redraw the user management icon with a person and a front-facing range hood.

The service workflow, module menu, and related links share the same vector artwork.

## 2026-11-13 · Separate independent navigation choices from ordered work and update device-user management artwork.

Manual and imported sales converge at review before dispatch. Independent stock tools and master data use unconnected choices. Both active repair nodes use matching artwork and sizing. The device user icon pairs a person with a range hood.

## 2026-11-13 · Distinguish device profiles from customers and draw parallel stages in the service workflow.

Stage definitions drive node positions and branch connectors at desktop and mobile widths. Links and status filters retain their original behavior.

## 2026-11-13 · Show device users and customer management as parallel entries before warranty cards.

Group the two independent links into one workflow stage on desktop and mobile. Existing records and routes are preserved.

## 2026-11-13 · Link freight receipts to sales and show optional workspace update notices.

Record carrier slips against approved sales issues, attach multiple photos, and preview or download WebP receipts. The workspace checks the release manifest every minute and on return without extending the login session or interrupting edits.
Verified linked records, partial upload retry, WebP bytes and optional refresh behavior with synthetic desktop and mobile browser scenarios.

## 2026-11-13 — Service users, installed machines and WebP downloads

- Group machines by their actual user across service orders, warranty cards and manual registration. Accept unlisted models and retain QR photos together with the registration, with later photo uploads available.
- Encode new product photos, thumbnails, machine QR photos and mobile avatars as quality-80 WebP. Explicit downloads use the stored bytes and a WebP filename.
- Verified registration and downloads with synthetic records in desktop and mobile browser layouts.

## 2026-11-13 — Marketplace order import and sales commissions

- Map CSV columns to normalized marketplace orders, validate before saving, preserve identifiers, and deduplicate by marketplace, shop, and order. Imports create drafts through the existing approval workflow. The shared intake service is ready for future adapters; live synchronization is not enabled.
- Assign configurable percentage, per-order, and per-unit rules to salespeople. Approval snapshots the customer owner and rule. Track returns, verify receipts or margin explicitly, and review commission details without posting payroll transactions.
- Verified with synthetic multi-platform orders, conflicts, financial rounding, returns, snapshot and permissions tests, plus desktop and mobile browser scenarios.

## 2026-11-13 — Distinct product and service icons

- Give product records a product-card icon while retaining the warehouse symbol.
- Differentiate intake, dispatch, pending work, active repair, completion, and archiving with notebook/pen, person/arrow, clock, wrench, checklist, and archive-box artwork.
- Retain the existing SVG stroke style, module colors, accessible labels, routes, and status filters. Verified syntax, desktop/mobile workflow navigation, screenshots, and status filtering.

## 2026-11-13 — Cached official logo

- Keep the original official PNG and silhouette unchanged; share a content-versioned URL across login, workspace, inventory, and warranty cards.
- Serve the public logo with long-lived immutable caching, ETag validation, and no session cookie. Retain authenticated page/API cache policies.
- Preload the logo and reveal the SVG image after loading completes, avoiding progressive appearance.
- Validation: identical asset bytes, cache headers and 304, private-page no-store, one initial browser download with reuse across navigation/login, and offline cached decoding; source and business regressions pass.

## 2026-11-13 — Account-specific interface font size

- Add four font sizes (100%, 110%, 125%, 150%) in basic settings, stored server-side for the authenticated account and restored on reload.
- Scale navigation, lists, forms, dialogs, dynamic styles, and shared financial typography while retaining the existing hierarchy and print sizing. Synchronize the shell and embedded workspace using source-checked same-origin messages.
- Remove the redundant visible representative-filter caption while preserving its accessible name. Failed saves retain the applied size and offer retry feedback.
- Validation covers account isolation, strict parameters, defaults/reset, desktop/mobile scaling and persistence, dynamic/inline styles and typography variables, failed saves, print behavior, and existing catalog regressions. Public configuration substitutions remain intact.

## 2026-11-13 — Sales representatives and customer ownership

- Add representative management for names, contact details, notes, active status, and customer counts, with entry points in customer navigation and the directory.
- Show and filter customer ownership; assign, reassign, or remove the owner of one or multiple customers. Each customer has one owner; suppliers are excluded. Inactive representatives keep existing customers but cannot receive new assignments.
- Add separate ownership tables without changing existing customer columns. Restrict writes to administrators, validate every selected customer version before atomic updates, increment customer versions, and record ownership changes in the audit log.
- Validate permissions, stale/duplicate batch rejection, inactive staff, supplier exclusion, reassignment/removal, and customer deletion. Local desktop/mobile browser coverage verifies creation, bulk assignment, filtering, reload persistence, and deactivation; existing customer/catalog regressions remain passing. Public demo configuration is preserved.

## 2026-11-13 — Preloaded product image previews

- Preload full images for the current product page and displayed galleries, using two concurrent requests and session-local Blob URLs with image decoding.
- Reuse cached previews on hover/focus; show the thumbnail while an uncached image loads. Update work after pagination, filters, uploads and selection changes, cancel obsolete requests, and release cached URLs on eviction/page exit.
- Bound cached compressed images to 60 entries/24MB without changing authenticated endpoint headers or using persistent storage.
- Validation: JavaScript syntax and local desktop/mobile product regression, including pre-hover loading of 13 images and no additional full-image requests on repeated hover.

## 2026-11-13 — Preserve product search on creation

- Stop replacing the search field with the newly created product code; keep the existing user query.
- Preserve category selection behavior. Validated JavaScript syntax and the desktop/mobile product browser regression, including the empty-search assertion after creation.

## 2026-11-13 — Product entry and sales numbering

- Accept one custom brand name per entry and wrap long names in the product list; preserve existing aliases.
- Upload multiple images while creating a product; preserve the saved product and failed files for retry without duplicate creation.
- Preview gallery thumbnails on hover or keyboard focus, including inside the product dialog.
- Preview sales numbers without consuming them; allocate the final unique number within the save transaction. Previously issued numbers remain untouched.
- Validation: source and business regressions, custom brand validation, canceled/concurrent sales previews and transaction rollback; desktop/mobile product browser checks, image failure/retry, and gallery previews using synthetic local fixtures.

## 2026-11-13 — Multiple product images and remembered table columns

Remove the per-product image-count cap and support multi-file uploads with per-file progress and partial-failure feedback. Retain individual file validation. Desktop tables gain a left-aligned column chooser, persisted per account and list, alongside existing column widths. Include reset, retry, keyboard access and a last-visible-column guard. Synthetic upload and responsive table regressions cover persistence and account isolation.

## 2026-11-13 — Product price presets, customer price recall and simpler warehouse locations

Split unit/SN and purchase/sale price columns. Add ten optional price presets and explicit customer price recall from approved sales, with reference-price fallback and manual overrides. Combine legacy shelf/floor notes into one location input per warehouse. Align product code fields. Local business and synthetic browser checks, patch install/rollback passed. No deployment.

## 2026-11-13 — Warehouse locations and production workflows

- Add free-text shelf/floor notes per product and warehouse, edited alongside product details and shown in stock lookup. Notes do not allocate quantities.
- Introduce versioned BOM templates and assembly/disassembly documents with frozen recipe snapshots, draft/submit/approve/reject/void states, and configurable ZZ/CZ numbering.
- Apply inventory inputs and outputs atomically; protect against repeated approval, insufficient stock, frozen warehouses and stale versions. Track consumed SNs/batches and generated outputs; require explicit SN selection and reject warranty-bound inputs.
- Output costs remain unknown; no automatic financial postings or reverse approval. Simplify three product-field helper labels.
- Verified syntax/business regressions, partial-deduction rollback, recipe snapshots, serial/lot tracking, location conflicts and synthetic desktop browser flows.

## 2026-11-12 — Free-text product names

- Added a full-width required name input (200 characters) and code guidance below its label.
- Accepted custom names while preserving existing classification and inventory constraints.
- Validated synthetic browser create/reload/filter flows, responsive regressions and JS/PHP syntax.

## 2026-11-12 — Product field helper text

- Moved product-code guidance below its input and placed category/model explanations on dedicated secondary lines.
- Preserved business behavior and the mobile breakpoint. JavaScript syntax, existing synthetic browser regressions and screenshots reviewed.

## 2026-11-12 — Directory actions and category hierarchy

- Copy counterparty fields into a new unsaved record without business history. Guard deletion with reference and version checks.
- Clarify desktop category levels using root separators, indentation and leaf markers.
- Validated syntax, business deletion safeguards and existing browser workflows.

## 2026-11-12 — Counterparty forms and category scopes

- Compact desktop create/edit forms with inline category creation.
- Unified tree with independent customer and supplier branches; creation inherits the selected type/category and prevents cross-scope assignments.
- Additive migration preserves entity identities and historical business links, including legacy shared categories.
- Verified scope isolation, same-name roots, migration idempotence, business regressions and synthetic browser workflows/screenshots.

## 2026-11-12 — Narrower product editor

- Reduced desktop product editor width from 1100px to 860px while retaining all fields in one window. Simplified price labels.
- Preserved business behavior and the mobile breakpoint. JavaScript syntax, existing synthetic browser regressions and screenshots reviewed.

## 2026-11-12 — Editable document sequences

- Added configurable purchasing, receipt, transfer and related document numbering while preserving sales rules and historical IDs.
- Reserve numbers when opening forms with idempotent retries and ownership/date validation; canceled forms may leave sequence gaps. Preserve legacy counters and prevent reuse after rule changes.
- Added destination-category search and handled server/browser date boundaries.
- Validated business/syntax regressions, concurrent numbering, rule permissions, catalog/settings UI, purchase/receipt workflows, transfer reservation persistence and sales selectors with synthetic data.

## 2026-11-12 — Product numbering and compact editing

- Added a settings numbering overview and editable product sequence rules, preserving document numbering.
- Continue product sequences above existing codes; compact identity fields and direct destination-category selection.
- Validated syntax, business regressions and synthetic catalog browser checks.

## 2026-11-12 — Integrated product images and workspace returns

- Upload and preview product images inside the product editor without losing unsaved fields.
- Double-click image cells or use the empty-image action to open the relevant editor section.
- Preserve validated return destinations from embedded workspaces and keep legacy inventory entry points in the current shell.
- Verified synthetic uploads, catalog layouts, sales/purchase/service selectors and navigation; business processing is unchanged.

## 2026-11-12 — Product activation and canonical navigation

- New products start enabled, including requests from legacy clients. Explicit editing and bulk deactivation remain available.
- Validated activation values and preserved existing status when omitted during editing.
- Canonical workspace links and legacy inventory redirects keep navigation in the current interface.
- Validated business checks, product creation/persistence and desktop/mobile navigation with synthetic data.

## 2026-11-12 — Pending category validation

- Product saves now require confirmation or cancellation of pending category edits.
- Newly created products are revealed by category and code, preventing stale filters from hiding them.
- Validated business checks and desktop/mobile catalog regression.

## 2026-11-12 — Catalog administration

- Compact configurable product codes with transactional collision avoidance; category subtree moves and cleaner tree labels.
- Test cleanup includes explicitly linked financial records while retaining unrelated and unallocated entries.
- Validated numbering, hierarchy safeguards, financial closure and desktop/mobile catalog regression.

## 2026-11-12 — Account-specific table widths

- Shared desktop resizing with keyboard controls and authenticated per-user preferences.
- Validated CSRF, width bounds, account isolation and desktop/mobile regression.

## 2026-11-12 — Guided category selection

- Replaced manual category-path entry with per-level selectors and inline category creation; changes apply on product save.
- New products inherit the selected desktop folder; existing products retain their category paths.
- Enlarged tree disclosure controls. Validated desktop/mobile catalog regression and local business checks.

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

## 2026-11-12 — Bilingual brand configuration, copy-as-new products, compact editor tabs and image-cell upload.

Existing records remain unchanged. Local business and browser regression checks; no production deployment.

## 2026-11-12 — Single-window product editor with compact category/media layout and editable-number hint.

Existing records remain unchanged. Local business and browser regression checks; no production deployment.

## 2026-11-12 — Product number previews no longer consume codes on cancel; aligned image upload controls.

## 2026-11-12 — Settings center with clear configuration cards, aligned business fields and a separate export section.

## 2026-11-12 — Unified customer/supplier directory and corrected configurable product numbering previews.

Preserves existing entity IDs and document links. Tested with synthetic records; historical sales numbering remains unchanged.

## 2026-11-12 — Custom nested customer categories with inline creation, editing and subtree filtering.

Stable category IDs preserve assignments through rename/reparent. Existing parties remain unclassified until assigned; accounting roles and document references are unchanged. Synthetic tests cover duplicates, cycles, stale writes, depth limits, additive schema upgrade and browser workflows.
