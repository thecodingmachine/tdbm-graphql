<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use function array_filter;
use Mouf\Composer\ClassNameMapper;
use TheCodingMachine\FluidSchema\DoctrineAnnotationDumper;
use TheCodingMachine\GraphQLite\Annotations\FailWith;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Type;
use TheCodingMachine\TDBM\ConfigurationInterface;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\Annotation\AnnotationParser;
use TheCodingMachine\TDBM\Utils\BaseCodeGeneratorListener;
use TheCodingMachine\TDBM\Utils\BeanDescriptor;
use TheCodingMachine\TDBM\Utils\DirectForeignKeyMethodDescriptor;
use TheCodingMachine\TDBM\Utils\PivotTableMethodsDescriptor;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use TheCodingMachine\TDBM\Utils\BeanDescriptorInterface;
use Symfony\Component\Filesystem\Filesystem;
use TheCodingMachine\TDBM\Utils\MethodDescriptorInterface;
use TheCodingMachine\TDBM\Utils\ScalarBeanPropertyDescriptor;
use TheCodingMachine\GraphQLite\Annotations\Field as GraphQLField;
use function var_export;

/**
 * Annotates TDBM beans by adding "Type" and "Field" annotations.
 */
class GraphQLTypeAnnotator extends BaseCodeGeneratorListener
{
    /**
     * @var AnnotationParser
     */
    private $annotationParser;

    /**
     * @param string $namespace The namespace the type classes will be written in.
     * @param string|null $generatedNamespace The namespace the generated type classes will be written in (defaults to $namespace + '\Generated')
     * @param NamingStrategyInterface|null $namingStrategy
     * @param ClassNameMapper|null $classNameMapper
     */
    public function __construct(string $namespace, ?string $generatedNamespace = null, ?NamingStrategyInterface $namingStrategy = null, ?ClassNameMapper $classNameMapper = null, ?AnnotationParser $annotationParser = null)
    {
        $this->namespace = trim($namespace, '\\');
        if ($generatedNamespace !== null) {
            $this->generatedNamespace = $generatedNamespace;
        } else {
            $this->generatedNamespace = $namespace.'\\Generated';
        }
        $this->namingStrategy = $namingStrategy ?: new DefaultNamingStrategy();
        $this->classNameMapper = $classNameMapper ?: ClassNameMapper::createFromComposerFile();
        $this->annotationParser = $annotationParser ?: AnnotationParser::buildWithDefaultAnnotations([]);
    }

    public function onBaseBeanGenerated(FileGenerator $fileGenerator, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration): ?FileGenerator
    {
        $annotations = $this->annotationParser->getTableAnnotations($beanDescriptor->getTable());
        $fileGenerator->setUse(GraphQLField::class, 'GraphqlField');

        $type = $annotations->findAnnotation(Type::class);
        if ($type !== null) {
            $fileGenerator->setUse(Type::class, 'GraphqlType');
            $fileGenerator->getClass()->getDocBlock()->setTag(new GenericTag('GraphqlType'));

            $this->generateAbstractTypeFile($beanDescriptor);
            $this->generateMainTypeFile($beanDescriptor);
        }

        return $fileGenerator;
    }

    /**
     * Called when a column is turned into a getter/setter.
     *
     * @return array<int, ?MethodGenerator> Returns an array of 2 methods to be generated for this property. You MUST return the getter (first argument) and setter (second argument) as part of these methods (if you want them to appear in the bean). Return null if you want to delete them.
     */
    public function onBaseBeanPropertyGenerated(?MethodGenerator $getter, ?MethodGenerator $setter, AbstractBeanPropertyDescriptor $propertyDescriptor, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration, ClassGenerator $classGenerator): array
    {
        if ($getter !== null) {
            // Let's analyze the fields
            if ($propertyDescriptor instanceof ScalarBeanPropertyDescriptor) {
                $column = $beanDescriptor->getTable()->getColumn($propertyDescriptor->getColumnName());
                $annotations = $this->annotationParser->getColumnAnnotations($column, $beanDescriptor->getTable());

                /**
                 * @var GraphQLField $fieldAnnotation
                 */
                $fieldAnnotation = $annotations->findAnnotation(GraphQLField::class);
                if ($fieldAnnotation !== null) {
                    $parameters = null;
                    if ($fieldAnnotation->getName() !== null) {
                        $parameters['name'] = $fieldAnnotation->getName();
                    }
                    if ($fieldAnnotation->getOutputType() !== null) {
                        $parameters['outputType'] = $fieldAnnotation->getOutputType();
                    }
                    $getter->getDocBlock()->setTag(new GenericTag('GraphqlField', DoctrineAnnotationDumper::exportValues($parameters)));
                    if ($annotations->findAnnotation(Logged::class)) {
                        $getter->getDocBlock()->setTag(new GenericTag(Logged::class));
                    }
                    /**
                     * @var Right $rightAnnotation
                     */
                    $rightAnnotation = $annotations->findAnnotation(Right::class);
                    if ($rightAnnotation !== null) {
                        $getter->getDocBlock()->setTag(new GenericTag(Right::class, DoctrineAnnotationDumper::exportValues(['name'=>$rightAnnotation->getName()])));
                    }
                    /**
                     * @var FailWith $failWith
                     */
                    $failWith = $annotations->findAnnotation(FailWith::class);
                    if ($failWith !== null) {
                        $content = DoctrineAnnotationDumper::exportValues($failWith->getValue()) ?: '(null)';
                        $getter->getDocBlock()->setTag(new GenericTag(FailWith::class, $content));
                    }
                }
            }



        }
        return [$getter, $setter];
    }

    private function generateAbstractTypeFile(BeanDescriptorInterface $beanDescriptor)
    {
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

        // Let's remove method descriptors that are not annotated with GraphQL
        $methodDescriptors = array_filter($methodDescriptors, [$this, 'isMethodDescriptorExposed']);

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

use TheCodingMachine\GraphQLite\Registry\RegistryInterface;
use TheCodingMachine\GraphQLite\Annotations\Type;
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
            throw new GraphQLGeneratorNamespaceException('Unable to find a suitable autoload path for class '.$fqcn);
        }

        $fileSystem->dumpFile($generatedFilePaths[0], $str);
    }

    private function isMethodDescriptorExposed(MethodDescriptorInterface $descriptor): bool
    {
        if ($descriptor instanceof DirectForeignKeyMethodDescriptor) {
            // Let's check that the base table is a GraphQL type
            $remoteTable = $descriptor->getForeignKey()->getLocalTable();
            $annotations = $this->annotationParser->getTableAnnotations($remoteTable);
            $type = $annotations->findAnnotation(Type::class);

            return $type !== null;
        } elseif ($descriptor instanceof PivotTableMethodsDescriptor) {
            $table = $descriptor->getPivotTable();
            $annotations = $this->annotationParser->getTableAnnotations($table);
            $field = $annotations->findAnnotation(GraphQLField::class);

            return $field !== null;
        } else {
            throw new GraphQLException('Unexpected method descriptor class.');
        }
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
