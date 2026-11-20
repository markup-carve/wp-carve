# Carve syntax (quick reference)

A short cheat sheet for the Carve 0.2 markup this plugin renders. Carve is a
post-Markdown dialect; the normative 0.2 spec lives in the
[Carve repo](https://github.com/markup-carve/carve) under `spec/0.2/`, and
`spec/0.2/UPGRADING.md` lists every change from 0.1. Note the emphasis
delimiters differ from Markdown.

## Inline emphasis

| Carve | Renders | |
| --- | --- | --- |
| `/italic/` | `<em>italic</em>` | word-boundary restricted |
| `*bold*` | `<strong>bold</strong>` | word-boundary restricted (`foo*bar*baz` stays literal) |
| `{_underline_}` | `<u>underline</u>` | braced only - a bare `_` is literal |
| `{~strike~}` | `<s>strike</s>` | braced only - a bare `~` is literal |
| `{^super^}` | `<sup>super</sup>` | braced only - a bare `^` is literal |
| `{,sub,}` | `<sub>sub</sub>` | braced only - a bare `,` is literal |
| `{=highlight=}` | `<mark>highlight</mark>` | braced only - a bare `=` is literal |
| `` `code` `` | `<code>code</code>` | never parsed for syntax inside |

Emphasis nests (`*bold with /italic/ inside*`). Whitespace right after an opener
or before a closer blocks it (`/ not emphasis /` renders literally).

## Blocks

```text
= Heading 1

{#custom-id .css-class}
== Heading 2

A paragraph. A single newline stays inside the paragraph (a soft break). For a
visible line break use a trailing backslash `\` (hard break) or a `::: |` line
block.

- Bullet list
- [ ] Task, unchecked
- [x] Task, checked

Ordered and letter lists:

1. Ordered list

a. Letter list (two spaces after a letter or roman marker)

***

> Blockquote
^ Attribution caption

|= Col A |= Col B |
| cell   | cell   |

|={<} Item |={>} Price |
| Tea      | 3.50      |
| Cake     |{~} n/a    |
```

Column alignment goes in braces right after the header marker (`|={<}`,
`|={~}`, `|={>}`); a body cell overrides its column with `|{>}`. The glued
0.1.13 spelling (`|=>`, `|<`) is gone in 0.2: it renders as cell text.

Headings are written with `=` (`= Title`, `== Section`); the `#` spelling still
reads. An attribute line above a block sets its id and classes. The editor
writes thematic breaks as `***`.

Fenced code uses backticks or tildes with a language after the fence:

````text
``` php
echo 'highlighted with Torchlight when enabled';
```
````

A fence takes one language word.

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
[styled span]{#note .highlight key=val}
```

## Carve-specific constructs

| Syntax | Result |
| --- | --- |
| `@alice` | mention link (needs `mentions_enabled`; otherwise literal text); the name needs an ASCII letter |
| `#release-1.0` | tag link (needs `mentions_enabled`; otherwise literal text; outside the `comment` profile) |
| `:wiki[Page Title]` | link to the page with that title (needs `wikilinks_enabled`) |
| `$` ` `…` ` ` / `$$` blocks | math (KaTeX on the front end) |
| `::: note` … `:::` | admonition / generic div |
| `[^1]` + `[^1]: …` | footnote |
| `:: term`<br>`:  definition` (two spaces) | definition list; the term is one line |
| `::: list-table` + nested list | table built from the nested list (`extension_list_table`) |
| `---yaml` … `---` (top of doc) | frontmatter -> meta/SEO |
| `:cite[key]` | citation, resolved against the block's CSL-JSON bibliography |
| `---` on the first line, no closer | frontmatter to the end of the post (write `***` for a break) |

Extensions like table of contents, heading permalinks, smart quotes, Mermaid and
Torchlight are toggled in [Settings](settings.md). For the exact, pinned
behavior of every construct, read the corpus in the Carve repo.
