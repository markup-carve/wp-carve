# Carve Workbench

The Carve block includes an AST-aware authoring workbench in the block inspector.
It uses the same Carve engine as the live preview, so positions and structural
changes describe Carve constructs rather than approximating them as Markdown.

## Document health

The health panel combines the engine linter with host-level checks. Each result
links to its source line. It currently reports Carve lint rules, parse failures,
invalid CSL-JSON, and citation keys that cannot be resolved from either an
in-document definition or the block bibliography.

## Navigator and commands

The navigator lists headings with their nesting and jumps the source editor to
the selected line. **Open command palette** (or `Ctrl/Cmd + Shift + P` while in
the source editor) searches common constructs and inserts them at the cursor.

## Semantic changes

The changes panel compares the current Carve AST with the corresponding block in
the last saved post. It reports added, removed and changed top-level blocks
(carve-js 0.2 has no AST diff, so the plugin compares the encoded trees itself).
Save the post to establish a new baseline.

## Citations

The citations panel accepts a CSL-JSON array and supports numbered and
author-date rendering, and the panel checks the keys. Cite an
entry by its `id` with the 0.2 spelling (U-411) and place the references list:

```carve
Research supports this claim :cite[knuth84].

::: references
:::
```

```json
[{"id":"knuth84","title":"Literate Programming","author":[{"family":"Knuth","given":"Donald"}],"issued":{"date-parts":[[1984]]}}]
```

In-document `[@key]: Definition` entries take precedence over CSL entries with
the same key.

