<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

/**
 * Bodies of the generated pages, built from the document model: epic, technical story, subtask (the layout of the
 * AGIR Sub-Task template: details table in a left sidebar, Check / Why / Info panels), dependencies.
 */
final class PageBodyBuilder
{
    public function __construct(private readonly StorageFormatBuilder $storage)
    {
    }

    /**
     * @param array<string, mixed>  $epic   model node
     * @param array<string, string> $files  diagram source => attachment file name
     */
    public function epic(array $epic, string $format, array $files): string
    {
        $out = $this->table([
            'Epic Jira' => $this->jiraMacro($epic['ticket']),
            'Technical stories' => (string) count($epic['technicalStories']),
        ]);
        $out .= $this->section('Context', $this->storage->content($epic['what'], $format, $files));
        $out .= $this->resources($epic['resources'], $format);
        $out .= $this->diagrams($epic['diagrams'], $files);
        foreach ($epic['userStories'] as $us) {
            $out .= sprintf('<h2>%s</h2>', $this->e($us['displayTitle']));
            $out .= '<ul>'.implode('', array_map(fn (string $ac): string => sprintf('<li>%s</li>', $this->e($ac)), $us['acceptanceCriteria'])).'</ul>';
            $out .= $this->resources($us['resources'], $format);
            $out .= $this->diagrams($us['diagrams'], $files);
        }
        $out .= '<h2>Technical stories</h2><ac:structured-macro ac:name="children" ac:schema-version="2"><ac:parameter ac:name="depth">1</ac:parameter></ac:structured-macro>';

        return $out;
    }

    /**
     * @param array<string, mixed>  $ts    model node
     * @param array<string, string> $files
     */
    public function technicalStory(array $ts, string $format, array $files, ?string $epicTicket): string
    {
        $criteria = $ts['acceptanceCriteriaCovered'];
        $out = $this->layout(
            $this->table([
                'Epic Jira' => $this->jiraMacro($epicTicket),
                'Story Jira' => $this->jiraMacro($ts['ticket']),
                'E US / AC' => $this->e(implode(', ', $criteria) ?: '-'),
                'Feature Toggle' => $this->e(implode(', ', $ts['featureToggles']) ?: '-'),
                'Context' => $this->e($this->firstSentence($ts['what'])),
            ]),
            '',
        );
        $out .= $this->section('What', $this->storage->content($ts['what'], $format, $files));
        $out .= $this->section('Why', $this->storage->content($ts['why'], $format, $files));
        $out .= '<h2>Acceptance criteria covered</h2><ul>'.implode('', array_map(fn (string $ac): string => sprintf('<li>%s</li>', $this->e($ac)), $criteria)).'</ul>';
        $out .= $this->resources($ts['resources'], $format);
        $out .= $this->diagrams($ts['diagrams'], $files);
        $out .= '<h2>Sub-Tasks</h2><ac:structured-macro ac:name="children" ac:schema-version="2"><ac:parameter ac:name="depth">1</ac:parameter></ac:structured-macro>';

        return $out;
    }

    /**
     * @param array<string, mixed>  $sub     model node
     * @param array<string, string> $files
     */
    public function subtask(array $sub, string $format, array $files, string $tsTitle, ?string $tsTicket): string
    {
        $sql = $sub['sqlImpact'] === null
            ? ['SQL rows' => '-', 'SQL records' => '-']
            : ['SQL rows' => (string) $sub['sqlImpact']['nbRows'], 'SQL records' => (string) $sub['sqlImpact']['nbColumns']];

        $details = $this->table([
            'Name' => $this->e($sub['displayTitle']),
            'TBL' => sprintf('<ac:link><ri:page ri:content-title="%s" /></ac:link>', $this->e($tsTitle)),
            'Jira parent' => $this->jiraMacro($tsTicket),
            'Jira issue' => $this->jiraMacro($sub['ticket']),
            'Context' => $this->e($this->firstSentence($sub['why'])),
            'Feature Toggle' => $this->e((string) ($sub['featureToggle'] ?? '-')),
        ] + $sql);

        $check = $this->storage->content($sub['what'], $format, $files);
        $why = trim($sub['why']) === '' ? '' : $this->panel('darkorange', 'Why', $this->storage->content($sub['why'], $format, $files));
        $info = $this->storage->content(trim($sub['where']."\n\n".$sub['how']), $format, $files).$this->diagrams($sub['diagrams'], $files).$this->resources($sub['resources'], $format);

        return '<ac:layout><ac:layout-section ac:type="two_left_sidebar"><ac:layout-cell>'
            .'<ac:structured-macro ac:name="details" ac:schema-version="1"><ac:rich-text-body>'.$details.'</ac:rich-text-body></ac:structured-macro>'
            .'</ac:layout-cell><ac:layout-cell>'
            .$this->panel('darkgreen', 'Check', $check !== '' ? $check : '<p>-</p>')
            .$why
            .$this->panel('darkblue', 'Info', $info !== '' ? $info : '<p>-</p>')
            .'</ac:layout-cell></ac:layout-section></ac:layout>';
    }

