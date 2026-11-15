<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The relationship repeater fixture: one table, two relationships on it
 * (`kind`), so a ClientRepeater and its stock twin can edit the same kind of
 * records side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('body');
            $table->unsignedInteger('sort')->nullable();
            $table->timestamps();
        });
    }
};
