<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use TheCodingMachine\FluidSchema\DoctrineAnnotationDumper;
use TheCodingMachine\GraphQLite\Annotations\Factory;
use TheCodingMachine\GraphQLite\Annotations\FailWith;
use TheCodingMachine\GraphQLite\Annotations\HideParameter;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Type;
use TheCodingMachine\TDBM\ConfigurationInterface;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\Tdbm\GraphQL\Annotations\AnnotationAdder;
use TheCodingMachine\TDBM\Utils\AbstractBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\Annotation\AnnotationParser;
use TheCodingMachine\TDBM\Utils\Annotation\Annotations;
use TheCodingMachine\TDBM\Utils\BaseCodeGeneratorListener;
use TheCodingMachine\TDBM\Utils\BeanDescriptor;
use TheCodingMachine\TDBM\Utils\DirectForeignKeyMethodDescriptor;
use TheCodingMachine\TDBM\Utils\GeneratorListenerInterface;
use TheCodingMachine\TDBM\Utils\ObjectBeanPropertyDescriptor;
use TheCodingMachine\TDBM\Utils\PivotTableMethodsDescriptor;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlock\Tag\GenericTag;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use TheCodingMachine\TDBM\Utils\BeanDescriptorInterface;
use TheCodingMachine\TDBM\Utils\ScalarBeanPropertyDescriptor;
use TheCodingMachine\GraphQLite\Annotations\Field as GraphQLField;

/**
 * Annotates TDBM beans by adding "Type" and "Field" annotations.
 */
class GraphQLTypeAnnotator extends BaseCodeGeneratorListener implements GeneratorListenerInterface
{
    /**
     * @var AnnotationParser
     */
    private $annotationParser;
    /**
     * @var bool
     */
    private $exposeAllBeans = false;

    public function __construct(?AnnotationParser $annotationParser = null)
    {
        $this->annotationParser = $annotationParser ?: AnnotationParser::buildWithDefaultAnnotations([]);
    }

    /**
     * Exposes all tables as types.
     * For compatibility with 3.0 release.
     *
     * @deprecated
     */
    public function exposeAllBeansAsTypes(): void
    {
        $this->exposeAllBeans = true;
    }

    public function onBaseBeanGenerated(FileGenerator $fileGenerator, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration): ?FileGenerator
    {
        //$annotations = $this->annotationParser->getTableAnnotations($beanDescriptor->getTable());
        $fileGenerator->setUse(GraphQLField::class, 'GraphqlField');

        /*$type = $annotations->findAnnotation(Type::class);
        if ($type !== null || $this->exposeAllBeans === true) {
            $fileGenerator->setUse(Type::class, 'GraphqlType');
            $fileGenerator->getClass()->getDocBlock()->setTag(new GenericTag('GraphqlType'));

            $this->generateAbstractTypeFile($beanDescriptor);
            $this->generateMainTypeFile($beanDescriptor);
        }*/

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
                $this->alterGetter($getter, $annotations);
            } elseif ($propertyDescriptor instanceof ObjectBeanPropertyDescriptor) {
                $columnNames = $propertyDescriptor->getForeignKey()->getLocalColumns();

                $columns = array_map(function (string $columnName) use ($beanDescriptor) {
                    return $beanDescriptor->getTable()->getColumn($columnName);
                }, $columnNames);

                foreach ($columns as $column) {
                    $annotations = $this->annotationParser->getColumnAnnotations($column, $beanDescriptor->getTable());
                    $this->alterGetter($getter, $annotations);
                }
            } else {
                throw new GraphQLException('Unexpected property descriptor type.'); // @codeCoverageIgnore
            }
        }
        return [$getter, $setter];
    }

    /**
     * Called when a foreign key from another table is turned into a "get many objects" method.
     *
     * @param MethodGenerator $getter
     * @param DirectForeignKeyMethodDescriptor $directForeignKeyMethodDescriptor
     * @param BeanDescriptor $beanDescriptor
     * @param ConfigurationInterface $configuration
     * @param ClassGenerator $classGenerator
     * @return MethodGenerator|null
     */
    public function onBaseBeanOneToManyGenerated(MethodGenerator $getter, DirectForeignKeyMethodDescriptor $directForeignKeyMethodDescriptor, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration, ClassGenerator $classGenerator): ?MethodGenerator
    {
        $columnNames = $directForeignKeyMethodDescriptor->getForeignKey()->getLocalColumns();

        $columns = array_map(function (string $columnName) use ($directForeignKeyMethodDescriptor) {
            return $directForeignKeyMethodDescriptor->getForeignKey()->getLocalTable()->getColumn($columnName);
        }, $columnNames);

        foreach ($columns as $column) {
            $annotations = $this->annotationParser->getColumnAnnotations($column, $beanDescriptor->getTable());
            $this->alterGetter($getter, $annotations);
        }
        return $getter;
    }

    /**
     * Called when a pivot table is turned into get/has/add/set/remove methods.
     *
     * @return array<int, ?MethodGenerator> Returns an array of methods to be generated for this property. You MUST return the get/add/remove/has/set methods in this order (if you want them to appear in the bean, otherwise return null).
     */
    public function onBaseBeanManyToManyGenerated(?MethodGenerator $getter, ?MethodGenerator $adder, ?MethodGenerator $remover, ?MethodGenerator $hasser, ?MethodGenerator $setter, PivotTableMethodsDescriptor $pivotTableMethodsDescriptor, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration, ClassGenerator $classGenerator): array
    {
        if ($getter !== null) {
            $annotations = $this->annotationParser->getTableAnnotations($pivotTableMethodsDescriptor->getPivotTable());
            $this->alterGetter($getter, $annotations);
        }
        return [$getter, $adder, $remover, $hasser, $setter];
    }


    private function alterGetter(MethodGenerator $getter, Annotations $annotations): void
    {

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

    public function onBaseDaoGetByIdGenerated(MethodGenerator $methodGenerator, BeanDescriptor $beanDescriptor, ConfigurationInterface $configuration, ClassGenerator $classGenerator): ?MethodGenerator
    {
        $annotations = $this->annotationParser->getTableAnnotations($beanDescriptor->getTable());
        $type = $annotations->findAnnotation(Type::class);

        if ($type !== null) {
            // This is a type, let's generate a factory for it.
            $methodGenerator->getDocBlock()->setTag(new GenericTag(Factory::class));
            $methodGenerator->getDocBlock()->setTag(new GenericTag(HideParameter::class, '(for="$lazyLoading")'));
        }
        return $methodGenerator;
    }

    /**
     * @param ConfigurationInterface $configuration
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    public function onGenerate(ConfigurationInterface $configuration, array $beanDescriptors): void
    {
        $annotationAdder = new AnnotationAdder();

        foreach ($beanDescriptors as $beanDescriptor) {
            $annotations = $this->annotationParser->getTableAnnotations($beanDescriptor->getTable());
            $type = $annotations->findAnnotation(Type::class);
            if ($type !== null || $this->exposeAllBeans === true) {
                $beanFilePath = $configuration->getPathFinder()->getPath($configuration->getBeanNamespace().'\\'.$beanDescriptor->getBeanClassName());

                if ($beanFilePath->isFile()) {
                    $code = file_get_contents($beanFilePath);

                    if (!$annotationAdder->hasAnnotation($code)) {
                        $code = $annotationAdder->addAnnotation($code);
                        file_put_contents($beanFilePath, $code);
                    }
                }
            }
        }
    }
}
