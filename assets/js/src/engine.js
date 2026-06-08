// Innovation A: in-browser Carve engine for instant editor preview.
// Bundled by `npm run build` into ../vendor/carve.js (IIFE), exposing
// `window.wpCarveEngine.carveToHtml`. The plugin loads it before the editor
// script; when it is absent the editor falls back to the REST endpoint.
import { carveToHtml } from '@markup-carve/carve'

window.wpCarveEngine = {
  carveToHtml(source) {
    return carveToHtml(String(source ?? ''))
  },
}
