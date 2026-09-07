import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');

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
