<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use Mouf\Database\TDBM\Utils\BeanDescriptorInterface;

class DefaultNamingStrategy implements NamingStrategyInterface
{
    public function getGraphQLType(BeanDescriptorInterface $beanDescriptor) : string
    {
        return $beanDescriptor->getBeanClassName();
    }

    public function getClassName(BeanDescriptorInterface $beanDescriptor) : string
    {
        return $this->getGraphQLType($beanDescriptor).'Type';
    }

    public function getGeneratedClassName(BeanDescriptorInterface $beanDescriptor) : string
    {
        return 'Abstract'.$this->getClassName($beanDescriptor);
    }


}
