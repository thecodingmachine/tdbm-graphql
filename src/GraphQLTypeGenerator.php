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
use function var_export;

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

        $properties = array_filter($properties, [$this, 'canBeCastToGraphQL']);

        $fieldsCodes = array_map([$this, 'generateFieldCode'], $properties);

        $fieldsCode = implode('', $fieldsCodes);

        $extendedBeanClassName = $beanDescriptor->getExtendedBeanClassName();
        if ($extendedBeanClassName === null) {
            $baseClassName = 'TdbmObjectType';
            $isExtended = false;
        } else {
            $baseClassName = '\\'.$this->namespace.'\\'.$this->namingStrategy->getClassName($extendedBeanClassName);
            $isExtended = true;
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

        $beanFullClassName = '\\'.$beanDescriptor->getBeanNamespace().'\\'.$beanDescriptor->getBeanClassName();

        $str = <<<EOF
<?php
namespace {$this->generatedNamespace};

use TheCodingMachine\GraphQL\Controllers\Registry\RegistryInterface;
use TheCodingMachine\GraphQL\Controllers\Annotations\Type;
use TheCodingMachine\Tdbm\GraphQL\Field;
use TheCodingMachine\Tdbm\GraphQL\TdbmObjectType;

/**
 * @Type(class=$beanFullClassName::class)
 */
abstract class $generatedTypeClassName extends $baseClassName
{
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
EOF;

        $str = rtrim($str, "\n ")."\n}\n";

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

    /**
     * Some fields cannot be bound to GraphQL fields (for instance JSON fields)
     */
    private function canBeCastToGraphQL(AbstractBeanPropertyDescriptor $descriptor) : bool
    {
        if ($descriptor instanceof ScalarBeanPropertyDescriptor) {
            $phpType = $descriptor->getPhpType();
            if ($phpType === 'array' || $phpType === 'resource') {
                // JSON or BLOB types cannot be casted since GraphQL does not allow for untyped arrays or BLOB.
                return false;
            }
        }
        return true;
    }

    private function generateFieldCode(AbstractBeanPropertyDescriptor $descriptor) : string
    {
        $getterName = $descriptor->getGetterName();
        $fieldNameAsCode = var_export($this->namingStrategy->getFieldName($descriptor), true);
        $variableName = $descriptor->getVariableName().'Field';
        $thisVariableName = '$this->'.substr($descriptor->getVariableName().'Field', 1);


        if (!$this->canBeTyped($descriptor)) {
            return <<<EOF
    // Field $getterName is ignored. Cannot represent a JSON  or BLOB field in GraphQL.

EOF;
        }

        $isId = var_export($descriptor->isPrimaryKey(), true);

        $code = <<<EOF
    private $variableName;
        
    protected function {$getterName}Field() : Field
    {
        if ($thisVariableName === null) {
            $thisVariableName = new Field($fieldNameAsCode, $isId);
        }
        return $thisVariableName;
    }

EOF;

        return $code;
    }

    private function canBeTyped(AbstractBeanPropertyDescriptor $descriptor) : bool
    {
        if ($descriptor instanceof ScalarBeanPropertyDescriptor) {
            $phpType = $descriptor->getPhpType();
            if ($phpType === 'array' || $phpType === 'resource') {
                // JSON and BLOB type cannot be casted since GraphQL does not allow for untyped arrays or BLOB.
                return false;
            }
        }
        return true;
    }

    private function generateRelationshipsCode(MethodDescriptorInterface $descriptor): string
    {
        $getterName = $descriptor->getName();
        $fieldName = $this->namingStrategy->getFieldNameFromRelationshipDescriptor($descriptor);
        $fieldNameAsCode = var_export($fieldName, true);
        $variableName = '$'.$fieldName.'Field';
        $thisVariableName = '$this->'.$fieldName.'Field';

        //$type = 'new NonNullType(new ListType(new NonNullType($this->registry->get(\''.$this->namespace.'\\'.$this->namingStrategy->getClassName($descriptor->getBeanClassName()).'\'))))';

        // FIXME: suboptimal code! We need to be able to call ->take for pagination!!!

        $code = <<<EOF
    private $variableName;
        
    protected function {$getterName}Field() : Field
    {
        if ($thisVariableName === null) {
            $thisVariableName = new Field($fieldNameAsCode);
        }
        return $thisVariableName;
    }


EOF;

        return $code;
    }
}
