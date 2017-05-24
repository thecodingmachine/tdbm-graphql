<?php
namespace TheCodingMachine\Tdbm\GraphQL;

use Mouf\Composer\ClassNameMapper;
use Mouf\Database\TDBM\ConfigurationInterface;
use Mouf\Database\TDBM\Utils\BeanDescriptorInterface;
use Mouf\Database\TDBM\Utils\GeneratorListenerInterface;
use Symfony\Component\Filesystem\Filesystem;

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
     * @param string $namespace The namespace the type classes will be written in.
     * @param null|string $generatedNamespace The namespace the generated type classes will be written in (defaults to $namespace + '\Generated')
     * @param null|NamingStrategyInterface $namingStrategy
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
        foreach ($beanDescriptors as $beanDescriptor) {
            $this->generateTypeClass($beanDescriptor);
        }
    }

    private function generateTypeClass(BeanDescriptorInterface $beanDescriptor)
    {
        $generatedTypeClassName = $this->namingStrategy->getGeneratedClassName($beanDescriptor);
        $typeClassName = $this->namingStrategy->getClassName($beanDescriptor);
        $typeName = var_export($this->namingStrategy->getGraphQLType($beanDescriptor), true);

        $str = <<<EOF
<?php
namespace {$this->generatedNamespace};

use Youshido\GraphQL\Type\Object\AbstractObjectType;

abstract class $generatedTypeClassName extends AbstractObjectType
{
    public function getName()
    {
        return $typeName;
    }
    
    /**
     * @param ObjectTypeConfig \$config
     */
    public function build(\$config)  // implementing an abstract function where you build your type
    {
        // TODO
        //\$config
        //    ->addField('title', new StringType())       // defining "title" field of type String
        //    ->addField('summary', new StringType());    // defining "summary" field of type String
    }
}
EOF;

        $fileSystem = new Filesystem();

        $fqcn = $this->generatedNamespace.'\\'.$generatedTypeClassName;
        $generatedFilePaths = $this->classNameMapper->getPossibleFileNames($this->generatedNamespace.'\\'.$generatedTypeClassName);
        if (empty($generatedFilePaths)) {
            throw new GraphQLGeneratorNamespaceException('Unable to find a suitable autoload path for class '.$fqcn);
        }

        $fileSystem->dumpFile($generatedFilePaths[0], $str);
    }
}
