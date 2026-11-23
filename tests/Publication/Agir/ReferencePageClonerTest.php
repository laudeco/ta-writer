<?php

declare(strict_types=1);

namespace App\Tests\Publication\Agir;

use App\Publication\Agir\ReferencePageCloner;
use App\Publication\Confluence\ConfluenceResponse;
use App\Tests\Publication\Confluence\FakeConfluenceClient;
use PHPUnit\Framework\TestCase;

final class ReferencePageClonerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/'.$name);
    }

    private function cloner(): ReferencePageCloner
    {
        return new ReferencePageCloner(new FakeConfluenceClient(static fn (): ConfluenceResponse => new ConfluenceResponse(404, null)));
    }

    private function cell(string $storage, string $label): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML('<root xmlns:ac="urn:ac" xmlns:ri="urn:ri">'.$storage.'</root>');
        foreach ($dom->getElementsByTagName('tr') as $row) {
            $th = $row->getElementsByTagName('th')->item(0);
            if ($th !== null && trim($th->textContent) === $label) {
                return trim($row->getElementsByTagName('td')->item(0)->textContent);
            }
        }

        return '<missing>';
    }

    public function testCellsAreReplacedByLabelAndTheRestIsKept(): void
    {
        $out = $this->cloner()->apply($this->fixture('agir-reference-ts.xhtml'), ['E US / AC' => 'E1 TS9', 'Feature Toggle' => 'demo_toggle']);

        self::assertSame('E1 TS9', $this->cell($out, 'E US / AC'));
        self::assertSame('demo_toggle', $this->cell($out, 'Feature Toggle'));
        self::assertStringContainsString('ac:structured-macro', $out, 'the jira macros of the reference stay');
        self::assertStringContainsString('Story Jira', $out);
    }

    public function testMissingRequiredLabelStopsTheRun(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Label "Nope" not found');
        $this->cloner()->apply($this->fixture('agir-reference-ts.xhtml'), ['Nope' => 'x']);
    }

    public function testOptionalLabelIsAddedAsARow(): void
    {
        $out = $this->cloner()->apply($this->fixture('agir-reference-epic.xhtml'), ['Context' => 'Added.'], ['Context']);

        self::assertSame('Added.', $this->cell($out, 'Context'));
    }

    public function testNotWellFormedStorageIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cloner()->apply('<table><tr><th>x</td></table>', []);
    }
}
