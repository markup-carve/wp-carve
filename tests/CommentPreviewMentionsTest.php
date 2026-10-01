<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WpCarve\Converter;
use WpCarve\Rest\RenderController;
use WpCarve\Settings;

/**
 * `/carve/v1/preview-comment` runs the full render pipeline for an
 * unauthenticated caller, so every content setting that is off has to be off
 * here too. The mentions switch is the one worth pinning: it reaches a public
 * surface, and the renderer auto-registers mentions unless something says no.
 */
#[UsesClass(RenderController::class)]
#[UsesClass(Converter::class)]
class CommentPreviewMentionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function previewAnonymously(array $settings, string $carve): string
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['enable_comments' => true] + $settings;
        $GLOBALS['_wpcarve_test_current_user'] = 0;
        $GLOBALS['_wpcarve_test_transients'] = [];

        $request = new WP_REST_Request();
        $request->set_param('carve', $carve);
        $response = (new RenderController(new Converter(Settings::all())))->previewComment($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        return (string)$data['html'];
    }

    public function testThePublicPreviewHonorsMentionsBeingOff(): void
    {
        $html = $this->previewAnonymously([], 'Hello @alice and #topic here.');

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringNotContainsString('class="tag"', $html);
        $this->assertStringContainsString('@alice', $html);
        $this->assertStringContainsString('#topic', $html);
    }

    public function testThePublicPreviewStillMarksUpMentionsWhenOn(): void
    {
        $html = $this->previewAnonymously(['mentions_enabled' => true], 'Hello @alice and #topic here.');

        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('class="tag"', $html);
    }
}
