<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an enquiry's follow-up trail: a note someone wrote, or a change of status. */
class EnquiryNote extends Model
{
    use BelongsToTenant;

    protected $fillable = ['enquiry_id', 'user_id', 'kind', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
