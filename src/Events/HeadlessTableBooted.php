<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Events;

use Filament\Resources\Pages\ListRecords;

/**
 * Dispatched once the data endpoint has booted the list page headlessly —
 * its table built, before any request state is validated or applied.
 *
 * On a real page a package that adds to the table at runtime (filters pushed
 * from a Livewire `mount`/`hydrate` listener, say) does it from Livewire's own
 * events, and none of those fire here. Listening to this event is how such a
 * package does the same work for the endpoint, so the state the island sends
 * back names things that exist.
 */
final readonly class HeadlessTableBooted
{
    public function __construct(public ListRecords $component) {}
}
