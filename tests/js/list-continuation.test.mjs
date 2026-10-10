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
const { edit, isPlainEnter } = sandbox.window.wpCarveListContinuation;

// `|` marks the caret. Returns the document after Enter, caret marked, or null
// when the editor should insert its own newline.
function enter(marked) {
  const at = marked.indexOf('|');
  const text = marked.slice(0, at) + marked.slice(at + 1);
  const next = edit(text, at);
  if (!next) return null;
  const result = text.slice(0, next.from) + next.text + text.slice(next.to);
  return result.slice(0, next.cursor) + '|' + result.slice(next.cursor);
}

const CONTINUES = [
  ['dash bullet', '- one|', '- one\n- |'],
  ['star bullet', '* one|', '* one\n* |'],
  ['decimal dot', '1. one|', '1. one\n2. |'],
  ['decimal paren', '1) one|', '1) one\n2) |'],
  ['decimal past nine', '9. nine|', '9. nine\n10. |'],
  ['zero-padded decimal', '09. nine|', '09. nine\n10. |'],
  ['lower alpha', 'a. one|', 'a. one\nb. |'],
  ['upper alpha paren', 'A) one|', 'A) one\nB) |'],
  ['lone i is roman', 'i. one|', 'i. one\nii. |'],
  ['upper roman', 'III. three|', 'III. three\nIV. |'],
  ['roman after iv', 'iv. four|', 'iv. four\nv. |'],
  ['v in a roman list stays roman', 'iv. four\nv. five|', 'iv. four\nv. five\nvi. |'],
  ['lone v is alpha', 'v. one|', 'v. one\nw. |'],
  ['c then d is alpha', 'c. one\nd. two|', 'c. one\nd. two\ne. |'],
  ['i then ii is roman for x', 'i. a\nii. b\niii. c\niv. d\nv. e\nvi. f\nvii. g\nviii. h\nix. i|',
    'i. a\nii. b\niii. c\niv. d\nv. e\nvi. f\nvii. g\nviii. h\nix. i\nx. |'],
  ['bare dot', '. one|', '. one\n. |'],
  ['open task', '- [ ] one|', '- [ ] one\n- [ ] |'],
  ['checked task resets', '- [x] one|', '- [x] one\n- [ ] |'],
  ['upper checked task resets', '- [X] one|', '- [X] one\n- [ ] |'],
  ['dropped task resets', '- [-] one|', '- [-] one\n- [ ] |'],
  ['star task keeps its bullet', '* [x] one|', '* [x] one\n* [ ] |'],
  ['indent is kept', '  - nested|', '  - nested\n  - |'],
  ['separator width is kept', '-   wide|', '-   wide\n-   |'],
  ['ordered separator width is kept', '1.  wide|', '1.  wide\n2.  |'],
  ['li attributes stay on their item', '-{.c} one|', '-{.c} one\n- |'],
  ['ordered li attributes', '3.{#x} three|', '3.{#x} three\n4. |'],
  ['mid-item splits the rest into the next item', '- one| two', '- one\n- | two'],
  ['nested ordered under a bullet', '- top\n  1. one|', '- top\n  1. one\n  2. |'],
  ['task box on an ordered item is content', '1. [ ] one|', '1. [ ] one\n2. |'],
  ['i then j is alpha from the first item', 'i. one|\nj. two', 'i. one\nj. |\nj. two'],
  ['c then ci is roman from the first item', 'c. one|\nci. two', 'c. one\nci. |\nci. two'],
  ['a case change starts a new roman list', 'a. one\nb. two\nI. three|', 'a. one\nb. two\nI. three\nII. |'],
  ['an item after a loose continuation paragraph', '- one\n\n  more\n- two|', '- one\n\n  more\n- two\n- |'],
  ['an item under a heading', '# Title\n- one|', '# Title\n- one\n- |'],
  ['an item after a lazy line in the list', '- one\nlazy\n- two|', '- one\nlazy\n- two\n- |'],
  ['a fence left open in an earlier item ends with it', '- top\n  ```\n  code\n\n- next|', '- top\n  ```\n  code\n\n- next\n- |'],
];

