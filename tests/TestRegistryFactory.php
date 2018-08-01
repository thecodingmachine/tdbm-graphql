<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Psr\Container\ContainerInterface;
use TheCodingMachine\GraphQL\Controllers\HydratorInterface;
use TheCodingMachine\GraphQL\Controllers\Mappers\StaticTypeMapper;
use TheCodingMachine\GraphQL\Controllers\Registry\EmptyContainer;
use TheCodingMachine\GraphQL\Controllers\Registry\Registry;
use TheCodingMachine\GraphQL\Controllers\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQL\Controllers\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQL\Controllers\Security\VoidAuthenticationService;
use TheCodingMachine\GraphQL\Controllers\Security\VoidAuthorizationService;
use TheCodingMachine\GraphQL\Controllers\Mappers\TypeMapperInterface;
use Youshido\GraphQL\Type\InputTypeInterface;
use Youshido\GraphQL\Type\TypeInterface;

class TestRegistryFactory
{
    public static function build(ContainerInterface $container = null,
                                 AuthorizationServiceInterface $authorizationService = null,
                                 AuthenticationServiceInterface $authenticationService = null,
                                 Reader $annotationReader = null,
                                 TypeMapperInterface $typeMapper = null,
                                 HydratorInterface $hydrator = null): Registry
    {
        $container = $container ?: new EmptyContainer();
        $authorizationService = $authorizationService ?: new VoidAuthorizationService();
        $authenticationService = $authenticationService ?: new VoidAuthenticationService();
        $reader = new AnnotationReader();
        $typeMapper = $typeMapper ?: new StaticTypeMapper();
        $hydrator = $hydrator ?: new class implements HydratorInterface {

            /**
             * Hydrates/returns an object based on a PHP array and a GraphQL type.
             *
             * @param array $data
             * @param TypeInterface $type
             * @return object
             */
            public function hydrate(array $data, TypeInterface $type)
            {
                throw new \RuntimeException('Not implemented');
            }
        };

        return new Registry($container, $authorizationService, $authenticationService,
            $reader, $typeMapper, $hydrator);
    }
}
