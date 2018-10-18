<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use TheCodingMachine\TDBM\ResultIterator;

/**
 * Type mapping a TDBM ResultIterator.
 * It allows easy pagination and sorting in the iterator.
 */
class ResultIteratorType extends ObjectType
{
    /**
     * @var ObjectType
     */
    private $beanType;

    public function __construct(ObjectType $beanType)
    {
        $this->beanType = $beanType;
        parent::__construct([
            'name' => $beanType->name.'ResultIterator',
            'fields' => [
                'count' => [
                    'type' => Type::int(),
                    'description' => 'Returns the total number of items in the collection.',
                    'resolve' => function (ResultIterator $source) {
                        return $source->count();
                    }
                ],
                'items' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->beanType))),
                    'description' => 'Returns the list of items in the collection.',
                    'args' => [
                        'limit' => Type::int(),
                        'offset' => Type::int(),
                        'order' => Type::string(),
                    ],
                    'resolve' => function (ResultIterator $source, $args) {
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
                ]
            ]
        ]);
    }
}
