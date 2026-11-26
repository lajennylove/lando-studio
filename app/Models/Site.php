<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => SiteStatus::class,
        'site_type' => SiteType::class,
        'db_port' => 'integer',
    ];

    public function remoteSite(): BelongsTo
    {
        return $this->belongsTo(RemoteSite::class);
    }

    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class);
    }
}
