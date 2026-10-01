import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const pickerSource = readFileSync(new URL('../../assets/js/media-picker.js', import.meta.url), 'utf8');
const documentSource = readFileSync(new URL('../../assets/js/code-editor.js', import.meta.url), 'utf8');
const blockSource = readFileSync(new URL('../../assets/blocks/carve/index.js', import.meta.url), 'utf8');

/** The shared mapper, loaded the way WordPress loads it: a plain script on window. */
function loadPicker(extra = {}) {
  const window = { wpCarveMediaL10n: { frameTitle: 'Pick', frameButton: 'Insert' }, ...extra };
  window.window = window;
  runInNewContext(pickerSource, { window });

  return window.wpCarveMedia;
}

/**
 * A real media-library payload. `sizes` is what core's attachment model
 * exposes, and the full-size entry keeps its own URL alongside the derived
 * ones.
 */
const timeline = {
  id: 3000,
  url: 'https://site.test/wp-content/uploads/2025/10/timeline-1.png',
  alt: 'A release timeline',
  caption: '',
  sizes: {
    full: { url: 'https://site.test/wp-content/uploads/2025/10/timeline-1.png', width: 1200, height: 685 },
    large: { url: 'https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png', width: 1024, height: 585 },
    medium: { url: 'https://site.test/wp-content/uploads/2025/10/timeline-1-300x171.png', width: 300, height: 171 },
  },
};

// The URL, the alt text and the attachment class are asserted in separate
// tests on purpose. The class is the mechanism the whole feature rests on, so
// a change that drops it has to fail a test that names it, and has to leave the
// URL and alt tests passing - otherwise a broad failure tells you nothing about
// which part broke.
test('a sized pick writes the URL of the size that was picked', () => {
  const media = loadPicker();

  assert.equal(
    media.pick(timeline, 'large').url,
    'https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png'
  );
  assert.equal(
    media.pick(timeline, 'medium').url,
    'https://site.test/wp-content/uploads/2025/10/timeline-1-300x171.png'
  );
  assert.match(media.toSource(media.pick(timeline, 'large')), /^!\[[^\]]*\]\(\S+-1024x585\.png\)/);
});

