<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A nullable BelongsTo from users to teams, so the grouping fixtures can
 * exercise RELATIONSHIP grouping (`Group::make('team.name')` dot-syntax):
 * eager loading, the relationship orderer, and `whereHas` scoping.
 *
 * Separate from the users migration because `teams` is created after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