    /**
     * @param list<array<string, mixed>> $dependencies model rows (fromTitle, toTitle, fromLink, toLink)
     */
    public function dependencies(array $dependencies): string
    {
        if ($dependencies === []) {
            return '<p><em>No dependency.</em></p>';
        }
        $items = '';
        foreach ($dependencies as $dep) {
            $items .= sprintf('<li>%s is blocked by %s</li>', $this->link($dep['toTitle'], $dep['toLink']), $this->link($dep['fromTitle'], $dep['fromLink']));
        }

        return '<ul>'.$items.'</ul>';
    }

    /**
     * @param array<string, string> $rows label => XHTML value
     */
    private function table(array $rows): string
    {
        $out = '<table class="wrapped"><colgroup><col /><col /></colgroup><tbody>';
        foreach ($rows as $label => $value) {
            $out .= sprintf('<tr><th scope="row">%s</th><td>%s</td></tr>', $this->e((string) $label), $value);
        }

        return $out.'</tbody></table>';
    }

    private function layout(string $details, string $main): string
    {
        return '<ac:layout><ac:layout-section ac:type="two_left_sidebar"><ac:layout-cell>'
            .'<ac:structured-macro ac:name="details" ac:schema-version="1"><ac:rich-text-body>'.$details.'</ac:rich-text-body></ac:structured-macro>'
            .'</ac:layout-cell><ac:layout-cell>'.$main.'</ac:layout-cell></ac:layout-section></ac:layout>';
    }

    private function panel(string $color, string $title, string $content): string
    {
        return sprintf(
            '<ac:structured-macro ac:name="panel" ac:schema-version="1"><ac:parameter ac:name="borderColor">%s</ac:parameter>'
            .'<ac:parameter ac:name="titleBGColor">%s</ac:parameter><ac:parameter ac:name="titleColor">white</ac:parameter>'
            .'<ac:parameter ac:name="title">%s</ac:parameter><ac:rich-text-body>%s</ac:rich-text-body></ac:structured-macro>',
            $color, $color, $title, $content,
        );
    }

    private function section(string $heading, string $content): string
    {
        return $content === '' ? '' : sprintf('<h2>%s</h2>%s', $this->e($heading), $content);
    }

    /**
     * @param list<array{type: string, content: string}> $resources
     */
    private function resources(array $resources, string $format): string
    {
        if ($resources === []) {
            return '';
        }
        $items = '';
        foreach ($resources as $resource) {
            $items .= sprintf('<li>(%s) %s</li>', $this->e($resource['type']), $this->e($resource['content']));
        }

        return '<h2>Resources / Target Impact</h2><ul>'.$items.'</ul>';
    }

    /**
     * @param list<array{type: string, content: string}> $diagrams
     * @param array<string, string>                     $files
     */
    private function diagrams(array $diagrams, array $files): string
    {
        $out = '';
        foreach ($diagrams as $diagram) {
            $out .= $this->storage->content("```mermaid\n".$diagram['content']."\n```", 'markdown', $files);
        }

        return $out;
    }

    private function jiraMacro(?string $key): string
    {
        if ($key === null || $key === '') {
            return '-';
        }

        return sprintf('<ac:structured-macro ac:name="jira" ac:schema-version="1"><ac:parameter ac:name="key">%s</ac:parameter></ac:structured-macro>', $this->e($key));
    }

    private function link(string $title, ?string $url): string
    {
        return $url === null || $url === '' ? $this->e($title) : sprintf('<a href="%s">%s</a>', $this->e($url), $this->e($title));
    }

    private function firstSentence(string $text): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $text));
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $flat, $m)) {
            return $m[1];
        }

        return $flat;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
