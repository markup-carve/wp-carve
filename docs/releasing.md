# Releasing & WordPress.org submission

This plugin releases the same way as its sibling `wp-djot`: a GitHub release
triggers an automated deploy to the WordPress.org plugin SVN.

## How a release works

1. **Update all dependencies to their latest first.** `markup-carve/carve-php`,
   the media-embed extension and the npm grammars are released packages, so they
   move by their published versions. The Composer `markup-carve/carve-grammars`
   and the npm engine are still pinned to a revision on `main`, so a release must
   ship against their current `main` - not a stale locked commit:

   ```bash
   composer update            # pull latest carve-php + all Composer deps
   npm update                 # pull the declared Carve engine and grammar versions
   npm run build              # rebuild engine + editor bundles against them
   ```

   Then re-run the gates (`composer test`, `composer stan`, `composer cs-check`,
   `npm run test:js`) so the new versions are verified before tagging. Commit the
   refreshed `composer.lock`, `package-lock.json`, and rebuilt bundles.

2. Bump the version everywhere with the helper script:

   ```bash
   ./scripts/version.sh 0.1.0
   ```

   It updates `carve-markup.php` (header `Version:` and the `WPCARVE_VERSION`
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

## WordPress.org listing

The plugin is **listed**: <https://wordpress.org/plugins/carve-markup/>, under
the slug `carve-markup` that `SLUG` in `deploy.yml` has to keep matching. The
one-time work below is done, and is recorded so that a later move - a rename, a
second plugin, a transferred account - starts from what was actually needed
rather than from scratch.

1. **Submitted and reviewed.** The assigned permalink became the slug.
2. **Plugin Check passes**, and does not rely on anyone remembering to run it:
   the `plugin-check` job in `ci.yml` runs it on every push.
3. **License.** MIT, which is GPL-compatible and was accepted.
4. **SVN secrets.** `SVN_USERNAME` and `SVN_PASSWORD` are set; `deploy.yml`
   reads both and fails loudly rather than skipping when either is missing.
5. **Directory assets** are in `.wordpress-org/`, which the 10up action syncs to
   the SVN `assets/` directory: `icon-128x128.png`, `icon-256x256.png`,
   `banner-772x250.png`, `banner-1544x500.png`, and `screenshot-1.png` through
   `screenshot-4.png` matched to `== Screenshots ==` in `readme.txt`.

The one item that stays live for every release:

- **`Tested up to`** in `readme.txt` wants checking against the current
  WordPress release before each deploy. Nothing enforces it, because a plugin
  that has not been tested against a release should not claim it has.

## Checklist before tagging a release

Was written for 0.1.0 and kept its four one-time items long after they were
done, which is how a checklist stops being read. Those moved to the listing
section above; what is left runs every time.

- [ ] `composer update` + `npm update` run; lockfiles committed (deps at latest).
- [ ] `npm run build` run against the updated deps (engine + editor bundles rebuilt + committed).
- [ ] `./scripts/version.sh X.Y.Z` run; versions consistent.
- [ ] `CHANGELOG.md` + `readme.txt` changelog updated.
- [ ] `readme.txt` `Tested up to:` checked against the current WordPress release.
- [ ] `composer test`, `composer stan`, `composer cs-check`, `npm run test:js` all green.
- [ ] `bash scripts/lint-dist-floor.sh` green against a freshly staged and downgraded tree.
