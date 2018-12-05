<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use function array_keys;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\Output;
use TheCodingMachine\GraphQL\Controllers\Mappers\CannotMapTypeException;
use TheCodingMachine\GraphQL\Controllers\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQL\Controllers\Mappers\TypeMapperInterface;
use Youshido\GraphQL\Type\InputObject\InputObjectType;
use Youshido\GraphQL\Type\InputTypeInterface;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Type\NonNullType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeInterface;

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

    public function __construct()
    {
        $this->typeToInputTypes = new \SplObjectStorage();
    }

    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL type.
     *
     * @param string $className
     * @return TypeInterface
     * @throws CannotMapTypeException
     */
    public function mapClassToType(string $className, RecursiveTypeMapperInterface $recursiveTypeMapper): ObjectType
    {
        $map = $this->getMap();
        if (!isset($map[$className])) {
            throw CannotMapTypeException::createForInputType($className);
        }
        return $this->container->get($map[$className]);
    }

    /**
     * Returns the list of classes that have matching input GraphQL types.
     *
     * @return string[]
     */
    public function getSupportedClasses(): array
    {
        return array_keys($this->getMap());
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL input type.
     *
     * @param string $className
     * @return InputType
     * @throws GraphQLException
     */
    public function mapClassToInputType(string $className): InputType
    {
        // Let's create the input type "on the fly"!
        $type = $this->mapClassToType($className);
        if (!$type instanceof AbstractObjectType) {
            throw CannotMapTypeException::createForInputType(get_class($type));
        }

        return $this->mapTypeToInputType($type);
    }


    private function mapTypeToInputType(TypeInterface $type): InputType
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

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL type.
     *
     * @param string $className
     * @return bool
     */
    public function canMapClassToType(string $className): bool
    {
        return isset($this->getMap()[$className]);
    }


    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL input type.
     *
     * @param string $className
     * @return bool
     */
    public function canMapClassToInputType(string $className): bool
    {
        return isset($this->getMap()[$className]);
    }
}
