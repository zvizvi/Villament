<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zvizvi\FilamentVueTable\Support\FilterIndicatorSerializer;
use Zvizvi\FilamentVueTable\Support\HeadlessTableHarness;
use Zvizvi\FilamentVueTable\Support\RecordSerializer;
use Zvizvi\FilamentVueTable\Support\RequestStateValidator;
use Zvizvi\FilamentVueTable\Support\ResourceRegistry;
use Zvizvi\FilamentVueTable\Support\SchemaSerializer;
use Zvizvi\FilamentVueTable\Support\SummarySerializer;

final class TableDataController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly HeadlessTableHarness $harness,
        private readonly RequestStateValidator $validator,
        private readonly SchemaSerializer $schemaSerializer,
        private readonly RecordSerializer $recordSerializer,
        private readonly FilterIndicatorSerializer $indicatorSerializer,
    ) {}

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $panel = Filament::getCurrentPanel();
        abort_if($panel === null, 404);

        $resourceClass = $this->registry->resolve($panel, $resource);
        abort_if($resourceClass === null, 404);

        abort_unless($resourceClass::canViewAny(), 403);

        $pageClass = $this->registry->resolveListPage($resourceClass);
        abort_if($pageClass === null, 404);

        $this->harness
            ->forPage($pageClass)
            ->boot();

        $table = $this->harness->getTable();
        $state = $this->validator->validate($request, $table);
        $this->harness->applyState($state);

        $records = $this->harness->getRecords();
        $schema = $this->schemaSerializer->serialize($table);

        $cacheTtl = (int) config('filament-vue-table.cache_ttl', 0);

        $response = response()->json([
            'records' => $this->recordSerializer->serialize(
                $this->harness->getComponent(),
                $resourceClass,
                $records,
            ),
            'meta' => $this->recordSerializer->meta($records),
            'indicators' => $this->indicatorSerializer->serialize($table),
            'summaries' => app(SummarySerializer::class)->serialize($this->harness->getComponent()),
            'schemaVersion' => $this->schemaSerializer->version($schema),
        ]);

        if ($cacheTtl > 0) {
            $response->header('Cache-Control', "private, max-age={$cacheTtl}");
        }

        return $response;
    }
}