for (const [name, before, after] of CONTINUES) {
  test(`Enter continues: ${name}`, () => {
    assert.equal(enter(before), after);
  });
}

const ENDS = [
  ['empty bullet', '- one\n- |', '- one\n|'],
  ['empty ordered', '1. one\n2. |', '1. one\n|'],
  ['empty bare dot', '. one\n. |', '. one\n|'],
  ['empty task', '- [ ] one\n- [ ] |', '- [ ] one\n|'],
  ['empty task without its trailing space', '- [ ] one\n- [ ]|', '- [ ] one\n|'],
  ['empty bullet with trailing spaces', '- one\n-   |', '- one\n|'],
  ['empty nested bullet', '- one\n  - |', '- one\n|'],
];

for (const [name, before, after] of ENDS) {
  test(`Enter on a marker-only line ends the list: ${name}`, () => {
    assert.equal(enter(before), after);
  });
}

const LEAVES = [
  ['plus is the continuation marker', '+ one|'],
  ['lone plus', '+|'],
  ['parenthesized number', '(1) one|'],
  ['bare paren', ') one|'],
  ['bare dash without a space', '-|'],
  ['dash glued to text', '-one|'],
  ['tab separator', '-\tone|'],
  ['prose', 'just text|'],
  ['thematic break', '---|'],
  ['multi-letter non-roman', 'ab. one|'],
  ['mixed-case roman', 'Iv. one|'],
  ['alpha past z', 'z. one|'],
  ['caret inside the marker', '-| one'],
  ['caret at line start', '|- one'],
  ['quoted list item', '> - one|'],
  ['inside a backtick fence', '```\n- one|\n```'],
  ['inside a tilde fence', '~~~\n1. one|\n~~~'],
  ['inside a fence opened on an item line', '- ```\n  - code|\n  ```'],
  ['a marker line under paragraph text', 'text\n- prose|'],
  ['a nested marker line under item paragraph text', '- one\n\n  more\n  - prose|'],
  ['a second marker line under paragraph text', 'text\n- prose\n- prose|'],
  ['inside a comment fence', '%%%\n\n- hidden|\n%%%'],
  ['an ordered marker line under paragraph text', 'text\n1. prose|'],
];

for (const [name, before] of LEAVES) {
  test(`Enter keeps its default: ${name}`, () => {
    assert.equal(enter(before), null);
  });
}

test('a closed fence above does not block continuation', () => {
  assert.equal(enter('```\ncode\n```\n- one|'), '```\ncode\n```\n- one\n- |');
});

test('only a plain Enter outside an IME composition continues', () => {
  const base = { key: 'Enter', shiftKey: false, altKey: false, ctrlKey: false, metaKey: false, isComposing: false, keyCode: 13 };
  assert.equal(isPlainEnter(base), true);
  assert.equal(isPlainEnter({ ...base, shiftKey: true }), false);
  assert.equal(isPlainEnter({ ...base, ctrlKey: true }), false);
  assert.equal(isPlainEnter({ ...base, metaKey: true }), false);
  assert.equal(isPlainEnter({ ...base, altKey: true }), false);
  assert.equal(isPlainEnter({ ...base, isComposing: true }), false);
  assert.equal(isPlainEnter({ ...base, keyCode: 229 }), false);
  assert.equal(isPlainEnter({ ...base, key: 'a' }), false);
});

function nodes(tree, type) {
  if (Array.isArray(tree)) return tree.flatMap(node => nodes(node, type));
  if (!tree || typeof tree !== 'object') return [];
  return [...(tree.type === type ? [tree] : []), ...nodes(tree.children || [], type)];
}

