<?php

declare(strict_types=1);

namespace App\Jira;

use App\Render\ContentConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Jira Data Center wiki markup (API v2, not ADF) from the Markdown of the document. The Markdown is parsed (GFM), then its
 * tree is written: headings, lists (nested, numbered, with [ ] / [x] items), tables, code, quotes, links and images. Text is
 * escaped where Jira would read it as markup; a text-format value is only escaped, never parsed.
 */
final class MarkdownToJiraWiki
{
    private MarkdownParser $parser;

    public function __construct(private readonly ContentConverter $converter)
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $this->parser = new MarkdownParser($environment);
    }

    /**
     * The wiki markup of a rich value, whatever the format of the document.
     */
    public function fromFormat(string $content, string $format): string
    {
        if (trim($content) === '') {
            return '';
        }
        if ($format === 'text') {
            return implode("\n\n", array_map(fn (string $line): string => $this->escape(trim($line)), preg_split('/\R{2,}/', trim($content)) ?: []));
        }

        return $this->convert($format === 'html' ? $this->converter->toMarkdown($content, 'html') : $content);
    }

    public function convert(string $markdown): string
    {
        $document = $this->parser->parse($markdown);

        return trim($this->blocks($document, 0));
    }

    /**
     * The blocks inside a container (the document, a quote, a list item).
     */
    private function blocks(Node $parent, int $depth): string
    {
        $out = '';
        foreach ($parent->children() as $node) {
            $out .= $this->block($node, $depth);
        }

        return $out;
    }

    private function block(Node $node, int $depth): string
    {
        return match (true) {
            $node instanceof Heading => sprintf("h%d. %s\n\n", min(6, $node->getLevel()), $this->inlines($node)),
            $node instanceof Paragraph => preg_replace('/ {2,}/', ' ', $this->inlines($node))."\n\n",
            $node instanceof FencedCode => $this->code($node->getInfo() ?? '', $node->getLiteral()),
            $node instanceof IndentedCode => $this->code('', $node->getLiteral()),
            $node instanceof BlockQuote => "{quote}\n".trim($this->blocks($node, $depth))."\n{quote}\n\n",
            $node instanceof ThematicBreak => "----\n\n",
            $node instanceof HtmlBlock => $this->escape(trim($node->getLiteral()))."\n\n",
            // a top-level list ends with a blank line; a nested one is followed by the next item of its parent
            $node instanceof ListBlock => $this->list($node, $depth).($depth === 0 ? "\n" : ''),
            $node instanceof Table => $this->table($node)."\n\n",
            default => $this->blocks($node, $depth),
        };
    }

    private function list(ListBlock $list, int $depth): string
    {
        $marker = $list->getListData()->type === ListBlock::TYPE_ORDERED ? '#' : '*';
        $out = '';
        foreach ($list->children() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }
            $prefix = str_repeat($marker, $depth + 1).' ';
            $first = true;
            foreach ($item->children() as $child) {
                if ($child instanceof ListBlock) {
                    $out .= $this->list($child, $depth + 1);
                    continue;
                }
                $text = trim($this->block($child, $depth + 1));
                $out .= ($first ? $prefix : str_repeat(' ', $depth + 1).' ').$text."\n";
                $first = false;
            }
            if ($first) {
                $out .= $prefix."\n";
            }
        }

        return $out;
    }

    private function table(Table $table): string
    {
        $out = '';
        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }
            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                $header = false;
                foreach ($row->children() as $cell) {
                    if ($cell instanceof TableCell) {
                        $header = $header || $cell->getType() === TableCell::TYPE_HEADER;
                        $cells[] = trim($this->inlines($cell));
                    }
                }
                $out .= ($header ? '||'.implode('||', $cells).'||' : '|'.implode('|', $cells).'|')."\n";
            }
        }

        return trim($out);
    }

    private function code(string $language, string $literal): string
    {
        $tag = $language !== '' ? '{code:'.preg_replace('/[^A-Za-z0-9_+#-]/', '', strtok($language, ' ') ?: '').'}' : '{code}';

        return $tag."\n".rtrim($literal, "\n")."\n{code}\n\n";
    }

    private function inlines(Node $parent): string
    {
        $out = '';
        foreach ($parent->children() as $node) {
            $out .= match (true) {
                $node instanceof Text => $this->escape($node->getLiteral()),
                $node instanceof Strong => '*'.$this->inlines($node).'*',
                $node instanceof Emphasis => '_'.$this->inlines($node).'_',
                $node instanceof Strikethrough => '-'.$this->inlines($node).'-',
                $node instanceof Code => '{{'.$node->getLiteral().'}}',
                $node instanceof Link => $this->link($node),
                $node instanceof Image => '!'.$node->getUrl().'!',
                $node instanceof Newline => $node->getType() === Newline::HARDBREAK ? "\\\\\n" : ' ',
                // [ ] would read as a Jira link: the box is drawn with characters instead
                $node instanceof TaskListItemMarker => $node->isChecked() ? '☑' : '☐',
                $node instanceof HtmlInline => $this->escape($node->getLiteral()),
                default => $this->inlines($node),
            };
        }

        return $out;
    }

    private function link(Link $link): string
    {
        $text = $this->inlines($link);

        return $text === '' || $text === $link->getUrl() ? '['.$link->getUrl().']' : '['.$text.'|'.$link->getUrl().']';
    }

    /**
     * Characters Jira reads as markup, escaped with a backslash.
     */
    private function escape(string $text): string
    {
        return str_replace(['\\', '{', '}', '[', ']', '|'], ['\\\\', '\\{', '\\}', '\\[', '\\]', '\\|'], $text);
    }
}
