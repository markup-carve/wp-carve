/* Where the caret and the selection end up after a toolbar click, which is the
   behaviour the author actually perceives. Both editors the classic source
   screen can present are covered: the bare textarea, and CodeMirror through a
   double that reproduces its index/line-ch conversion, so a regression to
   line-local arithmetic fails here instead of only in a browser. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { Window } from 'happy-dom';

const documentSource = readFileSync(new URL('../../assets/js/code-editor.js', import.meta.url), 'utf8');
const toggleSource = readFileSync(new URL('../../assets/js/inline-toggle.js', import.meta.url), 'utf8');

// The slice of CodeMirror 5 that code-editor.js calls. Lines are held split, so
// a position is a line and a column and the index conversions have to do real
// work, exactly as they do in the editor.
function codeMirrorDouble(value) {
  let lines = value.split('\n');
  let head = { line: 0, ch: 0 };
  let anchor = { line: 0, ch: 0 };
  const handlers = {};
  const before = (line) => lines.slice(0, line).reduce((total, text) => total + text.length + 1, 0);
  const cmp = (a, b) => (a.line - b.line) || (a.ch - b.ch);

  return {
    getValue: () => lines.join('\n'),
    setValue(next) {
      lines = next.split('\n');
      head = { line: 0, ch: 0 };
      anchor = { line: 0, ch: 0 };
    },
    indexFromPos: (pos) => before(pos.line) + pos.ch,
    posFromIndex(index) {
      let left = Math.max(0, Math.min(index, lines.join('\n').length));
      for (let line = 0; line < lines.length; line++) {
        if (left <= lines[line].length) return { line, ch: left };
        left -= lines[line].length + 1;
      }
      return { line: lines.length - 1, ch: lines[lines.length - 1].length };
    },
    getCursor(which) {
      if (which === 'from') return cmp(anchor, head) <= 0 ? anchor : head;
      if (which === 'to') return cmp(anchor, head) <= 0 ? head : anchor;
      return head;
    },
    setSelection(from, to) {
      anchor = { ...from };
      head = { ...(to || from) };
    },
    setCursor(pos) {
      anchor = { ...pos };
      head = { ...pos };
    },
    getSelection() {
      const whole = lines.join('\n');
      return whole.slice(this.indexFromPos(this.getCursor('from')), this.indexFromPos(this.getCursor('to')));
    },
    getLine: (line) => lines[line],
    lineCount: () => lines.length,
    replaceRange(text, from, to) {
      const whole = lines.join('\n');
      const start = this.indexFromPos(from);
      const end = to ? this.indexFromPos(to) : start;
      lines = (whole.slice(0, start) + text + whole.slice(end)).split('\n');
      const at = this.posFromIndex(start + text.length);
      anchor = at;
      head = at;
      (handlers.change || []).forEach(fn => fn());
    },
    operation: (fn) => fn(),
    on(event, fn) {
      handlers[event] = handlers[event] || [];
      handlers[event].push(fn);
    },
    focus() {},
    refresh() {},
    getScrollInfo: () => ({ top: 0, height: 0, clientHeight: 0 }),
    scrollTo() {},
  };
}

// Returns a driver for one editor flavour. `caret()` reports the selection as
// offsets into the source, the single coordinate system both paths now use.
function sourceEditor(source, start, end, options) {
  const settings = options || {};
  const window = new Window();
  const { document } = window;
  document.body.innerHTML = '<div class="wpcarve-document-toolbar">'
    + '<button title="Strong" data-wpcarve-open="*" data-wpcarve-close="*"></button>'
    + '<button title="Emphasis" data-wpcarve-open="/" data-wpcarve-close="/"></button>'
    + '<button title="Underline" data-wpcarve-open="_" data-wpcarve-close="_"></button>'
    + '<button title="Inline code" data-wpcarve-open="`" data-wpcarve-close="`"></button>'
    + '<button title="Heading 2" data-wpcarve-action="heading" data-wpcarve-open="" data-wpcarve-close="" data-wpcarve-insert="## "></button>'
    + '<button title="Blockquote" data-wpcarve-action="prefix" data-wpcarve-open="" data-wpcarve-close="" data-wpcarve-insert="&gt; "></button>'
    + '<button title="Code block" data-wpcarve-action="block" data-wpcarve-open="" data-wpcarve-close="" data-wpcarve-insert="```&#10;&#10;```"></button>'
    + '<button title="Table" data-wpcarve-action="block" data-wpcarve-open="" data-wpcarve-close="" data-wpcarve-insert="|= H |= V |&#10;| a | b |"></button>'
    + '</div>'
    + '<select class="wpcarve-more-insert"><option value=""></option><option value="footnote"></option>'
    + '<option value="math"></option><option value="citation"></option><option value="---"></option></select>'
    + '<textarea id="content"></textarea>';
  const textarea = document.getElementById('content');
  textarea.value = source;
  textarea.setSelectionRange(start, end);
  const cm = settings.codeMirror ? codeMirrorDouble(source) : null;
  window.wp = cm ? { codeEditor: { initialize: () => ({ codemirror: cm }) } } : {};
  window.wpCarve = { codeEditor: cm ? { codemirror: {} } : null };
  runInNewContext(toggleSource, { window });
  runInNewContext(documentSource, { window, document, Event: window.Event, setTimeout, clearTimeout });
  document.dispatchEvent(new window.Event('DOMContentLoaded'));
  if (cm) cm.setSelection(cm.posFromIndex(start), cm.posFromIndex(end));

  return {
    click: (title) => document.querySelector('[title="' + title + '"]').click(),
    more(kind) {
      const menu = document.querySelector('.wpcarve-more-insert');
      menu.value = kind;
      menu.dispatchEvent(new window.Event('change', { bubbles: true }));
    },
    source: () => (cm ? cm.getValue() : textarea.value),
    caret: () => (cm
      ? [cm.indexFromPos(cm.getCursor('from')), cm.indexFromPos(cm.getCursor('to'))]
      : [textarea.selectionStart, textarea.selectionEnd]),
  };
}

const flavours = [['textarea', {}], ['CodeMirror', { codeMirror: true }]];
const marks = [['Strong', '*'], ['Emphasis', '/'], ['Underline', '_'], ['Inline code', '`']];

// One document with several lines, so a caret offset that is right only by
// line-local accident cannot pass. "word" starts at 45 on the third line.
const DOC = 'Intro paragraph here.\n\nSecond paragraph with bold word.\n\nThird line.';
const BOLD = DOC.indexOf('bold');

for (const [flavour, options] of flavours) {
  for (const [title, mark] of marks) {
    test(`${flavour}: ${title} on a selection keeps the selection on the content`, () => {
      const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
      editor.click(title);
      assert.equal(editor.source(), DOC.slice(0, BOLD) + mark + 'bold' + mark + DOC.slice(BOLD + 4));
      assert.deepEqual(editor.caret(), [BOLD + 1, BOLD + 5]);
    });

    test(`${flavour}: ${title} on a caret in plain text puts the caret between the delimiters`, () => {
      const editor = sourceEditor(DOC, BOLD + 2, BOLD + 2, options);
      editor.click(title);
      assert.equal(editor.source(), DOC.slice(0, BOLD + 2) + mark + mark + DOC.slice(BOLD + 2));
      assert.deepEqual(editor.caret(), [BOLD + 3, BOLD + 3]);
    });

    test(`${flavour}: ${title} on a caret inside the mark removes it and keeps the caret in the word`, () => {
      const marked = 'A line with ' + mark + 'bold' + mark + ' text.\n\nSecond line.';
      const editor = sourceEditor(marked, 14, 14, options);
      editor.click(title);
      assert.equal(editor.source(), 'A line with bold text.\n\nSecond line.');
      assert.deepEqual(editor.caret(), [13, 13]);
    });

    test(`${flavour}: ${title} on a selection of the marked word removes it and keeps the word selected`, () => {
      const marked = 'A line with ' + mark + 'bold' + mark + ' text.\n\nSecond line.';
      const editor = sourceEditor(marked, 13, 17, options);
      editor.click(title);
      assert.equal(editor.source(), 'A line with bold text.\n\nSecond line.');
      assert.deepEqual(editor.caret(), [12, 16]);
    });
  }

  // The mark sits on the last line of a multi-line document, so a caret offset
  // computed within the line diverges from the offset into the document.
  test(`${flavour}: a mark on the last line of a multi-line document lands by document offset`, () => {
    const editor = sourceEditor('one\ntwo\nthree\nfour five six', 22, 22, options);
    editor.click('Strong');
    assert.equal(editor.source(), 'one\ntwo\nthree\nfour fiv**e six');
    assert.deepEqual(editor.caret(), [23, 23]);
  });

  test(`${flavour}: Heading 2 shifts the selection by the prefix instead of dropping it`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.click('Heading 2');
    assert.equal(editor.source(), DOC.replace('Second paragraph', '## Second paragraph'));
    assert.deepEqual(editor.caret(), [BOLD + 3, BOLD + 7]);
  });

  test(`${flavour}: Heading 2 on an existing heading replaces the marker and holds the caret`, () => {
    const editor = sourceEditor('## Title here\n\nBody.', 5, 5, options);
    editor.click('Heading 2');
    assert.equal(editor.source(), '## Title here\n\nBody.');
    assert.deepEqual(editor.caret(), [5, 5]);
  });

  test(`${flavour}: Blockquote shifts the selection by its two-character prefix`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.click('Blockquote');
    assert.equal(editor.source(), DOC.replace('Second paragraph', '> Second paragraph'));
    assert.deepEqual(editor.caret(), [BOLD + 2, BOLD + 6]);
  });

  test(`${flavour}: a code block puts the caret on its empty body line, selecting nothing`, () => {
    const editor = sourceEditor(DOC, BOLD + 2, BOLD + 2, options);
    editor.click('Code block');
    const source = editor.source();
    assert.equal(source, DOC.slice(0, BOLD + 2) + '\n\n```\n\n```\n\n' + DOC.slice(BOLD + 2));
    const caret = editor.caret();
    assert.deepEqual(caret, [BOLD + 8, BOLD + 8]);
    // The body line is empty: the caret sits between the two fence lines.
    assert.equal(source.slice(caret[0] - 1, caret[0] + 1), '\n\n');
  });

  test(`${flavour}: a table template leaves the caret after it rather than selecting it`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.click('Table');
    const caret = editor.caret();
    assert.equal(caret[0], caret[1]);
    assert.equal(editor.source().slice(caret[0] - 9, caret[0]), '| a | b |');
  });

  test(`${flavour}: a footnote selects the placeholder and not the whole anchor`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.more('footnote');
    assert.equal(editor.source(), DOC.slice(0, BOLD + 4) + '^[note]' + DOC.slice(BOLD + 4));
    assert.deepEqual(editor.caret(), [BOLD + 6, BOLD + 10]);
  });

  test(`${flavour}: inline math selects the content inside the delimiters`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.more('math');
    assert.equal(editor.source(), DOC.slice(0, BOLD) + '$`bold`' + DOC.slice(BOLD + 4));
    assert.deepEqual(editor.caret(), [BOLD + 2, BOLD + 6]);
  });

  test(`${flavour}: a citation selects the key`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.more('citation');
    assert.equal(editor.source(), DOC.slice(0, BOLD) + '[@bold]' + DOC.slice(BOLD + 4));
    assert.deepEqual(editor.caret(), [BOLD + 2, BOLD + 6]);
  });

  test(`${flavour}: a divider leaves a collapsed caret after the template`, () => {
    const editor = sourceEditor(DOC, BOLD, BOLD + 4, options);
    editor.more('---');
    const caret = editor.caret();
    assert.equal(caret[0], caret[1]);
    assert.equal(editor.source().slice(caret[0] - 3, caret[0]), '---');
  });
}

// The two editors are no longer allowed to disagree: one index-based path
// serves both, and a divergence here means a second path has grown back.
test('both editors place the caret identically for every toolbar action', () => {
  const actions = [
    (editor) => editor.click('Strong'),
    (editor) => editor.click('Inline code'),
    (editor) => editor.click('Heading 2'),
    (editor) => editor.click('Blockquote'),
    (editor) => editor.click('Code block'),
    (editor) => editor.click('Table'),
    (editor) => editor.more('footnote'),
    (editor) => editor.more('math'),
    (editor) => editor.more('citation'),
  ];
  for (const [start, end] of [[BOLD, BOLD + 4], [BOLD + 2, BOLD + 2]]) {
    for (const act of actions) {
      const plain = sourceEditor(DOC, start, end, {});
      const mirror = sourceEditor(DOC, start, end, { codeMirror: true });
      act(plain);
      act(mirror);
      assert.equal(mirror.source(), plain.source());
      assert.deepEqual(mirror.caret(), plain.caret());
    }
  }
});
