<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'report', 'format', 'filters', 'status', 'file_path', 'error', 'completed_at'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
