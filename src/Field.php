<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Type\AbstractType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeInterface;

class Field extends AbstractField
{
    private $hide = false;

    public function __construct(string $name, TypeInterface $type, array $additionalConfig = [])
    {
        $config = [
            'name' => $name,
            'type' => $type
        ];

        if (!isset($additionalConfig['resolve'])) {
            $config['resolve'] = function ($source, array $args, ResolveInfo $info) {
                $getter = 'get'.$info->getField()->getName();
                return $source->$getter();
            };
        }

        $config += $additionalConfig;
        parent::__construct($config);
    }
    
    /**
     * @return AbstractObjectType|AbstractType
     */
    public function getType()
    {
        return $this->config->getType();
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
