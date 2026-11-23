<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

use App\Render\ContentConverter;

/**
 * Confluence storage format (XHTML with ac: macros) for the text of the analysis: markdown paragraphs, fenced code
 * blocks as code macros, mermaid blocks as attached images, `- [ ]` lines as task lists.
 */
final class StorageFormatBuilder
{
    public function __construct(private readonly ContentConverter $converter)
    {
    }

    /**
     * @param array<string, string> $diagramFiles mermaid source => attached file name (diagrams already uploaded)
     */
    public function content(string $content, string $format, array $diagramFiles = []): string
    {
        $markdown = $this->converter->toMarkdown($content, $format);
        if (trim($markdown) === '') {
            return '';
        }

        $out = '';
        $text = [];
        $tasks = [];
        $taskId = 0;
        $lines = preg_split('/\R/', $markdown) ?: [];
        for ($i = 0, $n = \count($lines); $i < $n; ++$i) {
            $line = $lines[$i];
            if (preg_match('/^```(\S*)\s*$/', $line, $open)) {
                $body = [];
                for ($j = $i + 1; $j < $n && !preg_match('/^```\s*$/', $lines[$j]); ++$j) {
                    $body[] = $lines[$j];
                }
                $out .= $this->flush($text);
                $out .= $this->flushTasks($tasks);
                $out .= $this->codeBlock($open[1], implode("\n", $body), $diagramFiles);
                $i = $j;

                continue;
            }
            if (preg_match('/^\s*- \[( |x|X)\] (.*)$/', $line, $task)) {
                $out .= $this->flush($text);
                $tasks[] = ['done' => strtolower($task[1]) === 'x', 'text' => $task[2]];

                continue;
            }
            $out .= $this->flushTasks($tasks);
            $text[] = $line;
        }
        $out .= $this->flush($text).$this->flushTasks($tasks);

        return $out;
    }

    /**
     * @param list<string> $text
     */
    private function flush(array &$text): string
    {
        if (trim(implode("\n", $text)) === '') {
            $text = [];

            return '';
        }
        $html = $this->converter->toHtml(implode("\n", $text), 'markdown');
        $text = [];

        return $html;
    }

    /**
     * @param list<array{done: bool, text: string}> $tasks
     */
    private function flushTasks(array &$tasks): string
    {
        if ($tasks === []) {
            return '';
        }
        $items = '';
        foreach ($tasks as $index => $task) {
            $items .= sprintf(
                '<ac:task><ac:task-id>%d</ac:task-id><ac:task-status>%s</ac:task-status><ac:task-body>%s</ac:task-body></ac:task>',
                $index + 1,
                $task['done'] ? 'complete' : 'incomplete',
                $this->inline($task['text']),
            );
        }
        $tasks = [];

        return '<ac:task-list>'.$items.'</ac:task-list>';
    }

    /**
     * @param array<string, string> $diagramFiles
     */
    private function codeBlock(string $language, string $code, array $diagramFiles): string
    {
        if (strtolower($language) === 'mermaid') {
            $file = $diagramFiles[rtrim($code)] ?? null;

            return $file === null
                ? '<p><em>Diagram not attached</em></p>'
                : sprintf('<p><ac:image ac:align="center"><ri:attachment ri:filename="%s" /></ac:image></p>', $this->attr($file));
        }

        $language = $language === '' ? 'none' : strtolower($language);

        return sprintf(
            '<ac:structured-macro ac:name="code" ac:schema-version="1"><ac:parameter ac:name="language">%s</ac:parameter>'
            .'<ac:plain-text-body><![CDATA[%s]]></ac:plain-text-body></ac:structured-macro>',
            $this->attr($language),
            str_replace(']]>', ']]]]><![CDATA[>', $code),
        );
    }

    private function inline(string $text): string
    {
        return trim(strip_tags($this->converter->toHtml($text, 'markdown'))) ?: htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
