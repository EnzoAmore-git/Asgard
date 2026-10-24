<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    protected $fillable = ['name', 'timezone', 'currency', 'period_start_day'];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
