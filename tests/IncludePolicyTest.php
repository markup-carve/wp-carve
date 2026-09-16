<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;
use WpCarve\Admin\SettingsPage;
use WpCarve\Blocks\CarveBlock;
use WpCarve\Converter;
use WpCarve\Includes\IncludePolicy;
use WpCarve\Includes\IncludeReport;
use WpCarve\Meta\RenderCache;
use WpCarve\Plugin;
use WpCarve\Rest\RenderController;
use WpCarve\Settings;

class IncludePolicyTest extends TestCase
{
    /**
     * @var int
     */
    private const ADMIN = 1;

    /**
     * @var int
     */
    private const AUTHOR = 2;

    private string $base;

    private string $root;

    protected function setUp(): void
    {
        wpcarve_test_reset_hooks();
        $this->base = sys_get_temp_dir() . '/wpcarve-includes-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/root';
        mkdir($this->root . '/sub', 0777, true);
        file_put_contents($this->root . '/chapter.crv', "Included chapter text.\n");
        file_put_contents($this->base . '/secret.crv', "Secret outside the root.\n");

        $GLOBALS['_wpcarve_test_options'] = [];
        $GLOBALS['_wpcarve_test_meta'] = [];
        $GLOBALS['_wpcarve_test_caps'] = [self::ADMIN => [IncludePolicy::CAPABILITY => true]];
        $this->configure($this->root);
        (new IncludePolicy())->register();
    }

    protected function tearDown(): void
    {
        wpcarve_test_reset_hooks();
        $this->remove($this->base);
        unset(
            $GLOBALS['_wpcarve_test_options'],
            $GLOBALS['_wpcarve_test_meta'],
            $GLOBALS['_wpcarve_test_caps'],
            $GLOBALS['_wpcarve_test_current_user'],
            $GLOBALS['_wpcarve_test_current_post'],
        );
    }

    private function configure(string $root): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['include_root' => $root];
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function saveAs(int $userId, int $postId, string $content): void
    {
        wpcarve_test_set_post($postId, ['post_content' => $content]);
        update_post_meta($postId, '_wpcarve_enabled', '1');
        $GLOBALS['_wpcarve_test_current_user'] = $userId;
        (new IncludePolicy())->onSave($postId, get_post($postId));
    }

    private function renderPost(int $postId): string
    {
        $GLOBALS['_wpcarve_test_current_post'] = $postId;
        $plugin = new Plugin();
        (new ReflectionProperty($plugin, 'converter'))->setValue($plugin, new Converter(Settings::all()));

        return $plugin->maybeRenderPost('');
    }

    public function testTrustedSaveExpandsAnInclude(): void
    {
        $this->saveAs(self::ADMIN, 10, "{{ chapter.crv }}\n");

        $html = $this->renderPost(10);

        $this->assertStringContainsString('Included chapter text.', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function testUntrustedSaveLeavesTheDirectiveLiteral(): void
    {
        $this->saveAs(self::AUTHOR, 11, "{{ chapter.crv }}\n");

        $html = $this->renderPost(11);

        $this->assertSame('', get_post_meta(11, IncludePolicy::TRUST_META, true));
        $this->assertStringNotContainsString('Included chapter text.', $html);
        $this->assertStringContainsString('{{ chapter.crv }}', $html);
    }

    public function testLosingTheCapabilityDropsExpansionOnTheNextSave(): void
    {
        $this->saveAs(self::ADMIN, 12, "{{ chapter.crv }}\n");
        $this->assertSame('1', get_post_meta(12, IncludePolicy::TRUST_META, true));

        $GLOBALS['_wpcarve_test_caps'][self::ADMIN] = [];
        $this->saveAs(self::ADMIN, 12, "{{ chapter.crv }}\n");

        $this->assertSame('', get_post_meta(12, IncludePolicy::TRUST_META, true));
        $this->assertStringNotContainsString('Included chapter text.', $this->renderPost(12));
    }

    public function testAnotherUsersSaveReEvaluatesTheBit(): void
    {
        $this->saveAs(self::ADMIN, 13, "{{ chapter.crv }}\n");
        $this->saveAs(self::AUTHOR, 13, "{{ chapter.crv }}\n");

        $this->assertStringNotContainsString('Included chapter text.', $this->renderPost(13));
    }

    public function testAMetaWriteOutsideTheSavePathIsRefused(): void
    {
        wpcarve_test_set_post(14, ['post_content' => "{{ chapter.crv }}\n"]);
        update_post_meta(14, '_wpcarve_enabled', '1');

        // What a REST `meta` payload or `meta_input` does: a plain meta write.
        $written = update_post_meta(14, IncludePolicy::TRUST_META, '1');

        $this->assertFalse($written);
        $this->assertSame('', get_post_meta(14, IncludePolicy::TRUST_META, true));
        $this->assertStringNotContainsString('Included chapter text.', $this->renderPost(14));
    }

    public function testASaveIgnoresABitAlreadyOnThePost(): void
    {
        // A bit that reached storage by some other route before the save.
        $GLOBALS['_wpcarve_test_meta'][15][IncludePolicy::TRUST_META] = '1';
        $this->saveAs(self::AUTHOR, 15, "{{ chapter.crv }}\n");

        $this->assertSame('', get_post_meta(15, IncludePolicy::TRUST_META, true));
        $this->assertStringNotContainsString('Included chapter text.', $this->renderPost(15));
    }

    public function testTraversalIsRefusedAndReported(): void
    {
        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ ../secret.crv }}\n", includes: $report);

        $this->assertStringNotContainsString('Secret outside the root.', $html);
        $this->assertStringContainsString('{{ ../secret.crv }}', $html);
        $this->assertSame(['include-unresolved'], array_column($report->warnings(), 'rule'));
    }

