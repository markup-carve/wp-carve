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
const { edit, indent, isPlainEnter } = sandbox.window.wpCarveListContinuation;
const { parse } = await import('@markup-carve/carve');

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
  ['roman across a lazy line', 'iv. four\nlazy\nv. five|', 'iv. four\nlazy\nv. five\nvi. |'],
  ['an item under a thematic break', '---\n- one|', '---\n- one\n- |'],
  ['a heading between paragraph text and the item', 'text\n# Title\n- one|', 'text\n# Title\n- one\n- |'],
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

// `|` marks the caret, or both ends of a selection. Returns the document
// after Tab (Shift+Tab with `outdent`), selection marked, or null when the
// editor should keep its plain two-space indent.
function tab(marked, outdent = false) {
  const first = marked.indexOf('|');
  let text = marked.slice(0, first) + marked.slice(first + 1);
  const second = text.indexOf('|');
  const end = second < 0 ? first : second;
  if (second >= 0) text = text.slice(0, second) + text.slice(second + 1);
  const next = indent(text, first, end, outdent);
  if (!next) return null;
  const result = text.slice(0, next.from) + next.text + text.slice(next.to);
  if (next.start === next.end) return result.slice(0, next.start) + '|' + result.slice(next.start);
  return result.slice(0, next.start) + '|' + result.slice(next.start, next.end) + '|' + result.slice(next.end);
}

// Where the engine puts the item whose text is `word`: its list depth, and its
// ordinal when the list is ordered. A bare marker gets `new` typed after it.
function placement(source, word) {
  const typed = source.replace(/\|/g, '').replace(/^( *(?:[-*]|[0-9a-zA-Z]*[.)]) (?:\[ \] )?)$/gm, '$1new');
  let found = null;
  (function walk(blocks, depth) {
    for (const block of blocks) {
      if (block.type !== 'list') {
        if (block.children) walk(block.children, depth);
        continue;
      }
      block.items.forEach((item, at) => {
        const paragraph = item.children[0];
        const text = paragraph && paragraph.type === 'paragraph' ? paragraph.children.map(node => node.value || '').join('') : '';
        if (text === word || text.endsWith(' ' + word)) {
          found = { depth, ordinal: block.ordered ? (block.start || 1) + at : null };
        }
        walk(item.children, depth + 1);
      });
    }
  })(parse(typed).children, 1);
  return found;
}

// [name, before, outdent, after, word, depth, ordinal]
const MOVES = [
  ['bullet nests under the previous bullet', '- a\n- b|', false, '- a\n  - b|', 'b', 2, null],
  ['bare bullet right after Enter', '- a\n- |', false, '- a\n  - |', 'new', 2, null],
  ['star bullet keeps its character', '* a\n* b|', false, '* a\n  * b|', 'b', 2, null],
  ['bullet under a numbered item reaches its content column', '1. a\n- b|', false, '1. a\n   - b|', 'b', 2, null],
  ['bare numbered item after Enter restarts at 1', '1. a\n2. |', false, '1. a\n   1. |', 'new', 2, 1],
  ['numbered item becomes the first child', '1. a\n2. b|', false, '1. a\n   1. b|', 'b', 2, 1],
  ['a two-digit parent needs four columns', '10. a\n11. b|', false, '10. a\n    1. b|', 'b', 2, 1],
  ['joining a child list takes the next ordinal', '1. a\n   1. x\n2. y|', false, '1. a\n   1. x\n   2. y|', 'y', 2, 2],
  ['paren delimiter is kept', '1) a\n2) b|', false, '1) a\n   1) b|', 'b', 2, 1],
  ['alpha restarts at a', 'a. x\nb. y|', false, 'a. x\n   a. y|', 'y', 2, 1],
  ['upper alpha restarts at A', 'A. x\nB. y|', false, 'A. x\n   A. y|', 'y', 2, 1],
  ['roman restarts at i', 'i. x\nii. y|', false, 'i. x\n   i. y|', 'y', 2, 1],
  ['upper roman restarts at I', 'I. x\nII. y|', false, 'I. x\n   I. y|', 'y', 2, 1],
  ['bare dot stays a bare dot', '. a\n. b|', false, '. a\n  . b|', 'b', 2, 1],
  ['task box comes along', '- [ ] a\n- [x] b|', false, '- [ ] a\n  - [x] b|', 'b', 2, null],
  ['continuation lines and children move with the item', '- a\n- b|\n  more\n  - c', false, '- a\n  - b|\n    more\n    - c', 'c', 3, null],
  ['the caret mid-item moves with the text', '- a\n- b|c', false, '- a\n  - b|c', 'bc', 2, null],
  ['an item after its sibling\'s child list joins it', '- a\n  - x\n- b|', false, '- a\n  - x\n  - b|', 'b', 2, null],
  ['an item after a loose paragraph of its sibling', '- a\n\n  more\n\n- b|', false, '- a\n\n  more\n\n  - b|', 'b', 2, null],
  ['a paragraph after the list stays out of the item', '1. a\n2. b|\n\n  outside', false, '1. a\n   1. b|\n\n  outside', 'b', 2, 1],
  ['a loose child block after a blank line moves with the item', '- a\n- b|\n\n  more', false, '- a\n  - b|\n\n    more', 'b', 2, null],
  ['outdent a bullet to the parent marker column', '1. a\n   - b|', true, '1. a\n- b|', 'b', 1, null],
  ['outdent a numbered child takes the next parent ordinal', '1. a\n   1. b|', true, '1. a\n2. b|', 'b', 1, 2],
  ['outdent past nine widens the marker and keeps the children', '9. a\n   1. b|\n      - c', true, '9. a\n10. b|\n    - c', 'c', 2, null],
  ['outdent a third level', '- a\n  - b\n    - c|', true, '- a\n  - b\n  - c|', 'c', 2, null],
  ['outdent a bare marker', '- a\n  - |', true, '- a\n- |', 'new', 1, null],
  ['outdent into an alpha list', 'a. x\n   1. y|', true, 'a. x\nb. y|', 'y', 1, 2],
  ['selection nests each item once', '1. a\n2. |b\n3. c|', false, '1. a\n   1. |b\n   2. c|', 'c', 2, 2],
  ['selection skips an item that cannot move', '- |a\n- b|', false, '- |a\n  - b|', 'b', 2, null],
  ['selection moves a child once with its parent', '- a\n- |b\n  - c|', false, '- a\n  - |b\n    - c|', 'c', 3, null],
  ['selection outdents siblings in order', '1. a\n   1. |b\n   2. c|', true, '1. a\n2. |b\n3. c|', 'c', 1, 3],
];

