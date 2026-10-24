<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['frozen_until' => 'datetime'];
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'frozen' && $this->frozen_until?->isPast() ? 'active' : $this->status;
    }
}
