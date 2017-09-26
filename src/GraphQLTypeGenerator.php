<?php
namespace TheCodingMachine\Tdbm\GraphQL;

use Mouf\Composer\ClassNameMapper;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\TDBM\ConfigurationInterface;
use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\BeanDescriptorInterface;
use TheCodingMachine\TDBM\Utils\DirectForeignKeyMethodDescriptor;
use TheCodingMachine\TDBM\Utils\GeneratorListenerInterface;
use Symfony\Component\Filesystem\Filesystem;
use TheCodingMachine\TDBM\Utils\MethodDescriptorInterface;
use TheCodingMachine\TDBM\Utils\ObjectBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\ScalarBeanPropertyDescriptor;
use Youshido\GraphQL\Type\Scalar\BooleanType;
use Youshido\GraphQL\Type\Scalar\DateTimeType;
use Youshido\GraphQL\Type\Scalar\FloatType;
use Youshido\GraphQL\Type\Scalar\IdType;
use Youshido\GraphQL\Type\Scalar\IntType;
use Youshido\GraphQL\Type\Scalar\StringType;

class GraphQLTypeGenerator implements GeneratorListenerInterface
{
    /**
     * @var string
     */
    private $namespace;
    /**
     * @var string
     */
    private $generatedNamespace;
    /**
     * @var null|NamingStrategyInterface
     */
    private $namingStrategy;
    /**
     * @var ClassNameMapper
     */
    private $classNameMapper;

    /**
     * @param string $namespace The namespace the type classes will be written in.
     * @param string|null $generatedNamespace The namespace the generated type classes will be written in (defaults to $namespace + '\Generated')
     * @param NamingStrategyInterface|null $namingStrategy
     * @param ClassNameMapper|null $classNameMapper
     */
    public function __construct(string $namespace, ?string $generatedNamespace = null, ?NamingStrategyInterface $namingStrategy = null, ?ClassNameMapper $classNameMapper = null)
    {
        $this->namespace = trim($namespace, '\\');
        if ($generatedNamespace !== null) {
            $this->generatedNamespace = $generatedNamespace;
        } else {
            $this->generatedNamespace = $namespace.'\\Generated';
        }
        $this->namingStrategy = $namingStrategy ?: new DefaultNamingStrategy();
        $this->classNameMapper = $classNameMapper ?: ClassNameMapper::createFromComposerFile();
    }

    /**
     * @param ConfigurationInterface $configuration
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    public function onGenerate(ConfigurationInterface $configuration, array $beanDescriptors): void
    {
        $this->generateTypes($beanDescriptors);
        $this->generateTypeMapper($beanDescriptors);
    }

    /**
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    private function generateTypes(array $beanDescriptors): void
    {
        foreach ($beanDescriptors as $beanDescriptor) {
            $this->generateAbstractTypeFile($beanDescriptor);
            $this->generateMainTypeFile($beanDescriptor);
        }
    }

    private function generateAbstractTypeFile(BeanDescriptorInterface $beanDescriptor)
    {
        // FIXME: find a way around inheritance issues => we should have interfaces for inherited tables.
        // Ideally, the interface should have the same fields as the type (so no issue)

        $generatedTypeClassName = $this->namingStrategy->getGeneratedClassName($beanDescriptor->getBeanClassName());
        $typeName = var_export($this->namingStrategy->getGraphQLType($beanDescriptor->getBeanClassName()), true);

        $properties = $beanDescriptor->getExposedProperties();
        $fieldsCodes = array_map([$this, 'generateFieldCode'], $properties);

        $fieldsCode = implode('', $fieldsCodes);

        $extendedBeanClassName = $beanDescriptor->getExtendedBeanClassName();
        if ($extendedBeanClassName === null) {
            $baseClassName = 'TdbmObjectType';
            $callParentBuild = '';
            $isExtended = false;
            $parentCall = 'parent::__construct($config);';
        } else {
            $baseClassName = '\\'.$this->namespace.'\\'.$this->namingStrategy->getClassName($extendedBeanClassName);
            $isExtended = true;
            $callParentBuild = "parent::build(\$config);\n        ";
            $parentCall = 'parent::__construct($registry, $config);';
        }

        // one to many and many to many relationships:
        $methodDescriptors = $beanDescriptor->getMethodDescriptors();
        $relationshipsCodes = array_map([$this, 'generateRelationshipsCode'], $methodDescriptors);
        $relationshipsCode = implode('', $relationshipsCodes);

        $fieldFetcherCodes = array_map(function (AbstractBeanPropertyDescriptor $propertyDescriptor) {
            return '            $this->'.$propertyDescriptor->getGetterName(). 'Field(),';
        }, $properties);
        $fieldFetcherCodes = array_merge($fieldFetcherCodes, array_map(function (MethodDescriptorInterface $propertyDescriptor) {
            return '            $this->'.$propertyDescriptor->getName(). 'Field(),';
        }, $methodDescriptors));
        $fieldFetcherCode = implode("\n", $fieldFetcherCodes);


        $str = <<<EOF
<?php
namespace {$this->generatedNamespace};

//use Youshido\GraphQL\Relay\Connection\Connection;
//use Youshido\GraphQL\Relay\Connection\ArrayConnection;
use TheCodingMachine\Tdbm\GraphQL\Field;
use TheCodingMachine\Tdbm\GraphQL\TdbmObjectType;
use TheCodingMachine\Tdbm\GraphQL\Registry\Registry;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Config\Object\ObjectTypeConfig;
use Youshido\GraphQL\Type\NonNullType;

abstract class $generatedTypeClassName extends $baseClassName
{
    protected \$registry;

    public function __construct(Registry \$registry, array \$config = [])
    {
        $parentCall
        \$this->registry = \$registry;
    }

EOF;
        if (!$isExtended) {
            $str .= <<<EOF
    /**
     * Alters the list of properties for this type.
     */
    abstract public function alter(): void;


EOF;
        }
        $str .= <<<EOF
    public function getName()
    {
        return $typeName;
    }
    
    /**
     * @param ObjectTypeConfig \$config
     */
    public function build(\$config)
    {
        $callParentBuild\$this->alter();
        \$config->addFields(array_filter(\$this->getFieldList(), function (\$field) {
            return !\$field->isHidden();
        }));
    }
    
    /**
     * @return Field[]
     */
    protected function getFieldList(): array
    {
        return array_merge(parent::getFieldList(), [
$fieldFetcherCode
        ]);
    }
    
