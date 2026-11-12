<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\Pages;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\PickedUserResource;

class ListPickedUsers extends VillamentListRecords
{
    protected static string $resource = PickedUserResource::class;

    public ?string $pickedStatus = null;

    /** Typed properties a query string can only carry as strings. */
    public ?int $pickedYear = null;

    public bool $pickedFlag = false;

    /** @var list<string> */
    public array $pickedList = ['active'];

    /** Declared too, and never written from a request. */
    #[Locked]
    public string $lockedStatus = 'active';

    /**
     * @return list<string>
     */
    public function getVillamentStateProperties(): array
    {
        return ['pickedStatus', 'lockedStatus', 'pickedYear', 'pickedFlag', 'pickedList'];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        return $this->pickedStatus === null ? $query : $query->where('status', $this->pickedStatus);
    }
}
