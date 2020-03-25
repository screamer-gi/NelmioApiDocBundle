<?php

/*
 * This file is part of the NelmioApiDocBundle package.
 *
 * (c) Nelmio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\ModelDescriber;

use Doctrine\Common\Annotations\Reader;
use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareInterface;
use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareTrait;
use Nelmio\ApiDocBundle\Model\Model;
use Nelmio\ApiDocBundle\ModelDescriber\Annotations\AnnotationsReader;
use Nelmio\ApiDocBundle\SwaggerPhp\Util;
use OpenApi\Annotations as OA;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;

class ObjectModelDescriber implements ModelDescriberInterface, ModelRegistryAwareInterface
{
    use ModelRegistryAwareTrait;

    private $propertyInfo;
    private $doctrineReader;

    private $swaggerDefinitionAnnotationReader;

    public function __construct(
        PropertyInfoExtractorInterface $propertyInfo,
        Reader $reader
    ) {
        $this->propertyInfo = $propertyInfo;
        $this->doctrineReader = $reader;
    }

    public function describe(Model $model, OA\Schema $schema)
    {
        $schema->type = 'object';

        $class = $model->getType()->getClassName();
        $schema->_context->class = $class;

        $context = [];
        if (null !== $model->getGroups()) {
            $context = ['serializer_groups' => array_filter($model->getGroups(), 'is_string')];
        }

        $annotationsReader = new AnnotationsReader($this->doctrineReader, $this->modelRegistry);
        $annotationsReader->updateSchema(new ReflectionClass($class), $schema);

        $propertyInfoProperties = $this->propertyInfo->getProperties($class, $context);
        if (null === $propertyInfoProperties) {
            return;
        }

        foreach ($propertyInfoProperties as $propertyName) {
            // read property options from Swagger Property annotation if it exists
            if (property_exists($class, $propertyName)) {
                $reflectionProperty = new ReflectionProperty($class, $propertyName);
                $property = Util::getProperty($schema, $annotationsReader->getPropertyName($reflectionProperty, $propertyName));

                $groups = $model->getGroups();
                if (isset($groups[$propertyName]) && is_array($groups[$propertyName])) {
                    $groups = $model->getGroups()[$propertyName];
                }

                $annotationsReader->updateProperty($reflectionProperty, $property, $groups);
            } else {
                $property = Util::getProperty($schema, $propertyName);
            }

            // If type manually defined
            if (null !== $property->type || null !== $property->ref) {
                continue;
            }

            $types = $this->propertyInfo->getTypes($class, $propertyName);
            if (null === $types || 0 === count($types)) {
                throw new \LogicException(sprintf('The PropertyInfo component was not able to guess the type of %s::$%s. You may need to add a `@var` annotation or use `@SWG\Property(type="")` to make its type explicit.', $class, $propertyName));
            }
            if (count($types) > 1) {
                throw new \LogicException(sprintf('Property %s::$%s defines more than one type. You can specify the one that should be documented using `@SWG\Property(type="")`.', $class, $propertyName));
            }

            $type = $types[0];
            if ($type->isCollection()) {
                $type = $type->getCollectionValueType();
                if (null === $type) {
                    throw new \LogicException(sprintf('Property "%s:%s" is an array, but its items type isn\'t specified. You can specify that by using the type `string[]` for instance or `@SWG\Property(type="array", @SWG\Items(type="string"))`.', $class, $propertyName));
                }

                $property->type = 'array';
                $property = Util::getChild($property, OA\Items::class);
            }

            if (Type::BUILTIN_TYPE_STRING === $type->getBuiltinType()) {
                $property->type = 'string';
            } elseif (Type::BUILTIN_TYPE_BOOL === $type->getBuiltinType()) {
                $property->type = 'boolean';
            } elseif (Type::BUILTIN_TYPE_INT === $type->getBuiltinType()) {
                $property->type = 'integer';
            } elseif (Type::BUILTIN_TYPE_FLOAT === $type->getBuiltinType()) {
                $property->type = 'number';
                $property->format = 'float';
            } elseif (Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()) {
                if (is_a($type->getClassName(), \DateTimeInterface::class, true)) {
                    $property->type = 'string';
                    $property->format = 'date-time';
                } else {
                    $type = new Type($type->getBuiltinType(), false, $type->getClassName(), $type->isCollection(), $type->getCollectionKeyType(), $type->getCollectionValueType()); // ignore nullable field

                    $property->ref = $this->modelRegistry->register(new Model($type, $model->getGroups()));
                }
            } else {
                throw new \Exception(sprintf('Type "%s" is not supported in %s::$%s. You may use the `@SWG\Property(type="")` annotation to specify it manually.', $type->getBuiltinType(), $class, $propertyName));
            }
        }
    }

    public function supports(Model $model): bool
    {
        return Type::BUILTIN_TYPE_OBJECT === $model->getType()->getBuiltinType();
    }
}