test('a pick writes the alt text from the library', () => {
  const media = loadPicker();

  assert.equal(media.pick(timeline, 'large').alt, 'A release timeline');
  assert.match(media.toSource(media.pick(timeline, 'large')), /^!\[A release timeline\]\(/);
});

test('a pick carries the full-size attachment class', () => {
  const media = loadPicker();
  // core's wp_filter_content_tags reads the attachment id off this class at
  // the_content priority 12, after Carve has rendered at 9, and injects
  // width/height/srcset/sizes from it.
  assert.equal(media.pick(timeline, 'large').className, 'wp-image-3000');
  assert.equal(
    media.toSource(media.pick(timeline, 'large')),
    '![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png){.wp-image-3000}'
  );
});

test('the class carries the attachment id even when a derived size was picked', () => {
  const media = loadPicker();
  for (const size of ['full', 'large', 'medium', '']) {
    assert.match(media.toSource(media.pick(timeline, size)), /\{\.wp-image-3000\}$/);
  }
});

test('a library caption becomes a caption line under the image', () => {
  const media = loadPicker();
  const chosen = media.pick({ ...timeline, caption: 'Carve releases through 2025' }, 'full');

  assert.equal(
    media.toSource(chosen),
    '![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1.png){.wp-image-3000}\n'
    + '^ Carve releases through 2025'
  );
});

test('no caption line is written when the attachment has none', () => {
  const media = loadPicker();
  for (const caption of ['', '   ', null, undefined]) {
    const source = media.toSource(media.pick({ ...timeline, caption }, 'full'));
    assert.equal(source.indexOf('\n'), -1, `caption ${JSON.stringify(caption)} invented a line`);
  }
});

test('a multi-line library caption is flattened, because a caption folds the next lines', () => {
  const media = loadPicker();
  const chosen = media.pick({ ...timeline, caption: 'First line\nsecond line' }, 'full');

  assert.equal(chosen.caption, 'First line second line');
  assert.equal(media.toSource(chosen).split('\n').length, 2);
});

test('a captioned image inserted mid-paragraph gets a line of its own', () => {
  const media = loadPicker();
  const chosen = media.pick({ ...timeline, caption: 'A caption' }, 'full');

  assert.match(media.toSourceAt(chosen, 'Text before ', ''), /^\n!\[/);
  assert.match(media.toSourceAt(chosen, 'Text before\n', ''), /^!\[/);
  assert.match(media.toSourceAt(chosen, '', ''), /^!\[/);
  // The caption folds the lines after it, so trailing text needs a break too
  // or it becomes part of the caption.
  assert.match(media.toSourceAt(chosen, '', ' and text after'), /\^ A caption\n$/);
  assert.match(media.toSourceAt(chosen, '', '\nand text after'), /\^ A caption$/);
  assert.match(media.toSourceAt(chosen, '', ''), /\^ A caption$/);
  // An uncaptioned image is inline, so it never gets a break on either side.
  assert.equal(
    media.toSourceAt(media.pick(timeline, 'full'), 'Text before ', ' and after'),
    media.toSource(media.pick(timeline, 'full'))
  );
});

test('an attachment with no usable id writes no class rather than wp-image-NaN', () => {
  const media = loadPicker();

  assert.equal(media.pick({ url: 'https://site.test/x.png' }, '').className, '');
  assert.equal(media.toSource(media.pick({ url: 'https://site.test/x.png' }, '')), '![](https://site.test/x.png)');
});

test('alt and URL cannot break out of the image brackets', () => {
  const media = loadPicker();
  const chosen = media.pick(
    { url: 'https://site.test/a(b).png', alt: 'A [bracket] and a \\slash' },
    ''
  );

  assert.equal(
    media.toSource(chosen),
    '![A \\[bracket\\] and a \\\\slash](https://site.test/a%28b%29.png)'
  );
});

// A label sits inside an image construct, so it has to stay on one line. A
// blank line, or a line opening a heading, a fence or a blockquote, ends the
// paragraph and leaves the two halves of the construct as literal text. The
// alt field is single-line in the library UI, so this guards a stored value
// that arrived some other way rather than something an author can type.
for (const [name, alt] of [
  ['a blank line', 'Line one\n\nLine two'],
  ['a heading', 'Line one\n# heading'],
  ['a fence', 'Line one\n``` php'],
  ['a blockquote', 'Line one\n> quoted'],
]) {
  test(`an alt text containing ${name} stays inside the image label`, () => {
    const media = loadPicker();
    const source = media.toSource(
      media.pick({ id: 7, url: 'https://site.test/a.png', alt }, '')
    );

    assert.equal(source.split('\n').length, 1, source);
    assert.match(source, /^!\[[^[\]]*\]\(https:\/\/site\.test\/a\.png\)\{\.wp-image-7\}$/);
  });
}

// Runs of whitespace collapse to one space rather than reaching the label
// verbatim. Asserted on the value, not on the line count: a tab does not split
// the construct, so a line-count check would pass either way.
test('runs of whitespace in an alt text collapse to single spaces', () => {
  const media = loadPicker();

  assert.equal(
    media.toSource(
      media.pick({ id: 7, url: 'https://site.test/a.png', alt: '  Line\tone   spaced \n' }, '')
    ),
    '![Line one spaced](https://site.test/a.png){.wp-image-7}'
  );
});

test('the picker reports itself unavailable and runs the fallback without wp.media', () => {
  const media = loadPicker();
  let fellBack = 0;

  assert.equal(media.available(), false);
  assert.equal(media.open({ fallback: () => { fellBack += 1; }, onSelect: () => {} }), null);
  assert.equal(fellBack, 1);
});

test('the picker opens a single-image frame and maps the chosen size', () => {
  const opened = [];
  let selectHandler = null;
  const frame = {
    on: (event, handler) => { if (event === 'select') selectHandler = handler; },
    open: () => { opened.push('open'); },
    state: () => ({
      get: () => ({ first: () => ({ toJSON: () => timeline }) }),
      display: () => ({ get: name => (name === 'size' ? 'medium' : null) }),
    }),
  };
  const calls = [];
  const media = loadPicker({ wp: { media: config => { calls.push(config); return frame; } } });

  assert.equal(media.available(), true);
  let got = null;
  media.open({ onSelect: chosen => { got = chosen; } });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].multiple, false);
  assert.equal(calls[0].library.type, 'image');
  assert.equal(calls[0].title, 'Pick');
  assert.equal(calls[0].button.text, 'Insert');
  assert.deepEqual(opened, ['open']);

  selectHandler();
  assert.equal(got.url, 'https://site.test/wp-content/uploads/2025/10/timeline-1-300x171.png');
});

test('all three call sites reach the one shared mapper rather than mapping their own', () => {
  // The defect this guards against is three copies of the wp-image-N rule.
  // Only media-picker.js may spell it.
  assert.match(pickerSource, /'wp-image-' \+ id/);
  for (const [name, source] of [['code-editor.js', documentSource], ['blocks/carve/index.js', blockSource]]) {
    // A quoted occurrence is a construction; prose about the rule is fine.
    assert.equal(source.includes("'wp-image-"), false, `${name} spells the class itself`);
  }

  // Classic source editor and block source editor both build source through
  // toSourceAt; the visual editor maps the same pick onto nodes.
  assert.match(documentSource, /window\.wpCarveMedia/);
  assert.match(documentSource, /media\.toSourceAt\( chosen, before, after \)/);
  assert.match(blockSource, /window\.wpCarveMedia/);
  assert.match(blockSource, /media\.toSourceAt\(\s*\n\s*chosen,\s*\n\s*selection\.value\.slice\( 0, selection\.start \),/);
  assert.match(blockSource, /attrs\.class = chosen\.className/);

  // Each one keeps a prompt fallback for a screen without wp.media.
  assert.match(documentSource, /fallback: \(\) => insertLinked\( 'image' \)/);
  assert.match(blockSource, /fallback: \(\) => openInsertDialog\( 'image' \)/);
  assert.match(blockSource, /fallback: \(\) => promptImageUrl\( ed \)/);
});

test('the visual editor builds a figure with a caption child, and a bare image without', () => {
  assert.match(blockSource, /type: 'carveFigure'/);
  assert.match(blockSource, /type: 'carveCaption', content: \[ \{ type: 'text', text: chosen\.caption \} \]/);
  assert.match(blockSource, /if \( ! chosen\.caption \) \{\s*\n\s*ed\.chain\(\)\.focus\(\)\.setImage\( attrs \)\.run\(\);/);
});

/**
 * Drive the real classic-editor toolbar, in both arms: with the media modal
 * available, and with it missing so the prompt has to take over.
 */
async function classicEditor({ withMedia, prompts = [] }) {
  const { Window } = await import('happy-dom');
  const win = new Window();
  const { document } = win;
  document.body.innerHTML = '<div class="wpcarve-document-toolbar">'
    + '<button data-wpcarve-open="" data-wpcarve-action="image">Image</button></div>'
    + '<textarea id="content"></textarea>';
  const textarea = document.getElementById('content');
  textarea.value = 'Before. After.';
  textarea.setSelectionRange(8, 8);

  win.window = win;
  win.wpCarve = { codeEditor: null, imageUrlLabel: 'Image URL', imageAltLabel: 'Alt text' };
  win.wpCarveMediaL10n = { frameTitle: 'Pick', frameButton: 'Insert' };
  const asked = [];
  win.prompt = label => { asked.push(label); return prompts.shift(); };

  let selectHandler = null;
  win.wp = withMedia
    ? {
      media: () => ({
        on: (event, handler) => { if (event === 'select') selectHandler = handler; },
        open: () => {},
        state: () => ({
          get: () => ({ first: () => ({ toJSON: () => ({ ...timeline, caption: 'From the library' }) }) }),
          display: () => ({ get: name => (name === 'size' ? 'large' : null) }),
        }),
      }),
    }
    : {};

  // media-picker.js is enqueued as a dependency, so it runs first.
  runInNewContext(pickerSource, { window: win });
  runInNewContext(readFileSync(new URL('../../assets/js/inline-toggle.js', import.meta.url), 'utf8'), { window: win });
  runInNewContext(documentSource, {
    window: win, document, Event: win.Event, setTimeout, clearTimeout,
  });
  document.dispatchEvent(new win.Event('DOMContentLoaded'));
  document.querySelector('[data-wpcarve-action="image"]').click();

  return { textarea, asked, select: () => selectHandler && selectHandler() };
}

test('the classic Image button inserts library source without any prompt', async () => {
  const editor = await classicEditor({ withMedia: true });

  assert.deepEqual(editor.asked, [], 'the modal replaced the prompt');
  editor.select();
  assert.equal(
    editor.textarea.value,
    'Before. \n![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png)'
    + '{.wp-image-3000}\n^ From the library\nAfter.'
  );
});

test('the classic Image button falls back to the prompt when wp.media is absent', async () => {
  const editor = await classicEditor({
    withMedia: false,
    prompts: ['https://example.com/a.png', 'Alt from prompt'],
  });

  assert.deepEqual(editor.asked, ['Image URL', 'Alt text']);
  assert.equal(editor.textarea.value, 'Before. ![Alt from prompt](https://example.com/a.png)After.');
});
