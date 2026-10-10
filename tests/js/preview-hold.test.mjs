import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { Window } from 'happy-dom';

const listSource = readFileSync(new URL('../../assets/js/list-continuation.js', import.meta.url), 'utf8');
const toggleSource = readFileSync(new URL('../../assets/js/inline-toggle.js', import.meta.url), 'utf8');
const blockSource = readFileSync(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');
const documentSource = readFileSync(new URL('../../assets/js/code-editor.js', import.meta.url), 'utf8');

const sandbox = { window: {} };
runInNewContext(listSource, sandbox);
const { edit, isBareMarker, holdsPreview } = sandbox.window.wpCarveListContinuation;
const { carveToHtml } = await import('@markup-carve/carve');

// `|` marks the caret.
function holds(marked) {
  const at = marked.indexOf('|');
  return holdsPreview(marked.slice(0, at) + marked.slice(at + 1), at);
}

const BARE = [
  '-', '- ', '*', '* ', '-\t',
  '1.', '2. ', '10)', 'a.', 'B)', 'i.', 'iv) ', 'XII.', '.', '. ',
  '- [ ]', '- [ ] ', '* [x]', '- [X]', '- [-]', '- [>]', '- [?]', '- [_]',
  '  - ', '    1. ', '> - ', '> > 1.', '-{.c}',
];
const NOT_BARE = [
  '', ' ', '+', '+ ', '- a', '1. one', '- [ ] todo', '-a', '--', '---', '* * *', '- - -',
  'ab.', 'iiii.', '1', 'a', '-[ ]', 'text -', '> ', '- [y]', '1. [ ]', ')',
];

for (const line of BARE) {
  test(`bare marker: ${JSON.stringify(line)}`, () => {
    assert.equal(isBareMarker(line), true);
  });
}

for (const line of NOT_BARE) {
  test(`not a bare marker: ${JSON.stringify(line)}`, () => {
    assert.equal(isBareMarker(line), false);
  });
}

test('the engine folds a bare marker into the item above, which is what the hold hides', () => {
  assert.match(carveToHtml('- first\n- '), /<li>first\s+-<\/li>/);
});

test('the preview holds on a bare marker typed under a list', () => {
  assert.equal(holds('- first\n- second\n- |'), true);
  assert.equal(holds('- first\n- se|cond\n- '), false);
  assert.equal(holds('- first\n- second\n- t|'), false);
  assert.equal(holds('- first\n|- second\n- '), false);
});

test('the caret anywhere on the bare-marker line holds', () => {
  assert.equal(holds('- a\n|- '), true);
  assert.equal(holds('- a\n-| '), true);
});

test('a marker inside a code fence does not hold', () => {
  assert.equal(holds('```\n- |\n```\n'), false);
  assert.equal(holds('- a\n```\n- \n```\n- |'), true);
});

test('a fence closes only on the same character, at least as long, with nothing after it', () => {
  assert.equal(holds('````\n~~~~\n``` js\n```\n-|'), false);
  assert.equal(holds('````\n`````\n-|'), true);
});

test('a fence opened on a list-item line still counts', () => {
  assert.equal(holds('- ```\n  - |\n  ```\n'), false);
  assert.equal(holds('- ```\n  x\n  ```\n- |'), true);
  assert.equal(holds('- - ```\n    - |\n    ```\n'), false);
  assert.equal(holds('- [ ] ~~~\n  - |\n'), false);
});

test('a fence opened inside a block quote or a description still counts', () => {
  assert.equal(holds('> ```\n> - |\n'), false);
  assert.equal(holds('> ```\n> x\n> ```\n> - |'), true);
  assert.equal(holds(':: term\n: ```\n  - |\n  ```\n'), false);
});

test('a quoted fence line inside a top-level fence is code, not a closer', () => {
  assert.match(carveToHtml('```\n> ```\n- x\n'), /<pre><code>&gt; ```\s+- x/);
  assert.equal(holds('```\n> ```\n-|'), false);
  assert.equal(holds('> ```\n> > ```\n> -|'), false);
  assert.equal(edit('```\n> ```\n- x', 13), null);
});

test('a task box on an ordered item is text, so it opens no fence', () => {
  assert.match(carveToHtml('1. [ ] ~~~\n  1.\n'), /<li>\[ \] ~~~\s+1\.<\/li>/);
  assert.equal(holds('1. [ ] ~~~\n  1.|\n'), true);
});

test('an inline code span is not a fence opener', () => {
  assert.equal(holds('```code```\n\n- a\n- |'), true);
  assert.equal(holds('~~~ a~b\n-|'), true);
});

test('a marker line is not a fence closer', () => {
  assert.equal(holds('```\n- ```\n-|'), false);
});

test('an unclosed fence runs to the end', () => {
  assert.equal(holds('~~~\nx\ny\n-|'), false);
});

test('a marker inside a comment block does not hold', () => {
  assert.equal(holds('%%%\n- |\n%%%\n'), false);
});

test('CRLF line endings split like LF', () => {
  assert.equal(holds('- a\r\n- |\r\n'), true);
});

test('a long run of roman markers stays fast', () => {
  const line = 'i. '.repeat(5000) + 'x';
  const started = Date.now();
  assert.equal(isBareMarker(line), false);
  assert.equal(holds('```\n' + line + '\n-|'), false);
  assert.ok(Date.now() - started < 1000);
});

function nodes(tree, type) {
  if (Array.isArray(tree)) return tree.flatMap(node => nodes(node, type));
  if (!tree || typeof tree !== 'object') return [];
  return [...(tree.type === type ? [tree] : []), ...nodes(tree.children || [], type)];
}

// Deterministic timers: `flush()` runs every pending callback.
function fakeTimers() {
  let next = 1;
  const pending = new Map();
  return {
    setTimeout(callback) { pending.set(next, callback); return next++; },
    clearTimeout(id) { pending.delete(id); },
    flush() {
      for (const [id, callback] of [...pending]) {
        pending.delete(id);
        callback();
      }
    },
  };
}

// The block in Split mode, rendered through the REST path with effects run
// after every render.
function blockEditor(source, caret) {
  let blockEdit;
  const hooks = [];
  let hookIndex = 0;
  let effects = [];
  const attributes = { carve: source };
  const element = {
    createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
    useState(initial) {
      const slot = hookIndex++;
      if (!(slot in hooks)) hooks[slot] = initial;
      return [hooks[slot], value => { hooks[slot] = value; }];
    },
    useRef(initial) {
      const slot = hookIndex++;
      if (!(slot in hooks)) hooks[slot] = { current: initial };
      return hooks[slot];
    },
    useEffect(effect, deps) {
      const slot = hookIndex++;
      const previous = hooks[slot];
      if (!previous || !deps || deps.some((dep, at) => dep !== previous.deps[at])) {
        effects.push(() => {
          if (previous && previous.cleanup) previous.cleanup();
          hooks[slot] = { deps, cleanup: effect() };
        });
      }
    },
  };
  const rendered = [];
  const timers = fakeTimers();
  const wp = {
    blocks: { registerBlockType: (name, config) => { if (name === 'carve/markup') blockEdit = config.edit; } },
    element,
    blockEditor: { InspectorControls: 'InspectorControls', BlockControls: 'BlockControls', useBlockProps: props => props },
    components: Object.fromEntries([
      'TextareaControl', 'Notice', 'Button', 'ButtonGroup', 'PanelBody', 'SelectControl',
      'RangeControl', 'TextControl', 'Modal', 'ToolbarGroup', 'ToolbarButton', 'ToolbarDropdownMenu',
    ].map(name => [name, name])),
    i18n: { __: text => text },
    data: { select: () => null },
    apiFetch: ({ data }) => {
      rendered.push(data.carve);
      return new Promise(() => {});
    },
  };
  const noop = () => {};
  const window = {
    wp,
    wpCarve: { restRender: '/render' },
    requestAnimationFrame: callback => callback(),
    addEventListener: noop,
    removeEventListener: noop,
    innerHeight: 800,
  };
  runInNewContext(toggleSource, { window });
  runInNewContext(listSource, { window });
  runInNewContext(blockSource, {
    window,
    document: { addEventListener: noop, removeEventListener: noop },
    setTimeout: timers.setTimeout,
    clearTimeout: timers.clearTimeout,
  });
  const ownerDocument = { activeElement: null };
  const textarea = { selectionStart: caret, selectionEnd: caret, focus() {}, style: {}, scrollHeight: 0, ownerDocument };
  ownerDocument.activeElement = textarea;

  let field;
  function draw() {
    hookIndex = 0;
    effects = [];
    const tree = blockEdit({ attributes, setAttributes: next => Object.assign(attributes, next), clientId: 'block-1' });
    field = nodes(tree, 'textarea')[0];
    field.props.ref.current = textarea;
    effects.forEach(run => run());
    return tree;
  }
  const tree = draw();
  nodes(tree, 'Button').find(button => button.props.key === 'split').props.onClick();
  draw();
  timers.flush();

  return {
    rendered,
    textarea,
    type(value, at) {
      attributes.carve = value;
      textarea.selectionStart = textarea.selectionEnd = at;
      draw();
      timers.flush();
    },
    move(at) {
      textarea.selectionStart = textarea.selectionEnd = at;
      field.props.onSelect({});
    },
    blur() {
      ownerDocument.activeElement = null;
      field.props.onBlur({});
    },
  };
}

test('the block Split preview holds on a bare marker and renders once the line gets content', () => {
  const editor = blockEditor('- a', 3);
  assert.deepEqual(editor.rendered, ['- a']);
  editor.type('- a\n- ', 6);
  assert.deepEqual(editor.rendered, ['- a']);
  editor.type('- a\n- b', 7);
  assert.deepEqual(editor.rendered, ['- a', '- a\n- b']);
});

test('the block Split preview renders a held marker once the cursor leaves its line', () => {
  const editor = blockEditor('- a', 3);
  editor.type('- a\n- ', 6);
  editor.move(5);
  assert.deepEqual(editor.rendered, ['- a']);
  editor.move(1);
  assert.deepEqual(editor.rendered, ['- a', '- a\n- ']);
});

test('the block Split preview renders a held marker when the source loses focus', () => {
  const editor = blockEditor('- a', 3);
  editor.type('- a\n- ', 6);
  editor.blur();
  assert.deepEqual(editor.rendered, ['- a', '- a\n- ']);
});

// Enough of CodeMirror 5 for the classic editor's preview path, with positions
// kept as plain offsets.
function fakeCodeMirror(value, caret) {
  const handlers = {};
  return {
    value,
    caret,
    focused: true,
    on(name, handler) { (handlers[name] = handlers[name] || []).push(handler); },
    emit(name) { (handlers[name] || []).forEach(handler => handler(this)); },
    refresh() {},
    hasFocus() { return this.focused; },
    getScrollInfo: () => ({ top: 0, height: 0, clientHeight: 0 }),
    addKeyMap() {},
    getValue() { return this.value; },
    getCursor() { return this.caret; },
    indexFromPos: pos => pos,
    type(next, at) { this.value = next; this.caret = at; this.emit('change'); this.emit('cursorActivity'); },
  };
}

function classicEditor(cm) {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<textarea id="content"></textarea><div class="wpcarve-live-preview-wrap"><div id="wpcarve-live-preview"></div></div>';
  const rendered = [];
  const timers = fakeTimers();
  window.wp = {
    codeEditor: { initialize: () => ({ codemirror: cm }) },
    apiFetch: ({ data }) => {
      rendered.push(data.carve);
      return new Promise(() => {});
    },
  };
  window.wpCarve = { codeEditor: cm ? {} : null, restRender: '/render' };
  runInNewContext(toggleSource, { window });
  runInNewContext(listSource, { window });
  runInNewContext(documentSource, {
    window, document, Event: window.Event, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout,
  });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  // Split mode, the way the mode buttons set it.
  rendered.length = 0;
  return { window, document, rendered, timers };
}

test('the classic CodeMirror preview holds on a bare marker until the line gets content', () => {
  const cm = fakeCodeMirror('- a', 3);
  const { rendered, timers } = classicEditor(cm);
  cm.type('- a\n- ', 6);
  timers.flush();
  assert.deepEqual(rendered, []);
  cm.type('- a\n- b', 7);
  timers.flush();
  assert.deepEqual(rendered, ['- a\n- b']);
});

test('the classic CodeMirror preview renders a held marker when the cursor leaves or focus goes', () => {
  const cm = fakeCodeMirror('- a', 3);
  const { rendered, timers } = classicEditor(cm);
  cm.type('- a\n- ', 6);
  timers.flush();
  cm.caret = 1;
  cm.emit('cursorActivity');
  assert.deepEqual(rendered, ['- a\n- ']);

  cm.type('- a\n- \n- ', 9);
  timers.flush();
  assert.deepEqual(rendered, ['- a\n- ']);
  cm.focused = false;
  cm.emit('blur');
  assert.deepEqual(rendered, ['- a\n- ', '- a\n- \n- ']);
});

test('the classic CodeMirror preview does not hold without focus', () => {
  const cm = fakeCodeMirror('- a', 3);
  cm.focused = false;
  const { rendered, timers } = classicEditor(cm);
  cm.type('- a\n- ', 6);
  timers.flush();
  assert.deepEqual(rendered, ['- a\n- ']);
});

test('the classic bare textarea preview holds, then renders when the caret moves off the line', () => {
  const { window, document, rendered, timers } = classicEditor(null);
  const textarea = document.getElementById('content');
  textarea.focus();
  textarea.value = '- a\n- ';
  textarea.setSelectionRange(6, 6);
  textarea.dispatchEvent(new window.Event('input'));
  timers.flush();
  assert.deepEqual(rendered, []);
  textarea.setSelectionRange(1, 1);
  textarea.dispatchEvent(new window.Event('keyup'));
  assert.deepEqual(rendered, ['- a\n- ']);
});
