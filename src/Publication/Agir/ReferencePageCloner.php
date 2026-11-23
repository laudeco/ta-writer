<?php

declare(strict_types=1);

namespace App\Publication\Agir;

use App\Publication\Confluence\ConfluenceClientInterface;

/**
 * Epic and technical-story pages of AGIR start from a reference page body: the cells of the details table are replaced
 * by label (the `<th>` text), everything else of the page is kept. Missing optional labels are added as a row.
 */
final class ReferencePageCloner
{
    private const NS = 'xmlns:ac="urn:ac" xmlns:ri="urn:ri"';

    public function __construct(private readonly ConfluenceClientInterface $client)
    {
    }

    /**
     * @throws \RuntimeException when the page cannot be read
     */
    public function fetchBody(string $pageId): string
    {
        $response = $this->client->request('GET', sprintf('/rest/api/content/%s?expand=body.storage', $pageId));
        if (!$response->ok() || !isset($response->body['body']['storage']['value'])) {
            throw new \RuntimeException(sprintf('Reference page %s cannot be read (HTTP %d).', $pageId, $response->status));
        }

        return (string) $response->body['body']['storage']['value'];
    }

    /**
     * @param array<string, string> $cells    label => XHTML of the cell
     * @param list<string>          $optional labels added as a new row when the reference page does not have them
     *
     * @throws \RuntimeException when a required label is missing or the storage is not well-formed
     */
    public function apply(string $storage, array $cells, array $optional = []): string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML(sprintf('<root %s>%s</root>', self::NS, str_replace('&nbsp;', '&#160;', $storage)));
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException('Reference page storage is not well-formed: '.($errors[0]->message ?? 'unknown error'));
        }

        $rows = [];
        foreach ($dom->getElementsByTagName('tr') as $row) {
            $th = $this->firstChild($row, 'th');
            if ($th !== null) {
                $rows[trim($th->textContent)] = $row;
            }
        }

        foreach ($cells as $label => $value) {
            if (!isset($rows[$label])) {
                if (!\in_array($label, $optional, true)) {
                    throw new \RuntimeException(sprintf('Label "%s" not found in the reference page.', $label));
                }
                $this->appendRow($dom, $label, $value);
                continue;
            }
            $td = $this->firstChild($rows[$label], 'td');
            if ($td === null) {
                throw new \RuntimeException(sprintf('Label "%s" has no value cell in the reference page.', $label));
            }
            $this->replaceContent($dom, $td, $value);
        }

        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveXML($child);
        }

        return (string) preg_replace('/\s+xmlns:(ac|ri)="[^"]*"/', '', $out);
    }

    private function firstChild(\DOMNode $node, string $name): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private function replaceContent(\DOMDocument $dom, \DOMElement $cell, string $xhtml): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        foreach ($this->fragment($dom, $xhtml)->childNodes as $child) {
            $cell->appendChild($dom->importNode($child, true));
        }
    }

    private function appendRow(\DOMDocument $dom, string $label, string $value): void
    {
        $table = $dom->getElementsByTagName('table')->item(0);
        if ($table === null) {
            throw new \RuntimeException('The reference page has no details table.');
        }
        $tbody = $table->getElementsByTagName('tbody')->item(0) ?? $table;
        $row = $dom->createElement('tr');
        $th = $dom->createElement('th');
        $th->appendChild($dom->createTextNode($label));
        $td = $dom->createElement('td');
        $row->appendChild($th);
        $row->appendChild($td);
        $tbody->appendChild($row);
        $this->replaceContent($dom, $td, $value);
    }

    private function fragment(\DOMDocument $dom, string $xhtml): \DOMElement
    {
        $wrapper = new \DOMDocument();
        $wrapper->loadXML(sprintf('<x %s>%s</x>', self::NS, str_replace('&nbsp;', '&#160;', $xhtml)));

        return $wrapper->documentElement;
    }
}
