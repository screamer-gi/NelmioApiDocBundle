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
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\SerializationContext;
use Metadata\MetadataFactoryInterface;
use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareInterface;
use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareTrait;
use Nelmio\ApiDocBundle\Model\Model;
use Nelmio\ApiDocBundle\ModelDescriber\Annotations\AnnotationsReader;
use Nelmio\ApiDocBundle\SwaggerPhp\Util;
use OpenApi\Annotations as OA;
use Symfony\Component\PropertyInfo\Type;

/**
 * Uses the JMS metadata factory to extract input/output model information.
 */
class JMSModelDescriber implements ModelDescriberInterface, ModelRegistryAwareInterface
{
    use ModelRegistryAwareTrait;

    private $factory;
    private $namingStrategy;
    private $doctrineReader;
    private $previousGroups = [];

    /**
     * @var array
     */
    private $propertyTypeUseGroupsCache = [];

    public function __construct(
        MetadataFactoryInterface $factory,
        PropertyNamingStrategyInterface $namingStrategy = null,
        Reader $reader
    ) {
        $this->factory = $factory;
        $this->namingStrategy = $namingStrategy;
        $this->doctrineReader = $reader;
    }

    /**
     * {@inheritdoc}
     */
    public function describe(Model $model, OA\Schema $schema)
    {
        $className = $model->getType()->getClassName();
        $metadata = $this->factory->getMetadataForClass($className);
        if (null === $metadata) {
            throw new \InvalidArgumentException(sprintf('No metadata found for class %s.', $className));
        }

        $groupsExclusion = null !== $model->getGroups() ? new GroupsExclusionStrategy($model->getGroups()) : null;

        $schema->type = 'object';
        $annotationsReader = new AnnotationsReader($this->doctrineReader, $this->modelRegistry);
        $annotationsReader->updateSchema(new \ReflectionClass($className), $schema);

        $isJmsV1 = null !== $this->namingStrategy;

        $properties = $schema->getProperties();
        foreach ($metadata->propertyMetadata as $item) {
            // filter groups
            if (null !== $groupsExclusion && $groupsExclusion->shouldSkipProperty($item, SerializationContext::create())) {
                continue;
            }

            $groups = $model->getGroups();

            $previousGroups = null;
            if (isset($groups[$item->name]) && is_array($groups[$item->name])) {
                $previousGroups = $groups;
                $groups = $groups[$item->name];
            } elseif (!isset($groups[$item->name]) && !empty($this->previousGroups[$model->getHash()])) {
                $groups = false === $this->propertyTypeUsesGroups($item->type)
                    ? null
                    : ($isJmsV1 ? [GroupsExclusionStrategy::DEFAULT_GROUP] : $this->previousGroups[$model->getHash()]);
            }

            if (is_array($groups)) {
                $groups = array_filter($groups, 'is_scalar');
            }

            if ([GroupsExclusionStrategy::DEFAULT_GROUP] === $groups) {
                $groups = null;
            }

            $name = true === $isJmsV1 ? $this->namingStrategy->translateName($item) : $item->serializedName;
            // read property options from Swagger Property annotation if it exists
            try {
                if (true === $isJmsV1 && property_exists($item, 'reflection') && null !== $item->reflection) {
                    $reflection = $item->reflection;
                } else {
                    $reflection = new \ReflectionProperty($item->class, $item->name);
                }

                $property = Util::getProperty($schema, $annotationsReader->getPropertyName($reflection, $name));
                $annotationsReader->updateProperty($reflection, $property, $groups);
            } catch (\ReflectionException $e) {
                $property = Util::getProperty($schema, $name);
            }

            if (null !== $property->type || null !== $property->ref) {
                continue;
            }
            if (null === $item->type) {
                $key = Util::searchIndexedCollectionItem($schema->properties, 'property', $name);
                unset($schema->properties[$key]);

                continue;
            }

            $this->describeItem($item->type, $property, $groups, $previousGroups);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Model $model): bool
    {
        $className = $model->getType()->getClassName();

        if ($this->factory->getMetadataForClass($className)) {
            return true;
        }

        return false;
    }

    private function describeItem(array $type, OA\Schema $property, array $groups = null, array $previousGroups = null)
    {
        $nestedTypeInfo = $this->getNestedTypeInArray($type);
        if (null !== $nestedTypeInfo) {
            list($nestedType, $isHash) = $nestedTypeInfo;
            if ($isHash) {
                $property->type = 'object';
                $property->additionalProperties = Util::createChild($property, OA\Property::class);

                // this is a free form object (as nested array)
                if ('array' === $nestedType['name'] && !isset($nestedType['params'][0])) {
                    // in the case of a virtual property, set it as free object type
                    $property->additionalProperties = true;

                    return;
                }

                $this->describeItem($nestedType, $property->additionalProperties, $groups, $previousGroups);

                return;
            }

            $property->type = 'array';
            $property->items = Util::createChild($property, OA\Items::class);
            $this->describeItem($nestedType, $property->items, $groups, $previousGroups);
        } elseif ('array' === $type['name']) {
            $property->type = 'object';
            $property->additionalProperties = true;
        } elseif (in_array($type['name'], ['boolean', 'string'], true)) {
            $property->type = $type['name'];
        } elseif (in_array($type['name'], ['int', 'integer'], true)) {
            $property->type = 'integer';
        } elseif (in_array($type['name'], ['double', 'float'], true)) {
            $property->type = 'number';
            $property->format = $type['name'];
        } elseif (is_subclass_of($type['name'], \DateTimeInterface::class)) {
            $property->type = 'string';
            $property->format = 'date-time';
        } else {
            // we can use property type also for custom handlers, then we don't have here real class name
            if (!class_exists($type['name'])) {
                return null;
            }

            $model = new Model(new Type(Type::BUILTIN_TYPE_OBJECT, false, $type['name']), $groups);
            $property->ref = $this->modelRegistry->register($model);

            if ($previousGroups) {
                $this->previousGroups[$model->getHash()] = $previousGroups;
            }
        }
    }

    private function getNestedTypeInArray(array $type)
    {
        if ('array' !== $type['name'] && 'ArrayCollection' !== $type['name']) {
            return null;
        }
        // array<string, MyNamespaceMyObject>
        if (isset($type['params'][1]['name'])) {
            return [$type['params'][1], true];
        }
        // array<MyNamespaceMyObject>
        if (isset($type['params'][0]['name'])) {
            return [$type['params'][0], false];
        }

        return null;
    }

    /**
     * @param array $type
     *
     * @return bool|null
     */
    private function propertyTypeUsesGroups(array $type)
    {
        if (array_key_exists($type['name'], $this->propertyTypeUseGroupsCache)) {
            return $this->propertyTypeUseGroupsCache[$type['name']];
        }

        try {
            $metadata = $this->factory->getMetadataForClass($type['name']);

            foreach ($metadata->propertyMetadata as $item) {
                if (null !== $item->groups && $item->groups != [GroupsExclusionStrategy::DEFAULT_GROUP]) {
                    $this->propertyTypeUseGroupsCache[$type['name']] = true;

                    return true;
                }
            }
            $this->propertyTypeUseGroupsCache[$type['name']] = false;

            return false;
        } catch (\ReflectionException $e) {
            $this->propertyTypeUseGroupsCache[$type['name']] = null;

            return null;
        }
    }
}
