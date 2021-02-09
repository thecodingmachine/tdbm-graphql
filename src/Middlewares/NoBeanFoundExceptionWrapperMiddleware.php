<?php
namespace TheCodingMachine\Tdbm\GraphQL\Middlewares;

use GraphQL\Type\Definition\FieldDefinition;
use TheCodingMachine\GraphQLite\Exceptions\GraphQLException;
use TheCodingMachine\GraphQLite\Middlewares\FieldHandlerInterface;
use TheCodingMachine\GraphQLite\Middlewares\FieldMiddlewareInterface;
use TheCodingMachine\GraphQLite\QueryFieldDescriptor;
use TheCodingMachine\TDBM\NoBeanFoundException;

class NoBeanFoundExceptionWrapperMiddleware implements FieldMiddlewareInterface
{

    public function process(QueryFieldDescriptor $queryFieldDescriptor, FieldHandlerInterface $fieldHandler): ?FieldDefinition
    {

        $resolver = $queryFieldDescriptor->getResolver();

        $queryFieldDescriptor->setResolver(function (...$args) use ($resolver, $queryFieldDescriptor) {
            try {
                return $resolver(...$args);
            } catch (NoBeanFoundException $e) {
                throw new GraphQLException(
                    $e->getMessage(),
                    404,
                    $e,
                    'Exception',
                    [
                        'table' => $e->getTableName(),
                        'keys' => $e->getPrimaryKeys(),
                        'className' => $e->getClassName()
                    ]
                );
            }
        });

        return $fieldHandler->handle($queryFieldDescriptor);
    }
}