    public function testTheEditorSeedNeverExpands(): void
    {
        // The seed serializes back into post source, which would inline the child.
        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ chapter.crv }}\n", 'editor', includes: $report);

        $this->assertStringNotContainsString('Included chapter text.', $html);
        $this->assertSame([], $report->dependencies());
    }

    public function testCommentsNeverExpand(): void
    {
        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ chapter.crv }}\n", 'comment', includes: $report);

        $this->assertStringNotContainsString('Included chapter text.', $html);
        $this->assertSame([], $report->dependencies());
    }

    public function testSymlinkEscapeIsRefusedAndReported(): void
    {
        if (!@symlink($this->base . '/secret.crv', $this->root . '/link.crv')) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ link.crv }}\n", includes: $report);

        $this->assertStringNotContainsString('Secret outside the root.', $html);
        $this->assertSame(['include-unresolved'], array_column($report->warnings(), 'rule'));
    }

    public function testResolverDetailNeverReachesOutputOrWarnings(): void
    {
        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ ../secret.crv }}\n\n{{ missing.crv }}\n", includes: $report);
        $warnings = (string)wp_json_encode($report->warnings(), JSON_UNESCAPED_SLASHES);

        $this->assertCount(2, $report->warnings());
        $this->assertStringNotContainsString($this->base, $html);
        $this->assertStringNotContainsString($this->base, $warnings);
        // The resolver's own wording travels on the detail channel only.
        $this->assertStringNotContainsString('escapes configured root', $html . $warnings);
        $this->assertStringNotContainsString('target not found', $html . $warnings);
    }

    public function testARelativeRootIsRefusedAndReported(): void
    {
        // Relative to the working directory, where it does exist.
        $cwd = (string)getcwd();
        $relative = substr($this->root, 0, strlen($cwd) + 1) === $cwd . '/'
            ? substr($this->root, strlen($cwd) + 1)
            : str_repeat('../', substr_count(trim($cwd, '/'), '/') + 1) . ltrim($this->root, '/');
        $this->assertDirectoryExists($relative);
        $this->configure($relative);

        $report = $this->forceReport();
        $html = (new Converter(Settings::all()))->toHtml("{{ chapter.crv }}\n", includes: $report);

        $this->assertStringNotContainsString('Included chapter text.', $html);
        $this->assertSame(['include-root-refused'], array_column($report->warnings(), 'rule'));
    }

    public function testSettingsKeepTheRootVerbatim(): void
    {
        $page = new SettingsPage();
        $stored = $page->sanitize(['include_root' => ' ' . $this->root])['include_root'];

        $this->assertSame(' ' . $this->root, $stored);
    }

    public function testCacheInvalidatesWhenAnIncludedFileChanges(): void
    {
        $postId = $this->cachedTrustedPost("{{ chapter.crv }}\n");
        $this->assertStringContainsString('Included chapter text.', (string)RenderCache::read($postId, true));

        file_put_contents($this->root . '/chapter.crv', "Edited chapter text.\n");

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheInvalidatesWhenAMissingTargetAppears(): void
    {
        $postId = $this->cachedTrustedPost("{{ later.crv }}\n");
        $this->assertNotNull(RenderCache::read($postId, true));

        file_put_contents($this->root . '/later.crv', "Now it exists.\n");

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheInvalidatesWhenANestedMissingTargetAppears(): void
    {
        file_put_contents($this->root . '/sub/parent.crv', "{{ child.crv }}\n");
        $postId = $this->cachedTrustedPost("{{ sub/parent.crv }}\n");
        $this->assertNotNull(RenderCache::read($postId, true));

        file_put_contents($this->root . '/sub/child.crv', "Nested child.\n");

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheInvalidatesWhenARefusedRootAppears(): void
    {
        $this->configure($this->base . '/later-root');
        $postId = $this->cachedTrustedPost("{{ chapter.crv }}\n");
        $this->assertNotNull(RenderCache::read($postId, true));

        mkdir($this->base . '/later-root');

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheInvalidatesWhenTrustIsLost(): void
    {
        $postId = $this->cachedTrustedPost("{{ chapter.crv }}\n");
        $this->assertNotNull(RenderCache::read($postId, true));

        delete_post_meta($postId, IncludePolicy::TRUST_META);

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheInvalidatesWhenTheRootChanges(): void
    {
        $postId = $this->cachedTrustedPost("{{ chapter.crv }}\n");
        $this->assertNotNull(RenderCache::read($postId, true));

        $this->configure($this->root . '/sub');

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testCacheStoresWarningsWithoutDetail(): void
    {
        $postId = $this->cachedTrustedPost("{{ ../secret.crv }}\n");
        $stored = (string)get_post_meta($postId, RenderCache::INCLUDE_WARNINGS_KEY, true);

        $this->assertSame(['include-unresolved'], array_column((array)json_decode($stored, true), 'rule'));
        $this->assertStringNotContainsString($this->base, $stored);
    }

    public function testABlockSourceTheTrustedPostNeverSavedDoesNotExpand(): void
    {
        $saved = '<!-- wp:carve/markup ' . wp_json_encode(['carve' => "{{ chapter.crv }}\n"]) . ' /-->';
        $this->saveAs(self::ADMIN, 20, $saved);
        $block = new CarveBlock(new Converter(Settings::all()));

        $own = $block->render(['carve' => "{{ chapter.crv }}\n"]);
        $foreign = $block->render(['carve' => "Pattern.\n\n{{ chapter.crv }}\n"]);

        $this->assertStringContainsString('Included chapter text.', $own);
        $this->assertStringNotContainsString('Included chapter text.', $foreign);
    }

    /**
     * @var string
     */
    private const PREVIEW = "{{ chapter.crv }}\n\n{{ ../secret.crv }}\n";

    /**
     * @return array{html: string, include_warnings: array<int, array<string, string>>}
     */
    private function previewAs(int $userId, int $postId, string $carve = self::PREVIEW): array
    {
        $request = new WP_REST_Request();
        $request->set_param('carve', $carve);
        $request->set_param('post_id', $postId);
        $GLOBALS['_wpcarve_test_current_user'] = $userId;

        return (new RenderController(new Converter(Settings::all())))->render($request)->get_data();
    }

    private function grantEdit(int $userId, int $postId): void
    {
        $GLOBALS['_wpcarve_test_caps'][$userId]['edit_post:' . $postId] = true;
    }

    public function testRestPreviewOfASavedTrustedPostFollowsItsBit(): void
    {
        $this->saveAs(self::ADMIN, 40, self::PREVIEW);
        $this->grantEdit(self::ADMIN, 40);
        $this->grantEdit(self::AUTHOR, 40);

        $admin = $this->previewAs(self::ADMIN, 40, "Edited.\n\n{{ chapter.crv }}\n");
        $author = $this->previewAs(self::AUTHOR, 40);

        $this->assertStringContainsString('Included chapter text.', $admin['html']);
        $this->assertStringContainsString('Included chapter text.', $author['html']);
        $this->assertSame(['include-unresolved'], array_column($author['include_warnings'], 'rule'));
    }

    public function testRestPreviewOfATrustedPostExpandsASavedBlockSourceForAnAuthor(): void
    {
        $this->saveAs(self::ADMIN, 47, '<!-- wp:carve/markup ' . wp_json_encode(['carve' => self::PREVIEW]) . ' /-->');
        $this->grantEdit(self::AUTHOR, 47);

        $data = $this->previewAs(self::AUTHOR, 47);

        $this->assertStringContainsString('Included chapter text.', $data['html']);
    }

    public function testRestPreviewMatchesASavedDocumentAcrossLineEndings(): void
    {
        $this->saveAs(self::ADMIN, 48, str_replace("\n", "\r\n", self::PREVIEW));
        $this->grantEdit(self::AUTHOR, 48);

        $data = $this->previewAs(self::AUTHOR, 48);

        $this->assertStringContainsString('Included chapter text.', $data['html']);
    }

    public function testRestPreviewOfATrustedPostExpandsNoUnsavedSourceForAnAuthor(): void
    {
        $this->saveAs(self::ADMIN, 46, self::PREVIEW);
        $this->grantEdit(self::AUTHOR, 46);

        $data = $this->previewAs(self::AUTHOR, 46, "Edited.\n\n{{ chapter.crv }}\n");

        $this->assertStringNotContainsString('Included chapter text.', $data['html']);
    }

    public function testRestPreviewOfASavedUntrustedPostStaysLiteralForAnAdmin(): void
    {
        $this->saveAs(self::AUTHOR, 41, self::PREVIEW);
        $this->grantEdit(self::ADMIN, 41);

        $data = $this->previewAs(self::ADMIN, 41);

        $this->assertStringNotContainsString('Included chapter text.', $data['html']);
        $this->assertSame([], $data['include_warnings']);
    }

    public function testRestPreviewOfAnUnsavedDocumentFollowsTheCurrentUsersCapability(): void
    {
        $trusted = $this->previewAs(self::ADMIN, 0);
        $untrusted = $this->previewAs(self::AUTHOR, 0);
        $missing = $this->previewAs(self::ADMIN, 999);

        $this->assertStringContainsString('Included chapter text.', $trusted['html']);
        $this->assertSame(['include-unresolved'], array_column($trusted['include_warnings'], 'rule'));
        $this->assertStringNotContainsString($this->base, (string)wp_json_encode($trusted, JSON_UNESCAPED_SLASHES));
        $this->assertStringNotContainsString('Included chapter text.', $untrusted['html']);
        $this->assertSame([], $untrusted['include_warnings']);
        $this->assertStringContainsString('Included chapter text.', $missing['html']);
    }

    public function testRestPreviewOfAnAutoDraftFollowsTheCurrentUsersCapability(): void
    {
        $this->saveAs(self::AUTHOR, 42, '');
        $GLOBALS['_wpcarve_test_posts'][42]->post_status = 'auto-draft';
        $this->grantEdit(self::ADMIN, 42);
        $this->saveAs(self::ADMIN, 43, '');
        $GLOBALS['_wpcarve_test_posts'][43]->post_status = 'auto-draft';
        $this->grantEdit(self::AUTHOR, 43);

        $admin = $this->previewAs(self::ADMIN, 42);
        $author = $this->previewAs(self::AUTHOR, 43);

        $this->assertStringContainsString('Included chapter text.', $admin['html']);
        $this->assertStringNotContainsString('Included chapter text.', $author['html']);
    }

    public function testRestPreviewOfAPostTheUserMayNotEditRevealsNothing(): void
    {
        $this->saveAs(self::ADMIN, 44, self::PREVIEW);
        $this->saveAs(self::AUTHOR, 45, self::PREVIEW);

        $this->assertSame($this->previewAs(self::AUTHOR, 0), $this->previewAs(self::AUTHOR, 44));
        $this->assertSame($this->previewAs(self::ADMIN, 0), $this->previewAs(self::ADMIN, 45));
    }

    private function cachedTrustedPost(string $content): int
    {
        $postId = 30;
        $this->saveAs(self::ADMIN, $postId, $content);
        (new RenderCache(new Converter(Settings::all())))->onSave($postId, get_post($postId));

        return $postId;
    }

    private function forceReport(): IncludeReport
    {
        return new IncludeReport(IncludePolicy::root());
    }
}