$fieldsCode
$relationshipsCode
}

EOF;

        $fileSystem = new Filesystem();

        $fqcn = $this->generatedNamespace.'\\'.$generatedTypeClassName;
        $generatedFilePaths = $this->classNameMapper->getPossibleFileNames($this->generatedNamespace.'\\'.$generatedTypeClassName);
        if (empty($generatedFilePaths)) {
            throw new GraphQLGeneratorDirectForeignKeyMethodDescriptorNamespaceException('Unable to find a suitable autoload path for class '.$fqcn);
        }

        $fileSystem->dumpFile($generatedFilePaths[0], $str);
    }

    private function generateMainTypeFile(BeanDescriptorInterface $beanDescriptor)
    {
        $typeClassName = $this->namingStrategy->getClassName($beanDescriptor->getBeanClassName());
        $generatedTypeClassName = $this->namingStrategy->getGeneratedClassName($beanDescriptor->getBeanClassName());

        $fileSystem = new Filesystem();

        $fqcn = $this->namespace.'\\'.$typeClassName;
        $filePaths = $this->classNameMapper->getPossibleFileNames($fqcn);
        if (empty($filePaths)) {
            throw new GraphQLGeneratorNamespaceException('Unable to find a suitable autoload path for class '.$fqcn);
        }
        $filePath = $filePaths[0];

        if ($fileSystem->exists($filePath)) {
            return;
        }

        $isExtended = $beanDescriptor->getExtendedBeanClassName() !== null;
        if ($isExtended) {
            $alterParentCall = "parent::alter();\n        ";
        } else {
            $alterParentCall = '';
        }

        $str = <<<EOF
<?php
namespace {$this->namespace};

use {$this->generatedNamespace}\\$generatedTypeClassName;

class $typeClassName extends $generatedTypeClassName
{
    /**
     * Alters the list of properties for this type.
     */
    public function alter(): void
    {
        $alterParentCall// You can alter the fields of this type here.
        \$this->showAll();
    }
}

EOF;

        $fileSystem->dumpFile($filePaths[0], $str);
    }

    private function generateFieldCode(AbstractBeanPropertyDescriptor $descriptor) : string
    {
        $getterName = $descriptor->getGetterName();
        $fieldNameAsCode = var_export($this->namingStrategy->getFieldName($descriptor), true);
        $variableName = $descriptor->getVariableName().'Field';
        $thisVariableName = '$this->'.substr($descriptor->getVariableName().'Field', 1);

        $type = $this->getType($descriptor);

        $code = <<<EOF
    private $variableName;
        
    protected function {$getterName}Field() : Field
    {
        if ($thisVariableName === null) {
            $thisVariableName = new Field($fieldNameAsCode, $type, \$this->registry);
        }
        return $thisVariableName;
    }


EOF;

        return $code;
    }

    private function getType(AbstractBeanPropertyDescriptor $descriptor)
    {
        // FIXME: can there be several primary key? If yes, we might need to fix this.
        // Also, primary key should be named "ID"
        if ($descriptor->isPrimaryKey()) {
            return 'new \\'.IdType::class.'()';
        }

        $phpType = $descriptor->getPhpType();
        if ($descriptor instanceof ScalarBeanPropertyDescriptor) {
            $map = [
                // TODO: how to handle JSON properties???
                //'array' => StringT,
                'string' => '\\'.StringType::class,
                'bool' => '\\'.BooleanType::class,
                '\DateTimeImmutable' => '\\'.DateTimeType::class,
                'float' => '\\'.FloatType::class,
                'int' => '\\'.IntType::class,
            ];

            if (!isset($map[$phpType])) {
                throw new GraphQLGeneratorNamespaceException("Cannot map PHP type '$phpType' to any known GraphQL type.");
            }

            $newCode = 'new '.$map[$phpType].'()';
        } elseif ($descriptor instanceof ObjectBeanPropertyDescriptor) {
            $beanclassName = $descriptor->getClassName();
            $newCode = '$this->registry->get(\''.$this->namespace.'\\'.$this->namingStrategy->getClassName($beanclassName).'\')';
        } else {
            throw new GraphQLGeneratorNamespaceException('Unexpected property descriptor. Cannot handle class '.get_class($descriptor));
        }

        if ($descriptor->isCompulsory()) {
            $newCode = "new NonNullType($newCode)";
        }

        return $newCode;
    }

    private function generateRelationshipsCode(MethodDescriptorInterface $descriptor): string
    {
        $getterName = $descriptor->getName();
        $fieldName = $this->namingStrategy->getFieldNameFromRelationshipDescriptor($descriptor);
        $fieldNameAsCode = var_export($fieldName, true);
        $variableName = '$'.$fieldName.'Field';
        $thisVariableName = '$this->'.$fieldName.'Field';

        $type = 'new NonNullType(new ListType(new NonNullType($this->registry->get(\''.$this->namespace.'\\'.$this->namingStrategy->getClassName($descriptor->getBeanClassName()).'\'))))';

        // FIXME: suboptimal code! We need to be able to call ->take for pagination!!!
        /*$code = <<<EOF
    private $variableName;

    protected function {$getterName}Field() : Field
    {
        if ($thisVariableName === null) {
            $thisVariableName = new Field($fieldNameAsCode, Connection::connectionDefinition($type), [
                'args' => Connection::connectionArgs(),
                'resolve' => function (\$value = null, \$args = [], \$type = null) {
                    return ArrayConnection::connectionFromArray(\$value->$getterName(), \$args);
                }
            ]);
        }
        return $thisVariableName;
    }


EOF;*/
        $code = <<<EOF
    private $variableName;
        
    protected function {$getterName}Field() : Field
    {
        if ($thisVariableName === null) {
            $thisVariableName = new Field($fieldNameAsCode, $type, \$this->registry);
        }
        return $thisVariableName;
    }


EOF;

        return $code;
    }

    /**
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    private function generateTypeMapper(array $beanDescriptors)
    {
        $mapCode = '';

        foreach ($beanDescriptors as $beanDescriptor) {
            $fqcn = $beanDescriptor->getBeanNamespace().'\\'.$beanDescriptor->getBeanClassName();
            $graphqlType = $this->namespace.'\\'.$this->namingStrategy->getClassName($beanDescriptor->getBeanClassName());

            $beanToGraphQLMap[$fqcn] = $graphqlType;
            $mapCode .= '            '.var_export($fqcn, true).' => '.var_export($graphqlType, true).",\n";
        }


        $str = <<<EOF
<?php
namespace {$this->namespace};

use TheCodingMachine\Tdbm\GraphQL\AbstractTdbmGraphQLTypeMapper;

class TdbmGraphQLTypeMapper extends AbstractTdbmGraphQLTypeMapper
{
    protected function getMap(): array
    {
        return [
$mapCode
        ];
    }
}

EOF;

        $classMapperFqcn = $this->namespace.'\\TdbmGraphQLTypeMapper';

        $fileSystem = new Filesystem();
        $filePaths = $this->classNameMapper->getPossibleFileNames($classMapperFqcn);
        if (empty($filePaths)) {
            throw new GraphQLGeneratorNamespaceException('Unable to find a suitable autoload path for class '.$fqcn);
        }
        $filePath = $filePaths[0];
        $fileSystem->dumpFile($filePath, $str);
    }
}
