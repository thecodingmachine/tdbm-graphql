<?php


namespace TheCodingMachine\Tdbm\GraphQL;


use Psr\Container\ContainerInterface;
use Youshido\GraphQL\Type\InputObject\InputObjectType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeInterface;

abstract class AbstractTdbmGraphQLTypeMapper
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * Returns an array mapping PHP classes to GraphQL types.
     *
     * @return array
     */
    abstract protected function getMap(): array;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL type.
     *
     * @param string $className
     * @return TypeInterface
     * @throws GraphQLException
     */
    public function mapClassToType(string $className): TypeInterface
    {
        $map = $this->getMap();
        if (!isset($map[$className])) {
            throw new GraphQLException("Unable to map class $className to any known GraphQL type.");
        }
        return $this->container->get($map[$className]);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL input type.
     *
     * @param string $className
     * @return InputTypeInterface
     * @throws GraphQLException
     */
    public function mapClassToInputType(string $className): InputTypeInterface
    {
        // Let's create the input type "on the fly"!
        $type = $this->mapClassToType($className);

        if ($type instanceof AbstractObjectType) {
            throw new GraphQLException('Cannot map a type to input type if it does not extend the AbstractObjectType class');
        }

        return new InputObjectType([
            'name' => $type->getName().'Input',
            'fields' => $type->getFields()
        ]);
    }
}
