<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    /** app: "technician" or "manager" (the phone app that registered the token). */
    protected $fillable = ['user_id', 'token', 'platform', 'app'];
}
