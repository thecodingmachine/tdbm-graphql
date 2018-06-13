<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\GraphQL\Controllers\AbstractAnnotatedObjectType;
use TheCodingMachine\GraphQL\Controllers\Registry\RegistryInterface;

abstract class TdbmObjectType extends AbstractAnnotatedObjectType
{
    /**
     * Registry is exposed via protected field.
     *
     * @var RegistryInterface
     */
    protected $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
        parent::__construct($registry);
    }

    /**
     * Returns the list of fields coming from TDBM beans.
     *
     * @return Field[]
     */
    protected function getFieldList(): array
    {
        return [];
    }

    protected function showAll(): void
    {
        foreach ($this->getFieldList() as $field) {
            $field->show();
        }
    }

    protected function hideAll(): void
    {
        foreach ($this->getFieldList() as $field) {
            $field->hide();
        }
    }
}
