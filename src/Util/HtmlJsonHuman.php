<?php

declare(strict_types=1);

namespace Packeton\Util;

class HtmlJsonHuman
{
    public function buildToHtml($object)
    {
        $dom = $this->buildDom($object);
        if ($dom->firstChild instanceof \DOMElement) {
            $class = $dom->firstChild->getAttribute('class') ?: '';
            $dom->firstChild->setAttribute('class', $class . ' jh-root');
        }

        return $dom->saveHTML();
    }

    public function buildDom($object, \DOMDocument $root = null, \DOMElement $parent = null)
    {
        if (null === $root) {
            $root = new \DOMDocument('1.0');
        }

        $type = $this->getType($object);
        switch ($type) {
            case 'boolean':
                $div = $root->createElement('div');
                $span = $root->createElement('span');
                $span->setAttribute('class', $object ? 'jh-type-bool-true' : 'jh-type-bool-false');
                $span->nodeValue = $object ? 'true' : 'false';
                $div->appendChild($span);
                $parent ? $parent->appendChild($div) : $root->appendChild($div);
                break;
            case 'string':
                $span = $root->createElement('span');
                $span->setAttribute('class', $object ? 'jh-type-string' : 'jh-type-string jh-empty');
                $span->nodeValue = $object ? : '(Empty Text)';
                $parent ? $parent->appendChild($span) : $root->appendChild($span);
                break;
            case 'integer':
            case 'double':
                $span = $root->createElement('span');
                $span->setAttribute('class', $type === 'double' ? 'jh-type-float jh-type-number' : 'jh-type-int jh-type-number');
                $span->nodeValue = (string)$object;
                $parent ? $parent->appendChild($span) : $root->appendChild($span);
                break;
            case 'array':
            case 'object':
                $childs = [];
                foreach ($object as $key => $value) {
                    $keyNode = $root->createElement('th');
                    $keyNode->nodeValue = (string)$key;
                    $keyNode->setAttribute('class', "jh-key jh-$type-key");

                    $valueNode = $root->createElement('td');
                    $valueNode->setAttribute('class', "jh-value jh-$type-value");
                    $this->buildDom($value, $root, $valueNode);

                    $trNode = $root->createElement('tr');
                    $trNode->appendChild($keyNode);
                    $trNode->appendChild($valueNode);
                    $childs[] = $trNode;
                }

                if (empty($childs)) {
                    $resultNode = $root->createElement('span');
                    $resultNode->nodeValue = $type === 'object' ? '(Empty Object)' : '(Empty List)';
                    $resultNode->setAttribute('class', "jh-type-$type jh-empty");
                } else {
                    $resultNode = $root->createElement('table');
                    $resultNode->setAttribute('class', "jh-type-$type");

                    $tbody = $root->createElement('tbody');
                    $resultNode->appendChild($tbody);
                    foreach ($childs as $child) {
                        $tbody->appendChild($child);
                    }
                }

                $parent ? $parent->appendChild($resultNode) : $root->appendChild($resultNode);
                break;
            default:
                $span = $root->createElement('span');
                $span->setAttribute('class', 'jh-type-unk');
                $span->nodeValue = 'null';
                $parent ? $parent->appendChild($span) : $root->appendChild($span);
                break;
        }

        return $root;
    }

    private function getType($object): string
    {
        if (\is_object($object)) {
            return 'entity';
        }
        if (\is_array($object)) {
            return \array_key_exists(0, $object) || empty($object) ? 'array': 'object';
        }

        return \gettype($object);
    }
}
