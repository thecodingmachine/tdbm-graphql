<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\DirectForeignKeyMethodDescriptor;
use TheCodingMachine\TDBM\Utils\MethodDescriptorInterface;

class DefaultNamingStrategy implements NamingStrategyInterface
{
    public function getGraphQLType(string $beanClassName): string
    {
        return $beanClassName;
    }

    public function getClassName(string $beanClassName) : string
    {
        return $this->getGraphQLType($beanClassName).'Type';
    }

    public function getGeneratedClassName(string $beanClassName) : string
    {
        return 'Abstract'.$this->getClassName($beanClassName);
    }

    public function getFieldName(AbstractBeanPropertyDescriptor $descriptor): string
    {
        return substr($descriptor->getVariableName(), 1);
    }

    public function getFieldNameFromRelationshipDescriptor(MethodDescriptorInterface $descriptor): string
    {
        return lcfirst(substr($descriptor->getName(), 3));
    }
}
