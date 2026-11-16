<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The users of the project's team: what the tenant panel's relation
     * manager lists.
     *
     * @return HasMany<User, $this>
     */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(User::class, 'team_id', 'team_id');
    }
}
