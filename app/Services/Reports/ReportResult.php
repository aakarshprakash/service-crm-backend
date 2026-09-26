<?php

namespace App\Services\Reports;

/**
 * Uniform report output. Column spec: key => [label, type], where type is one of
 * text (default) | number | quantity | money | date | datetime. Rows may carry a
 * boolean "_flag" to highlight exceptions (discrepancies, low stock, overdue).
 */
class ReportResult
{
    public string $title = '';

    public string $subtitle = '';

    public function __construct(
        public array $columns,
        public array $rows,
        public array $summary = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'columns' => collect($this->columns)->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'type' => $c[1] ?? 'text'])->values(),
            'rows' => $this->rows,
            'summary' => $this->summary,
        ];
    }
}
