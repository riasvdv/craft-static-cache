<?php

declare(strict_types=1);

namespace Rias\CraftStaticCache\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $cacheKey
 * @property string $tag
 */
class CacheTag extends Model
{
    public const string CREATED_AT = 'dateCreated';

    public const string UPDATED_AT = 'dateUpdated';

    protected $table = 'staticcache_tags';

    protected $guarded = [];

    /** @return BelongsTo<CacheEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(CacheEntry::class, 'cacheKey', 'key');
    }

    protected function casts(): array
    {
        return [
            'dateCreated' => 'datetime',
            'dateUpdated' => 'datetime',
        ];
    }
}
