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
            $baseContent = $stack[0];
            foreach ($variables as $i => $var) {
                if (isset($stack[$i])) {
                    $stack[$i] = str_replace($hash, $var, $stack[$i]);
                } else {
                    $stack[$i] = str_replace($hash, $var, $baseContent);
                }
            }
        }

        $stack = array_values(array_unique($stack));
        return $stack;
    }
}
