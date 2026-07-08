// Innovation A: in-browser Carve engine for instant editor preview.
// Bundled by `npm run build` into ../vendor/carve.js (IIFE), exposing
// `window.wpCarveEngine.carveToHtml`. The plugin loads it before the editor
// script; when it is absent the editor falls back to the REST endpoint.
import { carveToHtml, tabs, details, spoiler, codeGroup } from '@markup-carve/carve'

// The block preview must match the published front-end render, so the same
// content widgets the PHP post path enables (CodeGroupExtension, TabsExtension,
// DetailsExtension, SpoilerExtension) are enabled here. Without them the
// preview showed raw `<div class="tab">` markup while the front end rendered
// interactive tabs/disclosures, so tabs and details looked broken in preview.
// Settings-dependent, non-round-trippable extras (TOC, permalinks, heading
// shift, smart quotes) are intentionally omitted - they need PHP settings and
// are added server-side only.
const EXTENSIONS = [codeGroup(), tabs(), details(), spoiler()]

window.wpCarveEngine = {
  carveToHtml(source) {
    return carveToHtml(String(source ?? ''), { extensions: EXTENSIONS })
  },
}
