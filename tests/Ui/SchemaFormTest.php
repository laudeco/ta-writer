<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Render\ContentConverter;
use App\Schema\SchemaProvider;
use App\Ui\Form\FieldNode;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use PHPUnit\Framework\TestCase;

final class SchemaFormTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../config/schema/document.openapi.yaml';

    private function child(FieldNode $node, string $name): FieldNode
    {
        foreach ($node->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }
        self::fail(sprintf('no field "%s" in the form', $name));
    }

    public function testWidgetsComeFromTheSchema(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(self::SCHEMA)))->build();

        self::assertSame('select', $this->child($root, 'format')->widget);
        self::assertSame(['html', 'text', 'markdown'], $this->child($root, 'format')->options);
        self::assertSame('url', $this->child($root, 'dependenciesPageLink')->widget);
        $epic = $this->child($root, 'epics')->item;
        self::assertSame('richtext', $this->child($epic, 'what')->widget, 'long text fields are Quill editors');
        self::assertSame('integer', $this->child($this->child($this->child($this->child($epic, 'technicalStories')->item, 'subtasks')->item, 'sqlImpact'), 'nbRows')->widget);
    }

    public function testAPropertyAddedToTheYamlAppearsWithoutCode(): void
    {
        $file = sys_get_temp_dir().'/ta-form-'.bin2hex(random_bytes(3)).'.yaml';
        $yaml = (string) file_get_contents(self::SCHEMA);
        $yaml = str_replace("          description: What the epic is about.\n", "          description: What the epic is about.\n        impact:\n          type: string\n          description: Impact of the epic.\n", $yaml);
        file_put_contents($file, $yaml);
        try {
            $root = (new SchemaFormBuilder(new SchemaProvider($file)))->build();
            $impact = $this->child($this->child($root, 'epics')->item, 'impact');
            self::assertSame('text', $impact->widget);
            self::assertSame('Impact of the epic.', $impact->description);
        } finally {
            unlink($file);
        }
    }

    public function testSubmittedFormBecomesAClearDocument(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(self::SCHEMA)))->build();
        $submitted = [
            'format' => 'markdown',
            'project' => ['name' => '  Demo  ', 'reference' => 'DEMO1'],
            'author' => ['name' => 'T', 'email' => 't@example.com', 'username' => ''],
            'epics' => [
                ['title' => 'E', 'reference' => '', 'what' => '', 'resources' => [['type' => 'note', 'content' => 'x']]],
                ['title' => '', 'reference' => '', 'what' => ''],
            ],
            'dependencies' => [['from' => '', 'to' => '']],
        ];
        $out = (new FormDataNormalizer(new ContentConverter()))->normalize($submitted, $root, 'markdown');

        self::assertSame('Demo', $out['project']['name']);
        self::assertArrayNotHasKey('username', $out['author']);
        self::assertSame('E', $out['epics'][0]['title']);
        self::assertSame([['type' => 'note', 'content' => 'x']], $out['epics'][0]['resources']);
        self::assertArrayNotHasKey('what', $out['epics'][0], 'an empty optional text is dropped');
        self::assertCount(1, $out['epics'], 'a blank item is dropped');
        self::assertArrayNotHasKey('dependenciesPageLink', $out);
    }

    public function testIntegersAreTyped(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(self::SCHEMA)))->build();
        $sub = ['title' => 's', 'sqlImpact' => ['nbColumns' => '3', 'nbRows' => ' 12 ']];
        $submitted = ['epics' => [['title' => 'e', 'technicalStories' => [['title' => 't', 'subtasks' => [$sub]]]]]];
        $out = (new FormDataNormalizer(new ContentConverter()))->normalize($submitted, $root, 'markdown');

        self::assertSame(['nbColumns' => 3, 'nbRows' => 12], $out['epics'][0]['technicalStories'][0]['subtasks'][0]['sqlImpact']);
    }
}
