# WP-CLI

The plugin registers a `carve` command (`WpCarve\CLI\MigrateCommand`, backed by
`WpCarve\Migration\Migrator`).

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
shortcode. Migrated posts get `_wp_carve_enabled = 1` so the `the_content`
filter renders them as Carve.
