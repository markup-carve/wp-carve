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
