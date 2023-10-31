<?php

declare(strict_types=1);

namespace Packeton\Webhook\Twig;

class PlaceholderContext
{
    /**
     * @var array
     */
    private $placeholders = [];

    /**
     * @param string $name
     * @param array $variables
     */
    public function setPlaceholder(string $name, array $variables): void
    {
        $this->placeholders[$name] = array_values($variables);
    }

    /**
     * @return array
     */
    public function getPlaceholders()
    {
        return $this->placeholders;
    }

    public function walkContent(array $content): iterable
    {
        if (empty($this->placeholders)) {
            return [$content];
        }

        $stack = [];
        $count = max(array_map(fn($p) => count($p), $this->placeholders));
        for ($idx = 0; $idx < $count; $idx++) {
            $copy = $content;

            $walker = function(&$value) use ($idx) {
                if (!is_string($value)) {
                    return $value;
                }
                foreach ($this->placeholders as $name => $placeholders) {
                    $replacement = $placeholders[$idx] ?? $placeholders[0];
                    $var = "{{ $name }}";
                    if ($value === $var) {
                        return $replacement;
                    }
                    $value = preg_replace('/{{\s*' . preg_quote($name) . '\s*}}/u', $replacement, $value);
                }
                return $value;
            };

            array_walk_recursive($copy, $walker);
            $stack[] = $copy;
        }

        $unique = [];
        foreach ($stack as $item) {
            $hash = md5(serialize($item));
            $unique[$hash] = $item;
        }

        return array_values($unique);
    }
}
