# Carve syntax (quick reference)

A short cheat sheet for the markup this plugin renders. Carve is a post-Markdown
dialect; the full normative spec and corpus live in the
[Carve repo](https://github.com/markup-carve/carve) (`docs/examples.md`,
`resources/grammar.ebnf`). Note the emphasis delimiters differ from Markdown.

## Inline emphasis

| Carve | Renders | |
| --- | --- | --- |
| `/italic/` | `<em>italic</em>` | word-boundary restricted |
| `*bold*` | `<strong>bold</strong>` | intraword OK (`foo*bar*baz`) |
| `_underline_` | `<u>underline</u>` | word-boundary restricted |
| `~strike~` | `<s>strike</s>` | |
| `^super^` | `<sup>super</sup>` | |
| `,sub,` | `<sub>sub</sub>` | |
| `=highlight=` | `<mark>highlight</mark>` | |
| `` `code` `` | `<code>code</code>` | never parsed for syntax inside |

Emphasis nests (`*bold with /italic/ inside*`). Whitespace right after an opener
or before a closer blocks it (`/ not emphasis /` renders literally).

## Blocks

```text
# Heading 1
## Heading 2  {#custom-id .css-class}

A paragraph. A single newline stays inside the paragraph (a soft break). For a
visible line break use a trailing backslash `\` (hard break) or a `::: |` line
block.

- Bullet list
- [ ] Task, unchecked
- [x] Task, checked

1. Ordered list

> Blockquote
^ Attribution caption

| Col A | Col B |
|-------|-------|
| cell  | cell  |
```

Fenced code uses backticks or tildes with a language after the fence:

````text
``` php
echo 'highlighted with Torchlight when enabled';
```
````

Carve fences accept a single language token only. Djot-style language strings
such as ```` ``` php # ```` or ```` ``` php #=42 ```` are not Carve fences and
will not render as code blocks.

Per-block code attributes go on the preceding attribute line:

````text
{.line-numbers data-line-start=42 title="Example"}
``` php
echo 'starts at line 42';
```
````

When Torchlight is enabled, `.line-numbers` asks Torchlight to render its
server-side gutter for that block. The global `torchlight_line_numbers` setting
shows line numbers for every Torchlight code block by default.

Torchlight annotations live inside the code and are stripped from the rendered
output while applying highlighting, diff, or focus styles:

````text
``` php
$safe = clean($input);   // [tl! highlight]
$old  = legacy();        // [tl! --]
$new  = modern();        // [tl! ++]
$focusMe();              // [tl! focus]
```
````

## Links, images, attributes

```text
[link text](https://example.com)
![alt text](image.png)
[styled span]{.highlight #note key=val}
```

## Carve-specific constructs

| Syntax | Result |
| --- | --- |
| `@alice` | mention span |
| `#release-1.0` | tag span |
| `$` ` `…` ` ` / `$$` blocks | math (KaTeX on the front end) |
| `::: note` … `:::` | admonition / generic div |
| `[^1]` + `[^1]: …` | footnote |
| `term`<br>`: definition` | definition list |
| `---yaml` … `---` (top of doc) | frontmatter -> meta/SEO |

Extensions like table of contents, heading permalinks, smart quotes, Mermaid and
Torchlight are toggled in [Settings](settings.md). For the exact, pinned
behavior of every construct, read the corpus in the Carve repo.
