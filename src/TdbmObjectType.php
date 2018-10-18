<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\GraphQL\Controllers\AbstractAnnotatedObjectType;
use TheCodingMachine\GraphQL\Controllers\Annotations\SourceFieldInterface;
use TheCodingMachine\GraphQL\Controllers\FromSourceFieldsInterface;

abstract class TdbmObjectType implements FromSourceFieldsInterface
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

    abstract public function alter(): void;

    /**
     * Dynamically returns the array of source fields to be fetched from the original object.
     *
     * @return SourceFieldInterface[]
     */
    public function getSourceFields(): array
    {
        $this->alter();
        return array_filter($this->getFieldList(), function ($field) {
            return !$field->isHidden();
        });
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
