<?php

namespace App\Services\PHR\Import;

use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Reading helpers shared by the C-CDA section readers.
 *
 * The rules match the Results reader (PhrCcdaResultObservations): every text
 * that may be held by reference (ST and ED values, `text`, `originalText`)
 * resolves its narrative `<reference>` first, and no element's whole text
 * content is concatenated, so a reference-only element never reads as empty and
 * a value written twice is never stored twice.
 */
final class PhrCcdaXml
{
    private const string XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /** Narrative elements whose children are separate lines, not one run of text. */
    private const array BLOCK_ELEMENTS = ['paragraph', 'br', 'item', 'list', 'tr', 'td', 'th', 'table', 'tbody', 'thead'];

    public function __construct(public readonly DOMXPath $xpath) {}

    /**
     * Elements carrying any of the templates, anywhere in the section's
     * entries, in document order. `*` matches an element of any name.
     *
     * @param  list<string>  $templates
     * @return list<DOMElement>
     */
    public function entryElements(DOMElement $section, string $localName, array $templates): array
    {
        $roots = implode(' or ', array_map(static fn (string $root): string => '@root="'.$root.'"', $templates));
        $name = $localName === '*' ? '' : '[local-name()="'.$localName.'"]';
        $elements = [];
        foreach ($this->xpath->query('.//*[local-name()="entry"]//*'.$name.'[*[local-name()="templateId"]['.$roots.']]', $section) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Whether the element carries one of these templates itself.
     *
     * @param  list<string>  $templates
     */
    public function hasTemplate(DOMElement $element, array $templates): bool
    {
        foreach ($this->children($element, 'templateId') as $templateId) {
            if (in_array($this->attribute($templateId, 'root'), $templates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The elements this one relates directly, through its entryRelationships,
     * that carry one of the templates.
     *
     * @param  list<string>  $templates
     * @return list<DOMElement>
     */
    public function related(DOMElement $element, string $localName, array $templates): array
    {
        $roots = implode(' or ', array_map(static fn (string $root): string => '@root="'.$root.'"', $templates));
        $related = [];
        foreach ($this->xpath->query('./*[local-name()="entryRelationship"]/*[local-name()="'.$localName.'"][*[local-name()="templateId"]['.$roots.']]', $element) ?: [] as $match) {
            if ($match instanceof DOMElement) {
                $related[] = $match;
            }
        }

        return $related;
    }

    /**
     * The cells of the narrative-table row one of these elements references, as
     * the narrative-table reader takes them, or null when they reference none
     * that reader would import.
     *
     * @param  list<DOMElement|null>  $elements  elements that may hold a `<reference>`
     * @return list<string>|null
     */
    public function narrativeCells(array $elements): ?array
    {
        foreach ($elements as $element) {
            $reference = $element === null ? null : $this->attribute($this->child($element, 'reference'), 'value');
            $target = $reference !== null && str_starts_with($reference, '#') ? $this->narrativeElement(substr($reference, 1)) : null;
            if ($target === null) {
                continue;
            }

            $row = $target->localName === 'tr' ? $target : $this->first('ancestor::*[local-name()="tr"][1]', $target);
            // The rows that reader takes: those directly in a table or its tbody.
            if ($row === null || ! in_array($row->parentNode?->localName, ['tbody', 'table'], true)) {
                continue;
            }

            $cells = PhrCcdaNarrativeRow::cells($this->xpath, $row);

            return PhrCcdaNarrativeRow::isData($cells) ? $cells : null;
        }

        return null;
    }

    /**
     * Ids an earlier load may have keyed this element by: `root|extension`, and
     * the extension alone.
     *
     * @return list<string>
     */
    public function sourceIds(DOMElement $element): array
    {
        $id = $this->child($element, 'id');
        $root = $this->attribute($id, 'root');
        if ($root === null) {
            return [];
        }

        $extension = $this->attribute($id, 'extension');

        return $extension === null ? [$root.'|'] : [$root.'|'.$extension, $extension];
    }

    /**
     * The element's source id when it has one; otherwise what it says.
     *
     * @param  list<string|null>  $content
     * @return list<string>
     */
    public function identity(DOMElement $element, array $content): array
    {
        $id = $this->child($element, 'id');
        $root = $this->attribute($id, 'root');
        if ($root !== null) {
            return ['id', $root, $this->attribute($id, 'extension') ?? ''];
        }

        return ['content', ...array_map(static fn (?string $part): string => $part ?? '', $content)];
    }

    /**
     * A code's display name or original text, or a translation's display name.
     */
    public function codeName(?DOMElement $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return $this->attribute($code, 'displayName')
            ?? $this->edText($this->child($code, 'originalText'))
            ?? $this->attribute($this->child($code, 'translation'), 'displayName');
    }

    /**
     * A code that has nothing else to name it: `codeSystemName|code`, or the
     * code alone.
     */
    public function codeLabel(?DOMElement $code): ?string
    {
        $value = $this->attribute($code, 'code');
        if ($value === null) {
            return null;
        }

        $system = $this->attribute($code, 'codeSystemName');

        return $system === null ? $value : "{$system}|{$value}";
    }

    /**
     * The code in this code system: the code itself, or one of its translations.
     */
    public function codeIn(?DOMElement $code, string $codeSystem): ?string
    {
        if ($code === null) {
            return null;
        }

        foreach ([$code, ...$this->children($code, 'translation')] as $candidate) {
            if ($this->attribute($candidate, 'codeSystem') === $codeSystem && ($value = $this->attribute($candidate, 'code')) !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * An observation value as text and unit. A PQ in a unit outside UCUM is a
     * nullFlavor PQ whose number and unit live in its translation.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function value(?DOMElement $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        if ($this->type($value) === 'PQ') {
            $number = $this->attribute($value, 'value');
            if ($number !== null) {
                return [$number, $this->attribute($value, 'unit')];
            }
            $translation = $this->child($value, 'translation');

            return $translation === null
                ? [null, null]
                : [$this->attribute($translation, 'value'), $this->edText($this->child($translation, 'originalText'))];
        }

        return match ($this->type($value)) {
            'ST', 'ED' => [$this->edText($value), null],
            'CD', 'CE', 'CO', 'CV' => [$this->codeName($value) ?? $this->codeLabel($value), null],
            default => [$this->attribute($value, 'value') ?? $this->edText($value), null],
        };
    }

    /**
     * Text of an element that may hold its text by reference (ED and ST values,
     * `text`, `originalText`): the narrative it points at when it has a
     * reference, otherwise its own text.
     */
    public function edText(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $reference = $this->attribute($this->child($element, 'reference'), 'value');
        if ($reference !== null && str_starts_with($reference, '#')) {
            $target = $this->narrativeElement(substr($reference, 1));
            if ($target !== null) {
                return $this->plainText($target);
            }
        }

        return $this->plainText($element, skipReference: true);
    }

    /**
     * A person's or organization's name: its parts (prefix, given, family,
     * suffix) in document order, separated by spaces.
     */
    public function nameText(?DOMElement $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $parts = [];
        foreach ($name->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $parts[] = $this->plainText($child);
            } elseif ($child instanceof DOMText) {
                $parts[] = ValueCoercion::string($child->data);
            }
        }

        return ValueCoercion::string(implode(' ', array_filter($parts)));
    }

    /**
     * An HL7 TS (YYYYMMDD[HH[MM[SS[.S+]]]][+/-ZZZZ]) as an ISO 8601 string.
     */
    public function timestamp(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})?(\d{2})?(?:\.\d+)?)?([+-]\d{4})?$/', $value, $m) !== 1) {
            return null;
        }

        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        if (($m[4] ?? '') === '') {
            return $date;
        }

        $offset = ($m[7] ?? '') === '' ? '' : substr($m[7], 0, 3).':'.substr($m[7], 3);

        return sprintf('%sT%s:%s:%s%s', $date, $m[4], ($m[5] ?? '') ?: '00', ($m[6] ?? '') ?: '00', $offset);
    }

    /**
     * Whether a TS carries a time of day, not only a date.
     */
    public function hasTime(?string $iso): bool
    {
        return $iso !== null && str_contains($iso, 'T');
    }

    /**
     * The start and end of an effectiveTime: its value, or its low and high.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function period(?DOMElement $effectiveTime): array
    {
        if ($effectiveTime === null) {
            return [null, null];
        }

        $value = $this->timestamp($this->attribute($effectiveTime, 'value'));
        if ($value !== null) {
            return [$value, null];
        }

        return [
            $this->timestamp($this->attribute($this->child($effectiveTime, 'low'), 'value')),
            $this->timestamp($this->attribute($this->child($effectiveTime, 'high'), 'value')),
        ];
    }

    /**
     * The `xsi:type` without its prefix, upper-cased ("PQ").
     */
    public function type(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $type = $element->getAttributeNS(self::XSI, 'type');
        if ($type === '') {
            $type = $element->getAttribute('xsi:type');
        }
        // A prefixed type ("v3:PQ") names the same datatype.
        $type = substr((string) strrchr(':'.$type, ':'), 1);

        return $type === '' ? null : strtoupper($type);
    }

    public function attribute(?DOMElement $element, string $name): ?string
    {
        if ($element === null || ! $element->hasAttribute($name)) {
            return null;
        }

        return ValueCoercion::string($element->getAttribute($name));
    }

    public function child(?DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($this->children($parent, $localName) as $child) {
            return $child;
        }

        return null;
    }

    /**
     * The element at the end of a path of child names, each the first of its name.
     */
    public function path(?DOMElement $element, string ...$localNames): ?DOMElement
    {
        foreach ($localNames as $localName) {
            $element = $this->child($element, $localName);
        }

        return $element;
    }

    /**
     * @return list<DOMElement>
     */
    public function children(?DOMElement $parent, string $localName): array
    {
        if ($parent === null) {
            return [];
        }

        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $children[] = $child;
            }
        }

        return $children;
    }

    public function first(string $query, ?DOMNode $context = null): ?DOMElement
    {
        $matches = $this->xpath->query($query, $context);
        $match = $matches === false ? null : $matches->item(0);

        return $match instanceof DOMElement ? $match : null;
    }

    public function narrativeElement(string $id): ?DOMElement
    {
        if ($id === '' || str_contains($id, '"')) {
            return null;
        }

        return $this->first('//*[@ID="'.$id.'"]');
    }

    /**
     * Text of one element with block-level children kept apart, whitespace
     * collapsed, and an empty result as null.
     */
    private function plainText(DOMNode $node, bool $skipReference = false): ?string
    {
        $parts = [];
        $this->collectText($node, $parts, $skipReference);
        $text = preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '';

        return ValueCoercion::string($text);
    }

    /**
     * @param  list<string>  $parts
     */
    private function collectText(DOMNode $node, array &$parts, bool $skipReference): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $parts[] = $child->data;
            } elseif ($child instanceof DOMElement) {
                if ($skipReference && $child->localName === 'reference') {
                    continue;
                }
                $block = in_array($child->localName, self::BLOCK_ELEMENTS, true);
                if ($block) {
                    $parts[] = ' ';
                }
                $this->collectText($child, $parts, $skipReference);
                if ($block) {
                    $parts[] = ' ';
                }
            }
        }
    }
}
