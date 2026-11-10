# Architecture and source map

## Public website

`src/website/app/` contains templates, content handling, product management, and carousel logic. `src/website/public/` contains public and CMS entry points and the official brand asset.

Start with `app/template.html`, `app/common.php`, and the `products-*` modules. Runtime content and uploaded images are external to this source tree.

## Application

`src/service/` contains the browser UI and PHP APIs:

| Files | Responsibility |
| --- | --- |
| `app.js`, `style.css` | Workspace, service views, customer directory |
| `inventory.js`, `inventory.php`, `inventory.css` | Inventory and document workflows |
| `phase2.js`, `phase2.php` | Returns, transfers, and stocktaking |
| `finance.js`, `finance.php` | Ledger and settlement workflows |
| `mini.php` | Mini-program API integration |
| `storage.php` | Persistence |

These modules work together; a single file is not a standalone application. The mini-program client is maintained separately.

## Development workflow

Edit active source in `src/`, update relevant tests, run validation, and record changes in `CHANGELOG.md`. Use focused commits. A commit or push does not deploy anything.

This standalone portfolio snapshot contains no production installers or private archive. Public changes are reviewed separately; the private development repository is not a Git remote or history ancestor of this repository.
