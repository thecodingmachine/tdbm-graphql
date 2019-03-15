<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\GraphQLite\AbstractAnnotatedObjectType;
use TheCodingMachine\GraphQLite\Annotations\SourceFieldInterface;
use TheCodingMachine\GraphQLite\FromSourceFieldsInterface;

abstract class TdbmObjectType implements FromSourceFieldsInterface
{
    /**
     * Returns the list of fields coming from TDBM beans.
     *
     * @deprecated With DB column annotations instead
     * @return Field[]
     */
    protected function getFieldList(): array
    {
        return [];
    }

    /**
     * @deprecated With DB column annotations instead
     */
    public function alter(): void
    {

    }

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

    /**
     * @deprecated With DB column annotations instead
     */
    protected function showAll(): void
    {
        foreach ($this->getFieldList() as $field) {
            $field->show();
        }
    }

    /**
     * @deprecated With DB column annotations instead
     */
    protected function hideAll(): void
    {
        foreach ($this->getFieldList() as $field) {
            $field->hide();
        }
    }
}
