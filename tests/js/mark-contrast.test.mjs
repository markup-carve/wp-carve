/**
 * `=highlight=` renders as `<mark>`, and a rule that pins a wash while leaving
 * `color: inherit` computes whatever ink the surrounding theme supplies. On a
 * dark theme that was 1.01:1.
 *
 * A CSS-text assertion cannot see a contrast failure, so these read the
 * computed color and background a real browser resolves and compute the WCAG
 * ratio from them. happy-dom cannot evaluate
 * `@media (prefers-color-scheme: dark)` at all, so a test written there would
 * pass whether the stylesheet is fixed or not; Chromium is the authority.
 *
 * Both dark spellings are covered. The media query and the
 * `:root[data-theme="dark"]` sibling are separate rules, and a fix to one does
 * not reach the other.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');

// What markup-carve/carve emits for
// `=mark with {+ins+} and {-del-} and [link](#x) inside=`. Every nested kind
// here is reachable from core syntax, with no extension enabled.
const markup =
	'<p>Text with <mark>mark with <ins>ins</ins> and <del>del</del> and' +
	' <a href="#x">link</a> inside</mark>.</p>';

// WCAG 2.x relative luminance and contrast ratio, over `rgb()` strings as
// `getComputedStyle` returns them.
function luminance(color) {
	const [r, g, b] = color.match(/[\d.]+/g).slice(0, 3).map(Number);
	const channels = [r, g, b].map((value) => {
		const srgb = value / 255;
		return srgb <= 0.03928 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
	});
	return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(foreground, background) {
	const [lighter, darker] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
	return (lighter + 0.05) / (darker + 0.05);
}

// AAA for body text. A highlight is prose, and the wash it sits on is the
// plugin's own choice rather than the theme's, so there is no reason to settle
// for the AA floor.
const THRESHOLD = 7;

// `prose` is the ink the surrounding WordPress theme hands down, and
// `otherProse` is a second theme's. The dark `prose` value is what the
// measurement in the issue used: on the broken rule the highlight inherited
// it, which is how a highlight reached 1.01:1. The pair is what pins the
// highlight's ink as its own rather than the theme's.
const themes = [
	{
		name: 'light',
		colorScheme: 'light',
		attribute: null,
		prose: '#1f2328',
		otherProse: '#57606a',
		page: '#ffffff',
	},
	{
		name: 'dark via prefers-color-scheme',
		colorScheme: 'dark',
		attribute: null,
		prose: '#f0f6fc',
		otherProse: '#8b949e',
		page: '#0d1117',
	},
	{
		// A site toggle forces dark regardless of the OS preference, so the OS
		// stays light here and only the attribute asks for dark.
		name: 'dark via the data-theme attribute',
		colorScheme: 'light',
		attribute: 'dark',
		prose: '#f0f6fc',
		otherProse: '#8b949e',
		page: '#0d1117',
	},
];

const browser = await chromium.launch();
test.after(() => browser.close());

async function computed(theme, prose = theme.prose) {
	const page = await browser.newPage({ colorScheme: theme.colorScheme });
	try {
		await page.setContent(
			`<!doctype html><html${theme.attribute ? ` data-theme="${theme.attribute}"` : ''}>` +
				`<head><style>${styles}</style>` +
				`<style>body { color: ${prose}; background: ${theme.page}; }</style>` +
				`</head><body><div class="wpcarve">${markup}</div></body></html>`,
		);
		return await page.evaluate(() =>
			Object.fromEntries(
				['mark', 'mark ins', 'mark del', 'mark a'].map((selector) => {
					const style = getComputedStyle(document.querySelector(selector));
					return [selector, { color: style.color, background: style.backgroundColor }];
				}),
			),
		);
	} finally {
		await page.close();
	}
}

for (const theme of themes) {
	test(`a highlight clears ${THRESHOLD}:1 in ${theme.name}`, async () => {
		const styled = await computed(theme);
		const { color, background } = styled.mark;
		const ratio = contrast(color, background);
		assert.ok(
			ratio >= THRESHOLD,
			`${color} on ${background} is ${ratio.toFixed(2)}:1, under ${THRESHOLD}:1`,
		);
	});

	test(`a highlight's ink does not come from the theme in ${theme.name}`, async () => {
		const [styled, other] = await Promise.all([
			computed(theme),
			computed(theme, theme.otherProse),
		]);
		assert.equal(
			styled.mark.color,
			other.mark.color,
			'the highlight inherits the theme prose ink instead of carrying its own',
		);
	});

	test(`a nested mark inside a highlight takes the highlight's ink in ${theme.name}`, async () => {
		const styled = await computed(theme);
		for (const selector of ['mark ins', 'mark del', 'mark a']) {
			assert.equal(
				styled[selector].color,
				styled.mark.color,
				`${selector} keeps its own color instead of the highlight's ink`,
			);
		}
	});
}
