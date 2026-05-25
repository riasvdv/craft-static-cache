<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staticcache_entries', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->text('url');
            $table->string('host')->index();
            $table->text('path');
            $table->text('query')->nullable();
            $table->integer('siteId')->nullable()->index();
            $table->text('filePath')->nullable();
            $table->json('tags')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
        });

        Schema::create('staticcache_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('cacheKey', 64)->index();
            $table->string('tag')->index();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->unique(['cacheKey', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staticcache_tags');
        Schema::dropIfExists('staticcache_entries');
    }
};
