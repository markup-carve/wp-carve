import { Node } from '@tiptap/core';

/**
 * Media embeds. carve-php-media-embed renders `:youtube[ID]` / `:vimeo[ID]` /
 * `:media[URL]` as provider <iframe>s. This recovers the provider + id from the
 * iframe src so the common providers round-trip in the visual editor.
 *
 * `:media[URL]` canonicalizes to the provider form (`:vimeo[ID]`) - identical
 * output, but the source text changes, so the round-trip warning still notes it.
 * iframes from providers not matched here are left unhandled (still flagged).
 */
function parseSrc( src ) {
	const s = src || '';
	let m;
	if ( ( m = s.match( /youtube(?:-nocookie)?\.com\/embed\/([\w-]+)/ ) ) ) {
		return { provider: 'youtube', id: m[ 1 ] };
	}
	if ( ( m = s.match( /player\.vimeo\.com\/video\/(\d+)/ ) ) ) {
		return { provider: 'vimeo', id: m[ 1 ] };
	}
	return null;
}

export const MediaEmbed = Node.create( {
	name: 'mediaEmbed',
	group: 'inline',
	inline: true,
	atom: true,

	addAttributes() {
		return {
			provider: { default: '' },
			mediaId: { default: '' },
		};
	},

	parseHTML() {
		return [
			{
				tag: 'iframe',
				getAttrs: ( el ) => {
					const parsed = parseSrc( el.getAttribute( 'src' ) );
					return parsed ? { provider: parsed.provider, mediaId: parsed.id } : false;
				},
			},
		];
	},

	renderHTML( { node } ) {
		const { provider, mediaId } = node.attrs;
		const src =
			provider === 'youtube'
				? '//www.youtube.com/embed/' + mediaId
				: provider === 'vimeo'
					? '//player.vimeo.com/video/' + mediaId
					: '';
		return [
			'iframe',
			{ src, title: provider + ' embed', frameborder: '0', loading: 'lazy', allowfullscreen: 'true' },
		];
	},
} );

export default MediaEmbed;
