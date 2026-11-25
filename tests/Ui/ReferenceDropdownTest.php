<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The dependency ends are dropdowns of the document's references (x-widget: reference in the OpenAPI schema).
 */
final class ReferenceDropdownTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testEveryDependencyEndIsAReferenceDropdown(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/documents/new', 'GET'))->getContent();

        // the template of a new dependency holds the two ends
        self::assertStringContainsString('name="data[dependencies][__INDEX__][from]"', $html);
        self::assertStringContainsString('name="data[dependencies][__INDEX__][to]"', $html);
        self::assertSame(2, substr_count($html, 'data-reference-select'), 'one dropdown per end of the dependency template');
        self::assertStringNotContainsString('name="data[dependencies][__INDEX__][from]" value=', $html, 'the ends are not free text fields');
    }

    public function testAStoredValueStaysSelectedWhenItIsNoLongerInTheDocument(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/documents/new', 'GET'))->getContent();

        // the dropdown carries its stored value, so the form shows it before references.js runs
        self::assertStringContainsString('data-value=""', $html);
    }
}
