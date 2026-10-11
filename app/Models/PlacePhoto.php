<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacePhoto extends Model
{
    protected $fillable = [
        'token',
        'photo_name',
    ];
}
