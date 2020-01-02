<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook\Twig;

class PlaceholderContext
{
    /**
     * @var array
     */
    private $context = [];

    /**
     * @param string $name
     * @param array $variables
     */
    public function setPlaceholder(string $name, array $variables): void
    {
        $this->context[$name] = array_values($variables);
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    public function walkContent(string $content)
    {
        $replacements = [];
        if (empty($this->context)) {
            return [$content];
        }

        $content = preg_replace_callback(
            '/{{\s([\w\.\_\-]*?)\s}}/u',
            function ($match) use (&$replacements) {
                list($result, $path) = $match;
                if (isset($this->context[$path])) {
                    if (count($this->context[$path]) === 1) {
                        return $this->context[$path][0];
                    }

                    $hash = sha1($path);
                    $replacements[$hash] = $this->context[$path];
                    return $hash;
                }

                return $result;
            },
            $content
        );

        // Generate all possible combination if used > 1 parameters for variable
        $stack = [$content];
        foreach ($replacements as $hash => $variables) {
            foreach ($variables as $var) {
                $currentStack = $stack;
                foreach ($currentStack as $content) {
                    $stack[] = str_replace($hash, $var, $content);
                }
            }
            foreach ($stack as $i => $content) {
                if (strpos($content, $hash) !== false) {
                    unset($stack[$i]);
                }
            }
            $stack = array_values($stack);
        }

        $stack = array_values(array_unique($stack));
        return $stack;
    }
}