for (const [name, before, outdent, after, word, depth, ordinal] of MOVES) {
  test(`${outdent ? 'Shift+Tab' : 'Tab'} moves the item: ${name}`, () => {
    assert.equal(tab(before, outdent), after);
    assert.deepEqual(placement(after, word), { depth, ordinal });
  });
}

const KEEPS_TAB = [
  ['first item of a list', '- a|', false],
  ['first child of an item', '- a\n  - b|', false],
  ['top-level outdent', '- a|', true],
  ['prose', 'text|', false],
  ['prose outdent', '  text|', true],
  ['plus is not a bullet', '- a\n+ b|', false],
  ['parenthesized number', '1. a\n(2) b|', false],
  ['inside a fence', '```\n- a\n- b|\n```', false],
  ['a marker line under paragraph text', 'text\n- a\n- b|', false],
  ['dash without a space', '- a\n-b|', false],
];

for (const [name, before, outdent] of KEEPS_TAB) {
  test(`${outdent ? 'Shift+Tab' : 'Tab'} keeps the plain indent: ${name}`, () => {
    assert.equal(tab(before, outdent), null);
  });
}

test('the engine keeps a paragraph after the list outside it once an item nests', () => {
  const after = tab('1. a\n2. b|\n\n  outside').replace('|', '');
  assert.deepEqual(parse(after).children.map(block => block.type), ['list', 'paragraph']);
});

test('a selection mixing prose and items shifts the prose by two spaces', () => {
  // `text` is a lazy line of `b` and stays; the blank line gets no spaces.
  assert.equal(tab('- a\n- |b\ntext\n\nmore|', false), '- a\n  - |b\ntext\n\n  more|');
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
      const key = modifiers.key || 'Enter';
      field.props.onKeyDown({
        key,
        code: key,
        ctrlKey: false,
        metaKey: false,
        altKey: false,
        shiftKey: !!modifiers.shift,
        nativeEvent: { key, isComposing: !!modifiers.composing, keyCode: 13, shiftKey: !!modifiers.shift },
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

test('Tab in the block source nests a numbered item at the content column', () => {
  const editor = blockEditor('1. a\n2. ', 8);
  assert.equal(editor.press({ key: 'Tab' }), true);
  assert.equal(editor.attributes.carve, '1. a\n   1. ');
  assert.equal(editor.textarea.selectionStart, 11);
});

test('Shift+Tab in the block source moves a child back out', () => {
  const editor = blockEditor('1. a\n   1. b', 13);
  assert.equal(editor.press({ key: 'Tab', shift: true }), true);
  assert.equal(editor.attributes.carve, '1. a\n2. b');
  assert.equal(editor.textarea.selectionStart, 10);
});

test('Tab in the block source keeps the two-space indent on prose', () => {
  const editor = blockEditor('text', 4);
  assert.equal(editor.press({ key: 'Tab' }), true);
  assert.equal(editor.attributes.carve, '  text');
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
    listSelections() { return [{}]; },
    setSelection(from, to) { this.caret = from; this.selectionEnd = to; },
    indexFromPos: pos => pos,
    posFromIndex: index => index,
    replaceRange(text, from, to) { this.value = this.value.slice(0, from) + text + this.value.slice(to); },
    setCursor(pos) { this.caret = pos; },
    execCommand(name) { this.commands.push(name); },
    pressEnter() { return this.press('Enter'); },
    press(key) { return maps.map(map => map[key]).find(Boolean)(this); },
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

test('Tab and Shift+Tab in the classic CodeMirror editor nest and un-nest an item', () => {
  const cm = fakeCodeMirror('- a\n- b', 7);
  classicEditor(cm);
  assert.equal(cm.press('Tab'), undefined);
  assert.equal(cm.value, '- a\n  - b');
  assert.equal(cm.caret, 9);
  assert.equal(cm.press('Shift-Tab'), undefined);
  assert.equal(cm.value, '- a\n- b');
  assert.equal(cm.caret, 7);
});

test('Tab in the classic CodeMirror editor passes on prose and on a first item', () => {
  for (const value of ['text', '- a']) {
    const cm = fakeCodeMirror(value, value.length);
    const { Pass } = classicEditor(cm);
    assert.equal(cm.press('Tab'), Pass);
    assert.equal(cm.press('Shift-Tab'), Pass);
    assert.equal(cm.value, value);
  }
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
