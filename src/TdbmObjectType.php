<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use Youshido\GraphQL\Type\Object\AbstractObjectType;

abstract class TdbmObjectType extends AbstractObjectType
{
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
