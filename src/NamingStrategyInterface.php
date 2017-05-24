<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;

interface NamingStrategyInterface
{
    public function getGraphQLType(string $beanClassName): string;

    public function getClassName(string $beanClassName) : string;

    public function getGeneratedClassName(string $beanClassName) : string;

    public function getFieldName(AbstractBeanPropertyDescriptor $descriptor): string;
}