<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filters bills and estimates by how they handle GST. Used by the invoice and estimate lists.
 * Needs the `is_gst` and `prices_include_gst` columns.
 */
trait HasGstStyle
{
    /** gst = any GST bill; exclusive / inclusive = GST bills of that style; non_gst = no GST. Anything else: no filter. */
    public function scopeGstStyle(Builder $query, ?string $style): Builder
    {
        return match ($style) {
            'gst' => $query->where('is_gst', true),
            'exclusive' => $query->where('is_gst', true)->where('prices_include_gst', false),
            'inclusive' => $query->where('is_gst', true)->where('prices_include_gst', true),
            'non_gst' => $query->where('is_gst', false),
            default => $query,
        };
    }
}
