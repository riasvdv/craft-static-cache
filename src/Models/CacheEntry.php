<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $key
 * @property string $url
 * @property string $host
 * @property string $path
 * @property ?string $query
 * @property ?int $siteId
 * @property ?string $filePath
 * @property list<string> $tags
 */
class CacheEntry extends Model
{
    public const string CREATED_AT = 'dateCreated';

    public const string UPDATED_AT = 'dateUpdated';

    protected $table = 'staticcache_entries';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @return HasMany<CacheTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(CacheTag::class, 'cacheKey', 'key');
    }

    protected function casts(): array
    {
        return [
            'siteId' => 'integer',
            'tags' => 'array',
            'dateCreated' => 'datetime',
            'dateUpdated' => 'datetime',
        ];
    }
}
