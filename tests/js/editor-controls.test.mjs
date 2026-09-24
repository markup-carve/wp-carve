import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { Window } from 'happy-dom';

const blockSource = readFileSync(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');
const documentSource = readFileSync(new URL('../../assets/js/code-editor.js', import.meta.url), 'utf8');
const commentSource = readFileSync(new URL('../../assets/js/comment-toolbar.js', import.meta.url), 'utf8');

function nodes(tree, type) {
  if (Array.isArray(tree)) return tree.flatMap(node => nodes(node, type));
  if (!tree || typeof tree !== 'object') return [];
  return [...(tree.type === type ? [tree] : []), ...nodes(tree.children || [], type)];
}

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
  runInNewContext(blockSource, { window, document: {}, setTimeout, clearTimeout });
  const textarea = { selectionStart: start, selectionEnd: end, focus() {}, style: {} };
  return {
    attributes,
    render() {
      hookIndex = 0;
      const tree = edit({ attributes, setAttributes: next => Object.assign(attributes, next), clientId: 'block-1' });
      nodes(tree, 'textarea')[0].props.ref.current = textarea;
      return tree;
    },
    textarea,
  };
}

test('the block Link button keeps selected text while the URL is entered', () => {
  const editor = blockEditor('Read this guide today.', 5, 15);
  let tree = editor.render();
  nodes(tree, 'ToolbarButton').find(node => node.props.title === 'Link').props.onClick();
  assert.equal(editor.attributes.carve, 'Read this guide today.');

  tree = editor.render();
  assert.equal(nodes(tree, 'TextControl').find(node => node.props.label === 'Link text').props.value, 'this guide');
  nodes(tree, 'TextControl').find(node => node.props.label === 'URL').props.onChange('https://example.com/guide');
  tree = editor.render();
  nodes(tree, 'form')[0].props.onSubmit({ preventDefault() {} });
  assert.equal(editor.attributes.carve, 'Read [this guide](https://example.com/guide) today.');
});

test('the block Image button uses selected text as alt text', () => {
  const editor = blockEditor('A diagram follows.', 2, 9);
  let tree = editor.render();
  nodes(tree, 'ToolbarButton').find(node => node.props.title === 'Image').props.onClick();
  tree = editor.render();
  assert.equal(nodes(tree, 'TextControl').find(node => node.props.label === 'Alt text').props.value, 'diagram');
  nodes(tree, 'TextControl').find(node => node.props.label === 'URL').props.onChange('diagram.svg');
  tree = editor.render();
  nodes(tree, 'form')[0].props.onSubmit({ preventDefault() {} });
  assert.equal(editor.attributes.carve, 'A ![diagram](diagram.svg) follows.');
});

test('source toolbar footnotes keep the anchor and code fences carry selected content', () => {
  const note = blockEditor('A useful note.', 2, 8);
  nodes(note.render(), 'ToolbarButton').find(node => node.props.title === 'Footnote').props.onClick();
  assert.equal(note.attributes.carve, 'A useful^[note] note.');

  const code = blockEditor('Before\necho 1;\nAfter', 7, 14);
  nodes(code.render(), 'ToolbarButton').find(node => node.props.title === 'Code block').props.onClick();
  assert.equal(code.attributes.carve, 'Before\n\n```\necho 1;\n```\nAfter');
});

test('a selected fence makes the outer code fence wide enough', () => {
  const editor = blockEditor('before\n```\na\n```\nafter', 7, 16);
  nodes(editor.render(), 'ToolbarButton').find(node => node.props.title === 'Code block').props.onClick();
  assert.match(editor.attributes.carve, /````\n```\na\n```\n````/);
});

test('the block media control uses a selected URL', () => {
  const editor = blockEditor('Watch https://example.com/video today.', 6, 31);
  let tree = editor.render();
  const media = nodes(tree, 'ToolbarDropdownMenu').find(node => node.props.label === 'Media embed');
  media.props.controls.find(control => control.title === 'Auto (URL)').onClick();
  tree = editor.render();
  assert.equal(nodes(tree, 'TextControl').find(node => node.props.label === 'URL').props.value, 'https://example.com/video');
  nodes(tree, 'form')[0].props.onSubmit({ preventDefault() {} });
  assert.equal(editor.attributes.carve, 'Watch :media[https://example.com/video] today.');
});

