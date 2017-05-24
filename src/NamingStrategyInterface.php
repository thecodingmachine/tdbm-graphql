<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use Mouf\Database\TDBM\Utils\BeanDescriptorInterface;

interface NamingStrategyInterface
{
    public function getGraphQLType(BeanDescriptorInterface $beanDescriptor): string;

    public function getClassName(BeanDescriptorInterface $beanDescriptor): string;

    public function getGeneratedClassName(BeanDescriptorInterface $beanDescriptor): string;
}