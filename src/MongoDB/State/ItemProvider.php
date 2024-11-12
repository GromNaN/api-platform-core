<?php

namespace ApiPlatform\MongoDB\State;

use ApiPlatform\Elasticsearch\Serializer\DocumentNormalizer;
use ApiPlatform\Elasticsearch\State\Options;
use ApiPlatform\Metadata\Exception\RuntimeException;
use ApiPlatform\Metadata\InflectorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Util\Inflector;
use ApiPlatform\State\ProviderInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Item provider for MongoDB.
 *
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class ItemProvider implements ProviderInterface
{
    public function __construct(
        private Database $database,
        private readonly ?DenormalizerInterface $denormalizer = null,
        private readonly ?InflectorInterface $inflector = new Inflector()
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $resourceClass = $operation->getClass();
        $options = $operation->getStateOptions() instanceof Options ? $operation->getStateOptions() : new Options(index: $this->getIndex($operation));
        if (!$options instanceof Options) {
            throw new RuntimeException(\sprintf('The "%s" provider was called without "%s".', self::class, Options::class));
        }

        try {
            // @todo check type of "_id" field
            $filter = ['_id' => new ObjectId(reset($uriVariables))];
        } catch (InvalidArgumentException) {
            return null;
        }

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            // @todo add projection
        ];

        $document = $this->getCollection($operation)->findOne($filter, $options);

        $item = $this->denormalizer->denormalize($document, $resourceClass, DocumentNormalizer::FORMAT, [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => true]);
        if (!\is_object($item) && null !== $item) {
            throw new \UnexpectedValueException('Expected item to be an object or null.');
        }

        return $item;
    }

    private function getCollection(Operation $operation): Collection
    {
        $name = $this->inflector->tableize($operation->getShortName());

        return $this->database->selectCollection($name);
    }
}
