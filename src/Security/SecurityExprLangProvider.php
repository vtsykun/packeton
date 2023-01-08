<?php

namespace Packeton\Security;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

class SecurityExprLangProvider implements ExpressionFunctionProviderInterface
{
    public function __construct(private readonly ParameterBagInterface $parameterBag)
    {
    }

    public function getFunctions(): iterable
    {
        yield new ExpressionFunction(
            'parameter',
            fn () => throw new \LogicException('Compilation is not support by "parameter" function'), //compile is never called by ACL voter
            fn (array $vars, string $name) => $this->parameterBag->get($name)
        );
    }
}
