# Images and the media library

Every editing surface that inserts an image opens the WordPress media library:
the Carve block in source mode, the same block in visual mode, and the Carve
Document editor on the classic screen. Press **Image** in the toolbar, upload or
pick an attachment, choose a size, and press Insert.

The selection is written as Carve source:

``````
![Alt text from the library](https://site.test/wp-content/uploads/2025/10/cat-1024x768.jpg){.wp-image-3000}
^ Caption from the library
``````

The caption line appears only when the attachment has one.

## Why the class is there

`{.wp-image-3000}` is what gives the image responsive markup. Carve renders at
`the_content` priority 9 and WordPress runs `wp_filter_content_tags` at priority
12, so core reads the attachment id off that class after Carve has rendered and
sanitized, then adds `width`, `height`, `loading`, `decoding`, `srcset` and
`sizes` from the attachment itself.

Writing `srcset` into the source by hand does not work instead: those
attributes are not in the `img` allowlist that sanitization applies, so they are
stripped before core ever sees them. The class is the only route.

Core's injection also happens outside the render cache, so a post that stays
cached for a long time keeps correct responsive markup as the attachment's
sizes change.

Delete the class and the image still renders, just without responsive
attributes. Nothing else depends on it.

## What the source owns

Alt text and caption are copied from the library once, when the image is
inserted. After that the source is the only record of them, and editing the
attachment in the library does not change posts that already reference it. This
is deliberate: the same `.crv` source has to render where no media library
exists, so it cannot depend on one.

Edit the alt text or the caption in the source whenever the wording needs to
differ from the library's.

## Sizes

The size chosen in the modal decides the URL that gets written. The class keeps
the id of the full-size attachment either way, which is what lets core work out
the whole `srcset` for the size that was picked.

The destination is always a plain URL rather than an attachment reference, so
the source stays portable across sites and renderers.

## When the modal is unavailable

On a screen where the media library scripts are not loaded, the Image button
falls back to asking for a URL and alt text, as it did before. Source written
that way carries no attachment class, so it gets no responsive attributes.

## Profiles

Images render as literal `[img: …]` text under the `comment` and `minimal`
profiles, so this applies to posts and pages. The comment toolbar has no image
affordance. See [Profiles & rendering](profiles.md).
