<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tenancy follows its room / parent. */
class ResidencyRoomItem extends Model
{
    protected $table = 'residency_room_items';

    protected $fillable = ['location_id', 'item_name', 'ownership', 'quantity', 'remarks', 'added_by'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
