<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;

class ProfileTableTest extends TestCase
{
    public function testPublishedTableMatchesCurrentConverterAndProfiles(): void
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/scripts/generate-profile-table.php', '--check'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output . $errors);
    }
}
