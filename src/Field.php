<?php


namespace TheCodingMachine\Tdbm\GraphQL;


use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Type\AbstractType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeInterface;

class Field extends AbstractField
{
    private $hide = false;

    public function __construct(string $name, TypeInterface $type)
    {
        parent::__construct([
            'name' => $name,
            'type' => $type
        ]);
    }
    
    /**
     * @return AbstractObjectType|AbstractType
     */
    public function getType()
    {
        return $this->config['type'];
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hide;
    }

    /**
     * Hides this field.
     */
    public function hide()
    {
        $this->hide = true;
    }

    /**
     * Show this field if it was previously hidden.
     */
    public function show()
    {
        $this->hide = false;
    }
}