function documentEditor(source, start, end, replies) {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<div class="wpcarve-document-toolbar">'
    + '<button data-wpcarve-open="" data-wpcarve-action="link">Link</button>'
    + '<button data-wpcarve-open="" data-wpcarve-action="image">Image</button>'
    + '<button data-wpcarve-open="" data-wpcarve-action="block" data-wpcarve-insert="```&#10;&#10;```">Code</button></div>'
    + '<select class="wpcarve-more-insert"><option value="">More</option><option value="footnote">Footnote</option><option value="media">Media</option></select>'
    + '<textarea id="content"></textarea>';
  const textarea = document.getElementById('content');
  textarea.value = source;
  textarea.setSelectionRange(start, end);
  window.wp = {};
  window.wpCarve = { codeEditor: null, linkUrlLabel: 'Link URL', linkTextLabel: 'Link text', imageUrlLabel: 'Image URL', imageAltLabel: 'Alt text' };
  window.prompt = () => replies.shift();
  runInNewContext(documentSource, { window, document, Event: window.Event, setTimeout, clearTimeout });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  return { document, textarea };
}

test('the document Link button preserves selected text and accepts a URL', () => {
  const { document, textarea } = documentEditor('Read the guide.', 5, 14, ['https://example.com']);
  document.querySelector('[data-wpcarve-action="link"]').click();
  assert.equal(textarea.value, 'Read [the guide](https://example.com).');
});

test('cancelling the document Link prompt leaves source intact', () => {
  const { document, textarea } = documentEditor('Read the guide.', 5, 14, [null]);
  document.querySelector('[data-wpcarve-action="link"]').click();
  assert.equal(textarea.value, 'Read the guide.');
});

test('the document editor keeps selected code inside a fence', () => {
  const { document, textarea } = documentEditor('Before\necho 1;\nAfter', 7, 14, []);
  document.querySelector('[data-wpcarve-action="block"]').click();
  assert.equal(textarea.value, 'Before\n```\necho 1;\n```\nAfter');
});

test('the document More menu adds a footnote after selected text', () => {
  const { document, textarea } = documentEditor('A useful note.', 2, 8, []);
  const menu = document.querySelector('.wpcarve-more-insert');
  menu.value = 'footnote';
  menu.dispatchEvent(new document.defaultView.Event('change'));
  assert.equal(textarea.value, 'A useful^[note] note.');
});

test('link targets preserve existing percent escapes', () => {
  const editor = blockEditor('guide', 0, 5);
  let tree = editor.render();
  nodes(tree, 'ToolbarButton').find(node => node.props.title === 'Link').props.onClick();
  tree = editor.render();
  nodes(tree, 'TextControl').find(node => node.props.label === 'URL').props.onChange('https://example.com/a%20b(c)');
  nodes(editor.render(), 'form')[0].props.onSubmit({ preventDefault() {} });
  assert.equal(editor.attributes.carve, '[guide](https://example.com/a%20b%28c%29)');
});

test('source inline math widens its delimiter around selected backticks', () => {
  const editor = blockEditor('a`b', 0, 3);
  const math = nodes(editor.render(), 'ToolbarDropdownMenu').find(node => node.props.label === 'Math');
  math.props.controls.find(control => control.title === 'Inline math').onClick();
  assert.equal(editor.attributes.carve, '$``a`b``');
});

test('clear formatting keeps literal equals signs', () => {
  const source = 'x = 5 = y and =marked=';
  const editor = blockEditor(source, 0, source.length);
  nodes(editor.render(), 'ToolbarButton').find(node => node.props.title === 'Clear formatting').props.onClick();
  assert.equal(editor.attributes.carve, 'x = 5 = y and marked');
});

test('the document More menu asks for a media URL', () => {
  const { document, textarea } = documentEditor('Watch here.', 6, 10, ['https://example.com/video']);
  const menu = document.querySelector('.wpcarve-more-insert');
  menu.value = 'media';
  menu.dispatchEvent(new document.defaultView.Event('change'));
  assert.equal(textarea.value, 'Watch :media[https://example.com/video].');
});

test('the comment Link button asks for a URL and keeps selected text', () => {
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<textarea id="comment">Read the guide.</textarea>';
  window.wpCarveComment = {};
  window.prompt = () => 'https://example.com';
  runInNewContext(commentSource, { window, document, Event: window.Event, fetch: () => {} });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  const textarea = document.getElementById('comment');
  textarea.setSelectionRange(5, 14);
  [...document.querySelectorAll('.wpcarve-comment-toolbar button')].find(button => button.textContent === 'Link').click();
  assert.equal(textarea.value, 'Read [the guide](https://example.com).');
});
