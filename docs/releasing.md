# Releasing & WordPress.org submission

This plugin releases the same way as its sibling `wp-djot`: a GitHub release
triggers an automated deploy to the WordPress.org plugin SVN.

## How a release works

1. **Update all dependencies to their latest first.** The Carve packages are
   tracked on dev branches (`markup-carve/carve-php: dev-main`, the media-embed
   extension, and the npm engine/grammars on `#main`), so a release must ship
   against their current `main` - not a stale locked commit:

   ```bash
   composer update            # pull latest carve-php + all Composer deps
   npm update                 # pull latest @markup-carve/carve + carve-grammars
   npm run build              # rebuild engine + editor bundles against them
   ```

   Then re-run the gates (`composer test`, `composer stan`, `composer cs-check`,
   `npm run test:js`) so the new versions are verified before tagging. Commit the
   refreshed `composer.lock`, `package-lock.json`, and rebuilt bundles.

2. Bump the version everywhere with the helper script:

   ```bash
   ./scripts/version.sh 0.1.0
   ```

   It updates `wp-carve.php` (header `Version:` and the `WP_CARVE_VERSION`
   constant), `readme.txt` (`Stable tag:`), `package.json`, and each
   `assets/blocks/*/index.asset.php` version.

3. Update `CHANGELOG.md` and the `== Changelog ==` section of `readme.txt`.

4. Commit, then publish a GitHub release whose **tag equals the version**
   (bare `0.1.0`, no `v` prefix - matching the rest of the Carve ecosystem).

5. `.github/workflows/deploy.yml` runs on `release: published`:
   - validates header == constant == stable tag == release tag (fails loudly
     if any drift),
   - runs syntax check + PHPStan,
   - **downgrades bundled PHP 8.1/8.2 syntax to 8.0** so WordPress.org's older
     SVN pre-commit PHP lint accepts the bundled `torchlight/engine` + `phiki`
     (Rector via `rector.php`, plus manual patches for trait constants and
     `enum->value` in const expressions),
   - strips dev dependencies and re-dumps the autoloader,
   - deploys to WordPress.org via `10up/action-wordpress-plugin-deploy`,
   - attaches the built zip to the GitHub release.

`.distignore` controls what is excluded from the deployed SVN tree (tests,
build tooling, docs, vendor cruft).

## One-time prerequisites for WordPress.org

These are **not yet done** and are the gate to a public listing:

1. **Submit the plugin for review.** Create a WordPress.org account, then submit
   the plugin zip at <https://wordpress.org/plugins/developers/add/>. A human
   reviewer checks it (typically days to a few weeks). The chosen permalink
   becomes the **slug**; it must match `SLUG: carve-markup` in `deploy.yml`
   (change one to match the other if the assigned slug differs).

2. **Pass Plugin Check.** Install the official `Plugin Check (PCP)` plugin and
   run it locally before submitting. Fix every error and as many warnings as
   possible. Common items: proper escaping/sanitization, text-domain on every
   `__()`/`esc_*__()`, no direct file access (the `ABSPATH` guards already
   handle this), prefixed globals/options/hooks.

3. **License compatibility.** WordPress.org requires a GPL-compatible license.
   MIT is GPL-compatible, so the current `License: MIT` is acceptable - but
   confirm the reviewer is happy with MIT rather than GPLv2+.

4. **Set the SVN secrets.** After approval, add repository secrets
   `SVN_USERNAME` and `SVN_PASSWORD` (your WordPress.org login) so the deploy
   workflow can push to SVN. Until these exist the workflow will fail at the
   deploy step.

5. **Add the directory assets** (optional but expected) under a top-level
   `.wordpress-org/` folder, which the 10up action syncs to the SVN `assets/`
   dir: `icon-256x256.png`, `banner-772x250.png` (and `@2x`), and
   `screenshot-1.png` … matched to the `== Screenshots ==` section of
   `readme.txt`.

6. **`Tested up to`.** Keep `readme.txt`'s `Tested up to:` current with the
   latest WordPress release before each submission/update.

## Checklist before tagging 0.1.0

- [ ] `composer update` + `npm update` run; lockfiles committed (deps at latest).
- [ ] `npm run build` run against the updated deps (engine + editor bundles rebuilt + committed).
- [ ] `./scripts/version.sh 0.1.0` run; versions consistent.
- [ ] `CHANGELOG.md` + `readme.txt` changelog updated.
- [ ] `composer test`, `composer stan`, `composer cs-check`, `npm run test:js` all green.
- [ ] Plugin Check (PCP) passes.
- [ ] WordPress.org listing approved and slug confirmed.
- [ ] `SVN_USERNAME` / `SVN_PASSWORD` secrets set.
- [ ] `.wordpress-org/` assets added.
