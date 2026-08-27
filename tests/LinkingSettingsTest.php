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

    public function testMentionsAndTagsLinkToArchives(): void
    {
        $html = (new Converter(['mentions_enabled' => true]))->toHtml('Ask @alice about #carve.');

        $this->assertStringContainsString('/author/alice/', $html);
        $this->assertStringContainsString('/tag/carve/', $html);
    }

    public function testWikilinksAreLiteralByDefault(): void
    {
        $this->assertStringNotContainsString('wikilink', (new Converter([]))->toHtml('See [[Getting Started]].'));
    }

    public function testAnUnresolvedWikilinkStaysInTheTextAsABrokenLink(): void
    {
        // Not dropped and not guessed at: the anchor keeps its class and its
        // title so a theme can style `a.wikilink[href="#"]` the way a wiki
        // styles a red link. Hiding it would hide the missing page.
        $html = (new Converter(['wikilinks_enabled' => true]))->toHtml('See [[No Such Page]].');

        $this->assertStringContainsString('class="wikilink"', $html);
        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringContainsString('No Such Page', $html);
    }
}
