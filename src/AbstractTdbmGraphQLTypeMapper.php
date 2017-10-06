<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use Psr\Container\ContainerInterface;
use TheCodingMachine\GraphQL\Controllers\TypeMapperInterface;
use Youshido\GraphQL\Type\InputObject\InputObjectType;
use Youshido\GraphQL\Type\InputTypeInterface;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Type\NonNullType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeInterface;
use Youshido\GraphQL\Type\TypeMap;

abstract class AbstractTdbmGraphQLTypeMapper implements TypeMapperInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var \SplObjectStorage
     */
    private $typeToInputTypes;

    /**
     * Returns an array mapping PHP classes to GraphQL types.
     *
     * @return array
     */
    abstract protected function getMap(): array;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->typeToInputTypes = new \SplObjectStorage();
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
        if (!$type instanceof AbstractObjectType) {
            throw new GraphQLException('Cannot map a type to input type if it does not extend the AbstractObjectType class. Type passed: '.get_class($type));
        }

        return $this->mapTypeToInputType($type);
    }


    private function mapTypeToInputType(TypeInterface $type): InputTypeInterface
    {
        if ($type instanceof ListType) {
            return new ListType($this->mapTypeToInputType($type->getItemType()));
        }
        /*if ($type instanceof NonNullType) {
            return new NonNullType($this->mapTypeToInputType($type->getNullableType()));
        }*/
        // We drop the non null in the input fields
        if ($type instanceof NonNullType) {
            return $this->mapTypeToInputType($type->getNullableType());
        }
        if (!$type instanceof AbstractObjectType) {
            return $type;
        }

        if (isset($this->typeToInputTypes[$type])) {
            return $this->typeToInputTypes[$type];
        }

        $inputType = new InputObjectType([
            'name' => $type->getName().'Input'
        ]);

        $this->typeToInputTypes->attach($type, $inputType);

        $inputFields = array_map(function (\Youshido\GraphQL\Field\FieldInterface $field) {
            $type = $field->getType();
            /*if ($type instanceof NonNullType) {
                return new NonNullType($this->mapTypeToInputType($type->getNullableType()));
            }
            if ($type instanceof ListType) {
                return new ListType($this->mapTypeToInputType($type->getItemType()));
            }
            if ($type->getKind() !== TypeMap::KIND_OBJECT) {
                return $field;
            }*/

            return new \Youshido\GraphQL\Field\Field([
                'name' => $field->getName(),
                'type' => $this->mapTypeToInputType($type),
            ]);
        }, $type->getFields());

        $inputType->addFields($inputFields);
        return $inputType;
    }
}
