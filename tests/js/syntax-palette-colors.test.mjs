import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { chromium } from 'playwright';

const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');
const palette = JSON.parse(await readFile(new URL('../../assets/syntax-palette.json', import.meta.url), 'utf8'));
const sample = `<div class="wpcarve-highlight-editor"><div class="wpcarve-highlight-layer">
  <span id="list" class="token list">-</span>
  <span class="token list"><span id="task" class="token constant">[x]</span></span>
  <span id="definition" class="token definition-term">:: Term</span>
  <span class="token code-block"><span id="language" class="token language">php</span><span id="fence" class="token punctuation">&#96;&#96;&#96;</span></span>
  <span class="token table-header"><span id="boundary" class="token table-boundary">|</span></span>
  <span id="caption" class="token caption title"><span id="captionMarker" class="token punctuation">^</span> Listing</span>
</div></div><div class="wpcarve-document-workspace"><span id="workspaceBoundary" class="wpcarve-token-table wpcarve-token-table-header wpcarve-token-table-boundary">|</span><span id="workspaceCaptionMarker" class="wpcarve-token-caption wpcarve-token-title wpcarve-token-punctuation">^</span><span id="workspaceCaption" class="wpcarve-token-caption wpcarve-token-title"> Listing</span></div>`;

const targets = { list: 'list', task: 'task', definition: 'definition', language: 'language', fence: 'muted', boundary: 'tableBoundary', workspaceBoundary: 'tableBoundary', caption: 'captionText', captionMarker: 'captionMarker', workspaceCaption: 'captionText', workspaceCaptionMarker: 'captionMarker' };

const browser = await chromium.launch();
try {
  for (const [mode, root, system] of [
    ['light', 'data-theme="light"', 'dark'],
    ['dark', 'data-theme="dark"', 'light'],
    ['dark', '', 'dark'],
  ]) {
    await test(`Carve source tokens use the ${mode} palette with root ${root || 'system'}`, async () => {
      const page = await browser.newPage({ colorScheme: system });
      try {
        await page.setContent(`<html ${root}><head><style>${styles}</style></head><body>${sample}</body></html>`);
        const colors = await page.evaluate((ids) => Object.fromEntries(
          ids.map((id) => [id, getComputedStyle(document.getElementById(id)).color]),
        ), Object.keys(targets));
        for (const [id, role] of Object.entries(targets)) {
          const hex = palette[mode][role];
          const rgb = hex.match(/\w\w/g).map((channel) => Number.parseInt(channel, 16));
          assert.equal(colors[id], `rgb(${rgb.join(', ')})`, `${mode} ${id}`);
        }
      } finally {
        await page.close();
      }
    });
  }
} finally {
  await browser.close();
}
