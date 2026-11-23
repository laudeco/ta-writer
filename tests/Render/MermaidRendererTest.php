<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Render\Mermaid\MermaidRenderer;
use PHPUnit\Framework\TestCase;

final class MermaidRendererTest extends TestCase
{
    public function testMissingBinaryIsReportedNotThrown(): void
    {
        self::assertFalse((new MermaidRenderer(sys_get_temp_dir(), 'ta-writer-no-such-mmdc'))->available());
    }

    public function testCachedDiagramIsReturnedWithoutRendering(): void
    {
        $cache = sys_get_temp_dir().'/ta-mermaid-'.bin2hex(random_bytes(3));
        mkdir($cache);
        $source = "classDiagram\n    class Cached";
        $png = $cache.'/'.hash('sha256', $source).'.png';
        file_put_contents($png, 'png-bytes');
        try {
            // the binary does not exist: a cache hit must not call it
            self::assertSame($png, (new MermaidRenderer($cache, 'ta-writer-no-such-mmdc'))->render($source));
        } finally {
            unlink($png);
            rmdir($cache);
        }
    }
}
