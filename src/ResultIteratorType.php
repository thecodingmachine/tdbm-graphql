<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use TheCodingMachine\TDBM\ResultIterator;
use Youshido\GraphQL\Config\Object\ObjectTypeConfig;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Type\NonNullType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\Scalar\IntType;
use Youshido\GraphQL\Type\Scalar\StringType;
use Youshido\GraphQL\Type\TypeInterface;

/**
 * Type mapping a TDBM ResultIterator.
 * It allows easy pagination and sorting in the iterator.
 */
class ResultIteratorType extends AbstractObjectType
{
    /**
     * @var TypeInterface
     */
    private $beanType;

    public function __construct(TypeInterface $beanType)
    {
        parent::__construct([]);
        $this->beanType = $beanType;
    }

    /**
     * @param ObjectTypeConfig $config
     */
    public function build($config)
    {
        $config->addField('count', [
            'type' => new IntType(),
            'description' => 'Returns the total number of items in the collection.',
            'resolve' => function (ResultIterator $source, $args, $info) {
                return (int) $source->count();
            }
        ]);
        $config->addField('items', [
            'type' => new NonNullType(new ListType(new NonNullType($this->beanType))),
            'description' => 'Returns the list of items in the collection.',
            'args' => [
                'limit' => new IntType(),
                'offset' => new IntType(),
                'order' => new StringType(),
            ],
            'resolve' => function (ResultIterator $source, $args, $info) {
                if (isset($args['order'])) {
                    $source = $source->withOrder($args['order']);
                }
                if (!isset($args['limit']) && isset($args['offset'])) {
                    throw new GraphQLException('In "items" field, you can specify an offset without a limit.');
                }
                if (isset($args['limit']) || isset($args['offset'])) {
                    $source = $source->take($args['offset'] ?? 0, $args['limit']);
                }

                return $source->toArray();
            }
        ]);
    }
}
