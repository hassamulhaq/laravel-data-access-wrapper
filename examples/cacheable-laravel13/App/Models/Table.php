<?php

namespace App\Models;

use Hassam\DataAccessWrapper\BaseModel;
use Hassam\DataAccessWrapper\Traits\Cacheable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Table extends BaseModel
{
    use Cacheable, HasSlug, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'seating_capacity',
        'min_party_size',
        'max_party_size',
        'qr_code_hash',
        'qr_code_url',
        'qr_last_scanned',
        'is_active',
        'is_reserved',
        'is_occupied',
        'location_id',
        'branch_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_reserved' => 'boolean',
        'is_occupied' => 'boolean',
        'qr_last_scanned' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Slug Configuration
    |--------------------------------------------------------------------------
    */

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->extraScope(fn ($builder) => $builder->where('branch_id', $this->branch_id));
    }

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check() && is_null($model->created_by)) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeReserved($query)
    {
        return $query->where('is_reserved', true);
    }

    public function scopeOccupied($query)
    {
        return $query->where('is_occupied', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where('is_reserved', false)
            ->where('is_occupied', false);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isAvailable(): bool
    {
        return $this->is_active && ! $this->is_reserved && ! $this->is_occupied;
    }

    public function canAccommodate(int $partySize): bool
    {
        return $partySize >= (int) $this->min_party_size
            && $partySize <= (int) $this->max_party_size;
    }

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    public function cacheBy(): array
    {
        return [
            ['id'],
            'slug' => ['slug', 'branch_id'],
            'by_location' => ['location_id'],
            'by_branch' => ['branch_id'],
        ];
    }

    public function cacheTagsForFlush(): array
    {
        $tags = [
            'tables',
            "table:{$this->id}",
            "location:{$this->location_id}",
            "branch:{$this->branch_id}",
        ];

        if ($this->wasChanged('location_id')) {
            $tags[] = "location:{$this->getOriginal('location_id')}";
        }
        if ($this->wasChanged('branch_id')) {
            $tags[] = "branch:{$this->getOriginal('branch_id')}";
        }

        return $tags;
    }
}