// The block's source textarea, driven through its real onKeyDown.
function blockEditor(source, caret) {
  let blockEdit;
  const hooks = [];
  let hookIndex = 0;
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
    useEffect() {},
  };
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
  };
  const window = { wp, wpCarve: {}, requestAnimationFrame: callback => callback() };
  runInNewContext(toggleSource, { window });
  runInNewContext(listSource, { window });
  runInNewContext(blockSource, { window, document: {}, setTimeout, clearTimeout });
  const textarea = { selectionStart: caret, selectionEnd: caret, focus() {}, style: {} };

  return {
    attributes,
    textarea,
    press(modifiers = {}) {
      hookIndex = 0;
      const tree = blockEdit({ attributes, setAttributes: next => Object.assign(attributes, next), clientId: 'block-1' });
      const field = nodes(tree, 'textarea')[0];
      field.props.ref.current = textarea;
      let prevented = false;
      field.props.onKeyDown({
        key: 'Enter',
        code: 'Enter',
        ctrlKey: false,
        metaKey: false,
        altKey: false,
        shiftKey: !!modifiers.shift,
        nativeEvent: { key: 'Enter', isComposing: !!modifiers.composing, keyCode: 13, shiftKey: !!modifiers.shift },
        preventDefault() { prevented = true; },
        stopPropagation() {},
      });
      return prevented;
    },
  };
}

test('Enter in the block source continues the list and moves the caret', () => {
  const editor = blockEditor('- one', 5);
  assert.equal(editor.press(), true);
  assert.equal(editor.attributes.carve, '- one\n- ');
  assert.equal(editor.textarea.selectionStart, 8);
});

test('Shift+Enter in the block source keeps the default newline', () => {
  const editor = blockEditor('- one', 5);
  assert.equal(editor.press({ shift: true }), false);
  assert.equal(editor.attributes.carve, '- one');
});

test('Enter during an IME composition in the block source is left alone', () => {
  const editor = blockEditor('- one', 5);
  assert.equal(editor.press({ composing: true }), false);
  assert.equal(editor.attributes.carve, '- one');
});

test('Enter on a plus line in the block source is left alone', () => {
  const editor = blockEditor('+ one', 5);
  assert.equal(editor.press(), false);
  assert.equal(editor.attributes.carve, '+ one');
});

// Enough of CodeMirror 5 for the classic editor's init path, with positions
// kept as plain offsets.
function fakeCodeMirror(value, caret) {
  const maps = [];
  const cm = {
    value,
    caret,
    commands: [],
    on() {},
    refresh() {},
    getScrollInfo: () => ({ top: 0, height: 0, clientHeight: 0 }),
    addKeyMap(map) { maps.push(map); },
    getValue() { return this.value; },
    somethingSelected: () => false,
    getCursor() { return this.caret; },
    indexFromPos: pos => pos,
    posFromIndex: index => index,
    replaceRange(text, from, to) { this.value = this.value.slice(0, from) + text + this.value.slice(to); },
    setCursor(pos) { this.caret = pos; },
    execCommand(name) { this.commands.push(name); },
    pressEnter() { return maps.map(map => map.Enter).find(Boolean)(this); },
  };
  return cm;
}

function classicEditor(cm) {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<textarea id="content"></textarea>';
  const Pass = { pass: true };
  window.wp = { CodeMirror: { Pass }, codeEditor: { initialize: () => ({ codemirror: cm }) } };
  window.wpCarve = { codeEditor: cm ? {} : null };
  runInNewContext(toggleSource, { window });
  runInNewContext(listSource, { window });
  runInNewContext(documentSource, { window, document, Event: window.Event, setTimeout, clearTimeout });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  return { window, document, Pass };
}

test('Enter in the classic CodeMirror editor continues an ordered list', () => {
  const cm = fakeCodeMirror('a. one', 6);
  classicEditor(cm);
  assert.equal(cm.pressEnter(), undefined);
  assert.equal(cm.value, 'a. one\nb. ');
  assert.equal(cm.caret, 10);
});

test('Enter in the classic CodeMirror editor passes on prose', () => {
  const cm = fakeCodeMirror('text', 4);
  const { Pass } = classicEditor(cm);
  assert.equal(cm.pressEnter(), Pass);
  assert.equal(cm.value, 'text');
});

test('Enter in the classic bare textarea ends the list on an empty item', () => {
  const { window, document } = classicEditor(null);
  const textarea = document.getElementById('content');
  textarea.value = '- one\n- ';
  textarea.setSelectionRange(8, 8);
  const event = new window.KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
  textarea.dispatchEvent(event);
  assert.equal(event.defaultPrevented, true);
  assert.equal(textarea.value, '- one\n');
  assert.equal(textarea.selectionStart, 6);
});
