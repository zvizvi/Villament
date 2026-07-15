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

        $pages = $resourceClass::getPages();
        abort_unless(isset($pages['index']), 404);

        $this->harness
            ->forPage($pages['index']->getPage())
            ->boot();

        $table = $this->harness->getTable();
        $state = $this->validator->validate($request, $table);
        $this->harness->applyState($state);

        $records = $this->harness->getRecords();
        $schema = $this->schemaSerializer->serialize($table);

        return response()->json([
            'records' => $this->recordSerializer->serialize(
                $this->harness->getComponent(),
                $resourceClass,
                $records,
            ),
            'meta' => $this->recordSerializer->meta($records),
            'indicators' => $this->indicatorSerializer->serialize($table),
            'schemaVersion' => $this->schemaSerializer->version($schema),
        ]);
    }
}
