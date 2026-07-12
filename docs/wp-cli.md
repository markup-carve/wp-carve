# WP-CLI

The plugin registers `wp carve migrate` (`WpCarve\CLI\MigrateCommand`, backed by
`WpCarve\Migration\Migrator`) and `wp carve lint` (`WpCarve\CLI\LintCommand`).

## `wp carve migrate`

Convert existing post content into Carve and flag those posts to render as
Carve. Each post is **analyzed first** for safety before any change.

```bash
# Dry-run every post (analysis only, no writes):
wp carve migrate --dry-run

# Migrate all posts of a type:
wp carve migrate --post_type=post

# Migrate a single post:
wp carve migrate --post=123

# Convert even posts the analysis would skip:
wp carve migrate --post=123 --force
```

### Options

| Option | Default | Meaning |
| --- | --- | --- |
| `--post=<id>` | — | Migrate just this post (otherwise all of `--post_type`). |
| `--post_type=<type>` | `post` | Post type to scan when `--post` is not given. |
| `--dry-run` | off | Report what would happen; write nothing. |
| `--force` | off | Convert posts the analysis flags as unsafe to auto-migrate. |

### Analysis

For each post `analyze()` reports a `source` and whether it can be
auto-migrated:

| `source` | Auto-migrate? | Reason |
| --- | --- | --- |
| `none` | no | Missing or empty content. |
| `blocks` | no | Uses the block editor; convert manually (or `--force`). |
| `shortcodes` | no | Contains non-Carve shortcodes; convert manually (or `--force`). |
| `markdown` | yes | Markdown-looking source; converted via `MarkdownToCarve`. |
| `html` | yes | HTML source; converted via `HtmlToCarve`. |

The plugin's own `[carve]` shortcode does **not** count as a blocking
shortcode. Migrated posts get `_wpcarve_enabled = 1` so the `the_content`
filter renders them as Carve.

### Bulk migrate without WP-CLI (Tools → Carve Migrate)

For sites without shell access, **Tools → Carve Migrate** runs the same
migration through the admin. It lists posts per type with the analysis above -
the listing is the dry-run preview: each row shows the detected `source` and,
for flagged posts, why it is skipped. Tick the posts to convert (eligible ones
are pre-checked) and choose **Migrate selected posts**. A **Force** checkbox
converts flagged posts too (same as `--force`; back up first).

The screen requires the `edit_others_posts` capability, and each post is
re-checked with `edit_post` before it is touched. Posts already rendering as
Carve are omitted from the list.

## `wp carve lint`

Read-only health check for Carve content. Renders every Carve-enabled post (the
"Render as Carve" toggle or a `carve/markup` block) and reports render errors
plus the features the **Visual editor simplifies** relative to the front end.

```bash
# Lint every Carve post:
wp carve lint

# Lint one post, or one post type:
wp carve lint --post=123
wp carve lint --post_type=page
```

Each line is `OK` / `ERROR` with the post ID, title, and any caveats:

```
OK     #6  Carve TOC demo  [visual editor simplifies: table of contents]
```

### What it reports

| Column | Meaning |
| --- | --- |
| `ERROR` | The source threw during render, or rendered empty (broken Carve). |
| visual editor simplifies | Generated markup present on the front end but intentionally omitted from the visual-editor seed - a table of contents, heading permalinks, diagrams, or abbreviations. These are render-time output, not part of the source, so the Visual editor shows the plain source instead (it never freezes into the post). Not a problem - just so you know what differs. |

It writes nothing; use it before or after a migration to spot content that fails
to render.
