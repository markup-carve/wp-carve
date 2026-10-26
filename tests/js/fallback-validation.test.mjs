import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');

test('old null-save blocks retain the dynamic validation contract', () => {
  const types = new Map();
  const wp = {
    blocks: { registerBlockType: (name, config) => types.set(name, config) },
    element: { createElement() {}, useState() {}, useEffect() {}, useRef() {} },
    blockEditor: { useBlockProps() {} },
    components: {},
    i18n: { __: text => text },
  };
  runInNewContext(source, { window: { wp, wpCarve: {} } });
  for (const name of ['markup', 'slides', 'admonition', 'code-group', 'table-spans']) {
    const block = types.get(`carve/${name}`);
    assert.ok(block, `${name} registered`);
    assert.equal(block.save({ attributes: { carve: '# Existing' } }), null);
  }
});
