# DEAOE Website & Application Platform

A full-stack PHP and JavaScript project featuring a responsive product website, a content management system, and a browser-based operations application.

**This is a standalone, sanitized portfolio snapshot.** It has a new Git history and contains no imported private commits, production datasets, internal operating manuals, or deployment archives. Contact fields and integration identifiers use demonstration values.

## What this project demonstrates

- **Responsive UI engineering:** mobile navigation, compact record lists, searchable selectors, fixed-header anchor alignment, and touch-driven image galleries with reduced-motion support.
- **Full-stack development:** PHP APIs, SQLite persistence, CMS publishing, document entry, and inventory and account-ledger modules.
- **Data integrity:** transactional updates, repeat-request protection, stock validation, concurrent document numbering, and historical snapshots.
- **Testing:** isolated SQLite regressions, JavaScript interaction checks, and Playwright flows at desktop and mobile viewport sizes.
- **Maintainability:** separated UI/API modules, a repeatable local preview, focused commits, and a concise changelog.

The application UI remains Chinese, reflecting its original users. Project documentation and the engineering history are in English.

## Explore the implementation

| Location | Responsibility |
| --- | --- |
| `src/website/` | Website, product catalogue, image galleries, and CMS |
| `src/service/` | Application UI, API endpoints, and workflow persistence |
| `tests/` | Synthetic backend and browser regression tests |
| `tools/` | Local preview and validation runner |
| `docs/` | Architecture, testing boundaries, and third-party notices |

Suggested reading:

1. [Architecture and source map](docs/SOURCE-MAP.md)
2. [Engineering changelog](CHANGELOG.md)
3. [Test coverage and execution](tests/WEBAPP-TESTING.md)
4. [Public snapshot scope](docs/PRIVACY.md)

## Run a local website preview

Requirements: Python 3.10+, PHP 8.4 with PDO SQLite, GD and mbstring, and Node.js for JavaScript checks.

```sh
python3 tools/preview_website.py
```

Open `http://127.0.0.1:8765`. The terminal prints temporary CMS credentials. The preview runs from a temporary copy; edits are discarded when it exits. Restart the preview after changing source files.

```sh
python3 tools/check.py
```

This runs source syntax checks and isolated regressions. Optional browser tests require Playwright and Google Chrome; see [the testing guide](tests/WEBAPP-TESTING.md).

## Version control

This repository starts with one independently reviewed public baseline. Earlier development milestones are summarized in the changelog, not imported as private Git commits. Subsequent public updates should use focused commits, relevant checks, and English changelog entries.

A push does not deploy an application. This repository supplies no production credentials, infrastructure configuration, or operating runbook.

## Limitations

- Production content and uploaded media are intentionally absent. The default hero image is not included, so the preview is not a complete replica of a deployed website.
- External account integrations require separate configuration; demonstration identifiers do not connect to a real account.
- Mobile browser tests use viewport emulation, not physical-device certification.
- Brand assets are retained for project context. Third-party code notices are in [THIRD_PARTY_LICENSES.txt](docs/THIRD_PARTY_LICENSES.txt).
