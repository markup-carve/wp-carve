<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WpCarve\Converter;
use WpCarve\Extensions;
use WpCarve\Ingest\Importer;
use WpCarve\Ingest\ImporterUnavailable;
use WpCarve\Ingest\PasteController;
use WpCarve\Settings;

/**
 * Settings that map onto carve-php 0.2 engine options and extensions.
 */
class EngineWiringTest extends TestCase
{
    public function testCodeGroupRendersTabPanels(): void
    {
        $html = (new Converter(Settings::defaults()))->toHtml("::: code-group\n```php\n\$a = 1;\n```\n\n```js\nconst a = 1;\n```\n:::\n");

        $this->assertStringContainsString('<div class="code-group"', $html);
        $this->assertStringContainsString('class="code-group-label">php</label>', $html);
    }

    public function testSemanticSpansRenderTheirElements(): void
    {
        $html = (new Converter(Settings::defaults()))->toHtml("Press [Ctrl]{kbd} to see [out]{samp}.\n");

        $this->assertStringContainsString('<kbd>Ctrl</kbd>', $html);
        $this->assertStringContainsString('<samp>out</samp>', $html);
    }

    public function testAnExtensionTurnedOffByNameRendersItsFallback(): void
    {
        $settings = Settings::defaults();
        $settings[Extensions::settingKey('codeGroup')] = false;

        $html = (new Converter($settings))->toHtml("::: code-group\n```php\nx\n```\n:::\n");

        $this->assertStringNotContainsString('code-group-label', $html);
    }

    public function testEveryCatalogEntryHasADefaultOnSetting(): void
    {
        $defaults = Settings::defaults();
        foreach (array_keys(Extensions::CATALOG) as $name) {
            $this->assertTrue($defaults[Extensions::settingKey($name)], $name);
        }
        $this->assertSame('extension_code_group', Extensions::settingKey('codeGroup'));
    }

    public function testSoftBreakSettingReachesTheEngine(): void
    {
        $settings = Settings::defaults();
        $settings['post_soft_break'] = 'br';

        $html = (new Converter($settings))->toHtml("one\ntwo\n");

        $this->assertStringContainsString("one<br>\ntwo", $html);
    }

    public function testTabNormalizationUsesTheConfiguredWidth(): void
    {
        $settings = Settings::defaults();
        $settings['normalize_tabs'] = true;
        $settings['tab_width'] = 4;

        $html = (new Converter($settings))->toHtml("```\n\tx\n```\n");

        $this->assertStringContainsString("<code>    x\n", $html);
    }

    public function testSmartQuoteLocaleReachesTheEngine(): void
    {
        $settings = Settings::defaults();
        $settings['post_profile'] = 'full';
        $settings['smart_quotes'] = true;
        $settings['smart_quotes_locale'] = 'de';

        $html = (new Converter($settings))->toHtml("He said \"hi\".\n");

        $this->assertStringContainsString('„hi“', $html);
    }

    public function testSmartQuoteLocaleReachesTheArticleProfile(): void
    {
        $settings = Settings::defaults();
        $settings['post_profile'] = 'article';
        $settings['smart_quotes'] = true;
        $settings['smart_quotes_locale'] = 'de';

        $html = (new Converter($settings))->toHtml("He said \"hi\".\n");

        $this->assertStringContainsString('„hi“', $html);
    }

    public function testExcerptsUseThePlainWriter(): void
    {
        $text = (new Converter(Settings::defaults()))->toText("= Title\n\nSome *bold* text.\n\n- a\n- b\n");

        $this->assertSame("Title\n\nSome bold text.\n\n- a\n- b", $text);
    }

    public function testServerRefusesBbcodeAndPointsAtTheBrowser(): void
    {
        $request = new WP_REST_Request('POST', '/carve/v1/ingest');
        $request->set_param('source', '[b]bold[/b]');
        $request->set_param('from', 'auto');

        $response = (new PasteController())->ingest($request);

        $this->assertSame(422, $response->get_status());
        $this->assertSame('bbcode', $response->get_data()['from']);
        $this->assertTrue($response->get_data()['browser']);
    }

    public function testServerImportsMarkdownAndDjot(): void
    {
        $this->assertStringContainsString('*bold*', Importer::import('markdown', "Some **bold** text.\n")['value']);
        $this->assertStringContainsString('*strong*', Importer::import('djot', "Some *strong* text.\n")['value']);
    }

    public function testImporterNamesTheBrowserPathForBbcode(): void
    {
        $this->expectException(ImporterUnavailable::class);
        $this->expectExceptionMessage('BBCode is converted in the block editor');

        Importer::import('bbcode', '[b]x[/b]');
    }
}
