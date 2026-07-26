<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Zvizvi\Villament\Tests\Fixtures\Database\Factories\UserFactory;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * The single owning team — a BelongsTo (unlike the many-to-many tenancy
     * relation above), so grouping fixtures can exercise `team.name`
     * dot-syntax relationship grouping.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->teams;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->teams()->whereKey($tenant)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_banned' => 'boolean',
        ];
    }
}
