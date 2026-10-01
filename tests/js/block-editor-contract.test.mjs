import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');
const codeBlocks = await readFile(new URL('../../assets/js/code-blocks.js', import.meta.url), 'utf8');

test('focused Carve blocks retain source-first metadata', () => {
  for (const name of ['carve/admonition', 'carve/code-group', 'carve/table-spans']) {
    assert.match(source, new RegExp(name.replace('/', '\\/')));
  }
  assert.match(source, /supports: \{ html: false \}/);
  assert.match(source, /attributes: \{ carve: \{ type: 'string', default: '' \} \}/);
});

test('a newer source revision stops the stale visual editor', () => {
  assert.match(source, /current !== visualRevisionRef\.current/);
  assert.match(source, /ctlRef\.current\?\.destroy\(\)/);
  assert.match(source, /! sessionActiveRef\.current/);
  assert.match(source, /gated \|\| revisionConflict/);
});

test('a preview that may expand includes renders on the server', () => {
  assert.match(source, /const serverIncludes = cfg\.includes && source\.indexOf\( '\{\{' \) !== -1;/);
  assert.match(source, /! forceServer && ! serverIncludes && /);
});

test('code-fence chrome stays anchored to its block in editor previews', () => {
  assert.match(styles, /pre:not\(\.mermaid\)[^{]*\{[^}]*position: relative;/s);
  assert.match(styles, /pre:not\(\.mermaid, \.graphviz, \.wavedrom, \.abc, \.plantuml\)\[title\]:not\(\[data-title\]\)::before/);
  assert.match(styles, /content: attr\(title\)/);
  assert.match(source, /wrap\.className = 'wpcarve-codewrap'/);
  assert.match(source, /wrap\.dataset\.title = pre\.dataset\.title \|\| pre\.title/);
  assert.match(source, /wrap\.dataset\.lang = pre\.dataset\.lang/);
  assert.match(codeBlocks, /pre\.dataset\.title \|\| pre\.title/);
});

test('a dark dual-theme fence leaves the diff wash and its marker alone', () => {
  // The engine never sees a {.diff} marker, so a diff row carries no
  // --phiki-dark-background and these !important rules would unset the wash
  // pre.has-diff gives it, and repaint the marker in the editor foreground.
  const rowRules = styles.match(/\.phiki-themes \.line[^{]*\{\s*background-color: var\(--phiki-dark-background\)/g);
  assert.equal(rowRules.length, 2, 'both the media-query and the data-theme rule exist');
  for (const rule of rowRules) {
    assert.match(rule, /\.line:not\(\.diff\)/);
  }
  const colorRules = styles.match(/\.phiki-themes span[^{]*\{\s*color: var\(--phiki-dark-color\)/g);
  assert.equal(colorRules.length, 2);
  for (const rule of colorRules) {
    assert.match(rule, /span:not\(\.diff-marker\)/);
  }
  const bgRules = styles.match(/\.phiki-themes span[^{]*\{\s*background-color: var\(--phiki-dark-background-color\)/g);
  assert.equal(bgRules.length, 2);
  for (const rule of bgRules) {
    assert.match(rule, /span:not\(\.diff-marker\):not\(\.line-number\)/);
  }
});
