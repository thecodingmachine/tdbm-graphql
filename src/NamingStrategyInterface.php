<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\MethodDescriptorInterface;

interface NamingStrategyInterface
{
    public function getGraphQLType(string $beanClassName): string;

    public function getClassName(string $beanClassName) : string;

    public function getGeneratedClassName(string $beanClassName) : string;

    public function getFieldName(AbstractBeanPropertyDescriptor $descriptor): string;

    public function getFieldNameFromRelationshipDescriptor(MethodDescriptorInterface $descriptor): string;
}
