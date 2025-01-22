<?php

declare(strict_types=1);

namespace Packeton\Exception;

use Symfony\Component\Form\FormInterface;

class ValidationException extends \RuntimeException implements DebugHttpExceptionInterface
{
    private array $errors = [];

    public static function create(string $message, FormInterface|array $errors = [], ?\Throwable $previous = null): static
    {
        $exception = new static($message, 400, $previous);
        $exception->errors = $errors instanceof FormInterface ? $exception->getErrors($errors) : $errors;

        return $exception;
    }

    private function getErrors(FormInterface $form): array
    {
        $errors = $base = [];

        foreach ($form->getErrors() as $error) {
            $base[] = $error->getMessage();
        }
        foreach ($form as $child) {
            foreach ($child->getErrors(true) as $error) {
                $errors[$child->getName()] = $error->getMessage();
            }
        }
        if (count($base) > 0) {
            $errors['root'] = implode("\n", $base);
        }

        return $errors;
    }

    public function getFormErrors(): array
    {
        return $this->errors;
    }
}
