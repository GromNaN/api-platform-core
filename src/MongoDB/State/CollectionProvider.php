<?php

namespace ApiPlatform\MongoDB\State;

use ApiPlatform\Elasticsearch\Paginator;
use ApiPlatform\Elasticsearch\State\Options;
use ApiPlatform\Metadata\InflectorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Util\Inflector;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\CursorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class CollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly ?DenormalizerInterface $denormalizer = null,
        private readonly ?Pagination $pagination = null,
        //private readonly iterable $collectionExtensions = [],
        private readonly ?InflectorInterface $inflector = new Inflector()
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Paginator
    {
        $resourceClass = $operation->getClass();

        // @todo support collection extensions
        $filter = [];

        $limit = $this->pagination->getLimit($operation, $context);
        $offset = $this->pagination->getOffset($operation, $context);

        $pipeline = [
            ['$match' => $filter],
            // Use $facet to get total count and data in a single query
            [
                '$facet' => [
                    'count' => [['$count' => 'total']],
                    'data' => [
                        ['$skip' => $limit],
                        ['$limit' => $limit],
                    ]
                ]
            ],
            [
                '$project' => [
                    'total' => ['$arrayElemAt' => ['$count.total', 0]],
                    'data' => 1,
                ]
            ]
        ];

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ];

        $documents = $this->getCollection($operation)->aggregate($pipeline, $options);

        if ($documents instanceof CursorInterface) {
            $documents = $documents->toArray();
        }

        return new Paginator(
            $this->denormalizer,
            $documents,
            $resourceClass,
            $limit,
            $offset,
            $context
        );
    }

    private function getCollection(Operation $operation): Collection
    {
        $name = $this->inflector->tableize($operation->getShortName());

        return $this->database->selectCollection($name);
    }
}
