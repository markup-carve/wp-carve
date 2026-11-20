<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use WpCarve\Converter;

/**
 * The four link-shaping settings, each asserted OFF and ON.
 *
 * Both halves matter: an implementation that applied one of these
 * unconditionally would satisfy every "on" assertion on its own, and these
 * rewrite content on every post, so a default that leaked would change a whole
 * site's prose without anyone asking for it.
 */
#[UsesClass(Converter::class)]
class LinkingSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        // In tearDown, not at the end of each test body: a failing assertion
        // never reaches the end of the body, and a filter left registered then
        // surfaces as a failure in the NEXT test instead of this one.
        wpcarve_test_reset_hooks();
        wpcarve_test_set_pages([]);
        parent::tearDown();
    }

    public function testHeadingNumbersAreOffByDefault(): void
    {
        $this->assertStringNotContainsString('section-number', (new Converter([]))->toHtml('# Top'));
    }

    public function testHeadingNumbersNumberEachLevel(): void
    {
        $html = (new Converter(['heading_numbers' => true]))->toHtml("# Top\n\n## Sub");

        $this->assertStringContainsString('>1</span>', $html);
        $this->assertStringContainsString('>1.1</span>', $html);
    }

    public function testHeadingNumbersStayOutOfTheEditor(): void
    {
        // Generated markup must not reach the visual editor, or serializing it
        // back would freeze a site setting into the post source.
        $html = (new Converter(['heading_numbers' => true]))->toHtml('# Top', 'editor');

        $this->assertStringNotContainsString('section-number', $html);
    }

    public function testExternalLinksAreUntouchedByDefault(): void
    {
        $html = (new Converter([]))->toHtml('[a](https://elsewhere.test/x)');

        $this->assertStringNotContainsString('rel=', $html);
        $this->assertStringNotContainsString('target=', $html);
    }

    public function testExternalLinksGetRelButNotTargetByDefault(): void
    {
        $html = (new Converter(['external_links' => true]))->toHtml('[a](https://elsewhere.test/x)');

        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('target=', $html);
    }

    public function testExternalLinksCanOpenInANewTab(): void
    {
        $html = (new Converter(['external_links' => true, 'external_links_new_tab' => true]))
            ->toHtml('[a](https://elsewhere.test/x)');

        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function testExternalLinkSettingsMergeWithAnAuthoredRel(): void
    {
        $settings = ['external_links' => true, 'external_links_new_tab' => true, 'external_links_nofollow' => true];
        $html = (new Converter($settings))->toHtml('[a](https://elsewhere.test/x){rel=ugc}');

        $this->assertStringContainsString('rel="ugc noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertSame(1, substr_count($html, 'rel="'));
    }

    public function testExternalLinksCanAddNofollow(): void
    {
        $html = (new Converter(['external_links' => true, 'external_links_nofollow' => true]))
            ->toHtml('[a](https://elsewhere.test/x)');

        $this->assertStringContainsString('nofollow', $html);
    }

    public function testARelativeLinkIsNeverExternal(): void
    {
        $html = (new Converter(['external_links' => true, 'external_links_new_tab' => true]))
            ->toHtml('[a](/local/page)');

        $this->assertStringNotContainsString('target=', $html);
    }

    public function testMentionsAreLiteralByDefault(): void
    {
        $this->assertStringNotContainsString('<a', (new Converter([]))->toHtml('Ask @alice.'));
    }

    /**
     * The off case needs the mention SPAN asserted away, not only the link.
     * Mentions are core engine syntax that the converter auto-registers on
     * first parse, so declining to register the extension leaves the engine
     * default rendering `<span class="mention">` - which the `<a` assertion
     * above cannot see.
     */
    public function testMentionsOffLeavesTheSourceTextInAPost(): void
    {
        $html = (new Converter([]))->toHtml('Hello @alice and #topic here.', 'post');

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringNotContainsString('class="tag"', $html);
        $this->assertStringContainsString('@alice', $html);
        $this->assertStringContainsString('#topic', $html);
    }

    public function testMentionsOffLeavesTheSourceTextInAComment(): void
    {
        $html = (new Converter([]))->toHtml('Hello @alice and #topic here.', 'comment');

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringNotContainsString('class="tag"', $html);
        $this->assertStringContainsString('@alice', $html);
        $this->assertStringContainsString('#topic', $html);
    }

    public function testMentionsOnStillMarksUpBothContexts(): void
    {
        $converter = new Converter(['mentions_enabled' => true]);

        foreach (['post', 'comment'] as $context) {
            $html = $converter->toHtml('Hello @alice and #topic here.', $context);

            $this->assertStringContainsString('class="mention"', $html, $context);
            // 0.2 (C2-H-163, the `comment` profile table): `tag` is outside the
            // comment profile and degrades to its text.
            if ($context === 'comment') {
                $this->assertStringNotContainsString('class="tag"', $html, $context);
            } else {
                $this->assertStringContainsString('class="tag"', $html, $context);
            }
        }
    }

    public function testMentionsAndTagsLinkToArchives(): void
    {
        $html = (new Converter(['mentions_enabled' => true]))->toHtml('Ask @alice about #carve.');

        $this->assertStringContainsString('/author/alice/', $html);
        $this->assertStringContainsString('/tag/carve/', $html);
    }

    public function testWikilinksAreLiteralByDefault(): void
    {
        $this->assertStringNotContainsString('wikilink', (new Converter([]))->toHtml('See :wiki[Getting Started].'));
    }

    public function testALinkToThisSiteIsNotExternal(): void
    {
        // The half that needs a real host to mean anything: with no
        // `home_url()` every absolute URL looks external, so this passes for
        // the wrong reason unless the site knows its own name.
        $html = (new Converter(['external_links' => true, 'external_links_new_tab' => true]))
            ->toHtml('[a](https://example.test/page)');

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testAnotherHostCanBeDeclaredInternal(): void
    {
        add_filter('wpcarve_internal_hosts', static function (array $hosts): array {
            $hosts[] = 'staging.example.test';

            return $hosts;
        }, 10, 1);

        $html = (new Converter(['external_links' => true, 'external_links_new_tab' => true]))
            ->toHtml('[a](https://staging.example.test/page)');

        $this->assertStringNotContainsString('target=', $html);
    }

    public function testMentionTemplatesAreBuiltFromTheSiteUrl(): void
    {
        $html = (new Converter(['mentions_enabled' => true]))->toHtml('Ask @alice about #carve.');

        $this->assertStringContainsString('https://example.test/author/alice/', $html);
        $this->assertStringContainsString('https://example.test/tag/carve/', $html);
    }

    public function testAResolvedWikilinkPointsAtThePost(): void
    {
        // The resolution path. It was unreachable while `get_page_by_path` did
        // not exist, so every wikilink assertion here was really an assertion
        // about the fallback.
        wpcarve_test_set_pages(['getting-started' => 42]);

        $html = (new Converter(['wikilinks_enabled' => true]))->toHtml('See :wiki[Getting Started].');

        $this->assertStringContainsString('/?p=42', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function testAFilterCanResolveAWikilinkItself(): void
    {
        // Two accepted arguments, or the registry hands the closure only the
        // value and PHP raises ArgumentCountError.
        add_filter('wpcarve_wikilink_url', static fn (?string $url, string $page): string => '/docs/' . strtolower($page[0]), 10, 2);

        $html = (new Converter(['wikilinks_enabled' => true]))->toHtml('See :wiki[Zebra].');

        $this->assertStringContainsString('/docs/z', $html);
    }

    public function testAnExcerptShowsTheWikilinkLabelNotItsMarkup(): void
    {
        // An excerpt is the one place markup must not appear. 0.2 spells a wiki
        // link `:wiki[Page]` (U-408), and an unregistered inline extension
        // renders its content as the fallback (C2-X-004), so the label shows
        // whether the setting is on or off.
        $source = 'See :wiki[Getting Started] for more.';

        $this->assertSame(
            'See Getting Started for more.',
            trim((new Converter([]))->toText($source)),
            'off by default: the fallback is the label (C2-X-004)',
        );
        $this->assertSame(
            'See Getting Started for more.',
            trim((new Converter(['wikilinks_enabled' => true]))->toText($source)),
        );
    }

    public function testAnExcerptDoesNotResolveWikilinksAgainstTheDatabase(): void
    {
        // Plain text discards the href, so resolving would spend a lookup per
        // link on every archive page for a string nothing reads. Pinned by the
        // OUTPUT being identical whether or not the page exists.
        wpcarve_test_set_pages(['getting-started' => 42]);
        $resolved = trim((new Converter(['wikilinks_enabled' => true]))->toText('See :wiki[Getting Started].'));

        wpcarve_test_set_pages([]);
        $unresolved = trim((new Converter(['wikilinks_enabled' => true]))->toText('See :wiki[Getting Started].'));

        $this->assertSame($resolved, $unresolved);
        $this->assertSame('See Getting Started.', $resolved);
    }

    public function testCommentsDoNotResolveWikilinks(): void
    {
        // Comments get no post extensions, and a commenter should not be able
        // to mint links into the site by typing brackets.
        // 0.2: the unregistered `:wiki[...]` renders its label as the fallback
        // (U-408, C2-X-004); what matters is that no link is minted.
        $this->assertSame(
            'See Getting Started.',
            trim((new Converter(['wikilinks_enabled' => true]))->toText('See :wiki[Getting Started].', 'comment')),
        );
        $this->assertStringNotContainsString(
            'wikilink',
            (new Converter(['wikilinks_enabled' => true]))->toHtml('See :wiki[Getting Started].', 'comment'),
        );
    }

    public function testAnUnresolvedWikilinkStaysInTheTextAsABrokenLink(): void
    {
        // Not dropped and not guessed at: the anchor keeps its class and its
        // title so a theme can style `a.wikilink[href="#"]` the way a wiki
        // styles a red link. Hiding it would hide the missing page.
        $html = (new Converter(['wikilinks_enabled' => true]))->toHtml('See :wiki[No Such Page].');

        $this->assertStringContainsString('class="wikilink"', $html);
        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringContainsString('No Such Page', $html);
    }
}
