<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Login for a git host (for example devops.cltbcanada.net), used when a theme repository asks for one.
 * The password is stored encrypted with the app key.
 */
class RepositoryCredential extends Model
{
    protected $guarded = [];

    protected $casts = [
        'password' => 'encrypted',
    ];
}
