<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Http;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Support\RequestStateValidator;
use Zvizvi\Villament\Support\TablePayload;

/**
 * What every data endpoint does once it knows WHICH table component to drive
 * (and has authorized it): set the page's own state properties, boot the
 * component, validate the request against the table it built, apply the
 * state, and answer with the payload.
 */
final class HeadlessTableResponder
{
    public function __construct(
        private readonly RequestStateValidator $validator,
        private readonly TablePayload $payload,
    ) {}

    /**
     * @param  class-string<\Filament\Resources\Resource>|Closure(Model): array<string, bool>|null  $abilities  null: the component's own `getVillamentRecordAbilities()`
     */
    public function respond(Request $request, HeadlessTableHarness $harness, string|Closure|null $abilities = null): JsonResponse
    {
        // The page's own query-shaping properties, BEFORE boot: the table is
        // built during boot, and a table may decide which filters exist from
        // them (filters offered only for a picked campaign, say) — set after,
        // the island's state would name filters the endpoint never built.
        $properties = $request->query('properties');
        $harness->withStateProperties(is_array($properties) ? $properties : []);

        $harness->boot();

        $table = $harness->getTable();
        $state = $this->validator->validate($request, $table);
        $harness->applyState($state)->persistState();

        $component = $harness->getComponent();

        if ($abilities === null) {
            abort_unless(method_exists($component, 'getVillamentRecordAbilities'), 404);

            $abilities = (fn (): string|Closure => $this->getVillamentRecordAbilities())->call($component);
        }

        $cacheTtl = (int) config('villament.cache_ttl', 0);

        $response = response()->json($this->payload->for($component, $abilities));

        if ($cacheTtl > 0) {
            $response->header('Cache-Control', "private, max-age={$cacheTtl}");
        }

        return $response;
    }
}
