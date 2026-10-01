import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { Window } from 'happy-dom';

const blockSource = readFileSync(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');
const documentSource = readFileSync(new URL('../../assets/js/code-editor.js', import.meta.url), 'utf8');
const commentSource = readFileSync(new URL('../../assets/js/comment-toolbar.js', import.meta.url), 'utf8');
const toggleSource = readFileSync(new URL('../../assets/js/inline-toggle.js', import.meta.url), 'utf8');

function nodes(tree, type) {
  if (Array.isArray(tree)) return tree.flatMap(node => nodes(node, type));
  if (!tree || typeof tree !== 'object') return [];
  return [...(tree.type === type ? [tree] : []), ...nodes(tree.children || [], type)];
}

// The block's source textarea, driven through the real toolbar and keymap so
// the assertions cover the plumbing as well as the arithmetic.
function blockEditor(source, start, end) {
  let edit;
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
    blocks: { registerBlockType: (name, config) => { if (name === 'carve/markup') edit = config.edit; } },
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
  runInNewContext(blockSource, { window, document: {}, setTimeout, clearTimeout });
  const textarea = { selectionStart: start, selectionEnd: end, focus() {}, style: {} };

  function render() {
    hookIndex = 0;
    const tree = edit({ attributes, setAttributes: next => Object.assign(attributes, next), clientId: 'block-1' });
    nodes(tree, 'textarea')[0].props.ref.current = textarea;
    return tree;
  }

  return {
    attributes,
    textarea,
    render,
    click(title) {
      nodes(render(), 'ToolbarButton').find(node => node.props.title === title).props.onClick();
      return this;
    },
    press(key, modifiers = {}) {
      const field = nodes(render(), 'textarea')[0];
      field.props.onKeyDown({
        key,
        code: 'Key' + key.toUpperCase(),
        ctrlKey: true,
        metaKey: false,
        shiftKey: !!modifiers.shift,
        preventDefault() {},
        stopPropagation() {},
      });
      return this;
    },
    // The source string and the selection the user is left with, which is half
    // of what the toggle has to get right.
    state() {
      return {
        source: this.attributes.carve,
        start: this.textarea.selectionStart,
        end: this.textarea.selectionEnd,
      };
    },
  };
}

// Each construct, both directions. One assertion per property so a regression
// names which direction broke rather than failing a bundle of them.
const CONSTRUCTS = [
  { name: 'strong', marked: '*text*', click: 'Strong (bold)', doubles: true },
  { name: 'emphasis', marked: '/text/', click: 'Emphasis (italic)', doubles: true },
  { name: 'underline', marked: '_text_', click: 'Underline', doubles: true },
  { name: 'code', marked: '`text`', click: 'Inline code' },
  { name: 'strike', marked: '~text~', press: ['x', { shift: true }], doubles: true },
  { name: 'highlight', marked: '=text=', press: ['h', { shift: true }], doubles: true },
  { name: 'superscript', marked: '{^text^}', press: ['.', {}] },
  { name: 'subscript', marked: '{,text,}', press: [',', {}] },
];

function act(editor, construct) {
  return construct.click ? editor.click(construct.click) : editor.press(...construct.press);
}

for (const construct of CONSTRUCTS) {
  const open = construct.marked.indexOf('text');
  const close = construct.marked.length - open - 4;

  test(`a first click marks a selection as ${construct.name}`, () => {
    const editor = blockEditor('text', 0, 4);
    assert.equal(act(editor, construct).state().source, construct.marked);
  });

  test(`a first click on ${construct.name} keeps the selection on the text`, () => {
    const editor = blockEditor('a text b', 2, 6);
    const { start, end } = act(editor, construct).state();
    assert.deepEqual({ start, end }, { start: 2 + open, end: 6 + open });
  });

  test(`a second click removes ${construct.name} from around the selection`, () => {
    const editor = blockEditor('a ' + construct.marked + ' b', 2 + open, 6 + open);
    assert.equal(act(editor, construct).state().source, 'a text b');
  });

  test(`removing ${construct.name} from outside the selection keeps the selection`, () => {
    const editor = blockEditor('a ' + construct.marked + ' b', 2 + open, 6 + open);
    const { start, end } = act(editor, construct).state();
    assert.deepEqual({ start, end }, { start: 2, end: 6 });
  });

  test(`removing ${construct.name} works when the markers are inside the selection`, () => {
    const editor = blockEditor('a ' + construct.marked + ' b', 2, 2 + construct.marked.length);
    assert.equal(act(editor, construct).state().source, 'a text b');
  });

  test(`removing ${construct.name} from inside the selection leaves the text selected`, () => {
    const editor = blockEditor('a ' + construct.marked + ' b', 2, 2 + construct.marked.length);
    const { start, end } = act(editor, construct).state();
    assert.deepEqual({ start, end }, { start: 2, end: 6 });
  });

  test(`the caret alone inserts a wrapped ${construct.name} placeholder`, () => {
    const editor = blockEditor('ab', 1, 1);
    const { source, start, end } = act(editor, construct).state();
    assert.equal(source.slice(0, 1) + source.slice(source.length - 1), 'ab');
    assert.equal(source.slice(start, end).length > 0, true);
    assert.equal(source.slice(1, start), construct.marked.slice(0, open));
  });

  test(`the caret inside ${construct.name} removes the mark`, () => {
    const caret = 2 + open + 2;
    const editor = blockEditor('a ' + construct.marked + ' b', caret, caret);
    const { source, start, end } = act(editor, construct).state();
    assert.equal(source, 'a text b');
    assert.deepEqual({ start, end }, { start: 4, end: 4 });
  });

  // Only the single-character delimiters double into literal text. A doubled
  // backtick is a wider code fence, and a braced pair has no run to double.
  test(`a doubled ${construct.name} delimiter is literal text and is not unwrapped`, { skip: !construct.doubles }, () => {
    const doubled = construct.marked.slice(0, open) + construct.marked + construct.marked.slice(construct.marked.length - close);
    const editor = blockEditor(doubled, open * 2, open * 2 + 4);
    const { source } = act(editor, construct).state();
    assert.notEqual(source, construct.marked, 'stripping one pair would invent a mark');
    assert.equal(source.includes(construct.marked), true, 'the literal run is still there');
  });
}

test('marking code that holds a backtick widens the fence', () => {
  const editor = blockEditor('a`b', 0, 3);
  assert.equal(editor.click('Inline code').state().source, '``a`b``');
});

test('a second click removes a widened code fence', () => {
  const editor = blockEditor('``a`b``', 2, 5);
  assert.equal(editor.click('Inline code').state().source, 'a`b');
});

test('a second click on a widened code fence restores the selection', () => {
  const editor = blockEditor('``a`b``', 2, 5);
  const { start, end } = editor.click('Inline code').state();
  assert.deepEqual({ start, end }, { start: 0, end: 3 });
});

test('a code fence padded around its content drops the padding too', () => {
  const editor = blockEditor('`` `b ``', 0, 8);
  assert.equal(editor.click('Inline code').state().source, '`b');
});

test('the classic Strong button removes the mark on a second click', () => {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<div class="wpcarve-document-toolbar">'
    + '<button data-wpcarve-action="wrap" data-wpcarve-open="*" data-wpcarve-close="*" data-wpcarve-insert="">B</button></div>'
    + '<textarea id="content"></textarea>';
  const textarea = document.getElementById('content');
  textarea.value = 'a *text* b';
  textarea.setSelectionRange(3, 7);
  window.wp = {};
  window.wpCarve = { codeEditor: null };
  runInNewContext(toggleSource, { window });
  runInNewContext(documentSource, { window, document, Event: window.Event, setTimeout, clearTimeout });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  document.querySelector('[data-wpcarve-action="wrap"]').click();
  assert.equal(textarea.value, 'a text b');
  assert.deepEqual({ start: textarea.selectionStart, end: textarea.selectionEnd }, { start: 2, end: 6 });
});

test('the comment Strong button removes the mark on a second click', () => {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<textarea id="comment">a *text* b</textarea>';
  window.wpCarveComment = {};
  runInNewContext(toggleSource, { window });
  runInNewContext(commentSource, { window, document, Event: window.Event, fetch: () => {} });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  const textarea = document.getElementById('comment');
  textarea.setSelectionRange(3, 7);
  [...document.querySelectorAll('.wpcarve-comment-toolbar button')].find(button => button.textContent === 'B').click();
  assert.equal(textarea.value, 'a text b');
  assert.deepEqual({ start: textarea.selectionStart, end: textarea.selectionEnd }, { start: 2, end: 6 });
});
