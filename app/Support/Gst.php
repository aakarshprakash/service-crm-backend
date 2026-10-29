<?php

namespace App\Support;

/**
 * GST breakup for GST-inclusive prices, in integer paise only (no floats in any total).
 *
 * Each line's net amount (after its share of the bill discount) is split into
 *   taxable = round(net × 100 / (100 + rate))   and   tax = net − taxable,
 * so taxable + tax always equals the line, and the lines always add up to the invoice total.
 * Tax is CGST + SGST (half each; an odd paisa goes to SGST) within the state, or IGST between states.
 */
class Gst
{
    /**
     * @param  list<array{type: string, description: string, hsn: ?string, qty: float|int, gross: int, gst_rate: float|int}>  $lines
     * @return array{lines: list<array>, summary: list<array>, totals: array{gross: int, discount: int, taxable: int, cgst: int, sgst: int, igst: int, tax: int, total: int}}
     */
    public static function breakup(array $lines, ?int $discount = 0, bool $interState = false): array
    {
        $discount ??= 0;
        $shares = self::allocate(array_map(fn ($l) => max(0, (int) $l['gross']), $lines), $discount);

        $out = [];
        foreach ($lines as $i => $l) {
            $gross = (int) $l['gross'];
            $net = $gross - $shares[$i];
            $rate = round((float) $l['gst_rate'], 2);
            $taxable = self::taxable($net, $rate);
            $tax = $net - $taxable;
            $cgst = $interState ? 0 : intdiv($tax, 2);
            $sgst = $interState ? 0 : $tax - $cgst;
            $qty = (float) $l['qty'];
            $out[] = [
                'type' => $l['type'],
                'description' => $l['description'],
                'hsn' => $l['hsn'] ?: null,
                'qty' => $qty,
                'gross' => $gross,
                'discount' => $shares[$i],
                'net' => $net,
                'gst_rate' => $rate,
                // Display only: taxable value per unit. Totals never use it.
                'unit_taxable' => $qty > 0 ? (int) round($taxable / $qty) : $taxable,
                'taxable' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $interState ? $tax : 0,
                'tax' => $tax,
            ];
        }

        $summary = [];
        foreach ($out as $l) {
            $key = ($l['hsn'] ?? '').'|'.$l['gst_rate'];
            $summary[$key] ??= ['hsn' => $l['hsn'], 'gst_rate' => $l['gst_rate'], 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'tax' => 0];
            foreach (['taxable', 'cgst', 'sgst', 'igst', 'tax'] as $k) {
                $summary[$key][$k] += $l[$k];
            }
        }

        $sum = fn (string $k) => array_sum(array_column($out, $k));

        return [
            'lines' => $out,
            'summary' => array_values($summary),
            'totals' => [
                'gross' => $sum('gross'),
                'discount' => $sum('discount'),
                'taxable' => $sum('taxable'),
                'cgst' => $sum('cgst'),
                'sgst' => $sum('sgst'),
                'igst' => $sum('igst'),
                'tax' => $sum('tax'),
                'total' => $sum('net'),
            ],
        ];
    }

    /** Taxable value inside a GST-inclusive amount, rounded half away from zero to the paisa. */
    public static function taxable(int $inclusive, float $rate): int
    {
        if ($rate <= 0 || $inclusive === 0) {
            return $inclusive;
        }
        $divisor = 10000 + (int) round($rate * 100); // basis points: 18% → 11800
        $abs = abs($inclusive);
        $value = intdiv($abs * 10000 * 2 + $divisor, 2 * $divisor);

        return $inclusive < 0 ? -$value : $value;
    }

    /**
     * Split $amount across $weights in proportion, exactly (largest remainder), so the
     * shares always add up to $amount.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function allocate(array $weights, int $amount): array
    {
        $shares = array_fill(0, count($weights), 0);
        $total = array_sum($weights);
        if ($amount === 0 || $total <= 0) {
            return $shares;
        }
        $remainders = [];
        foreach ($weights as $i => $w) {
            $exact = $amount * $w;
            $shares[$i] = intdiv($exact, $total);
            $remainders[$i] = $exact % $total;
        }
        arsort($remainders);
        $left = $amount - array_sum($shares);
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $shares[$i]++;
            $left--;
        }

        return $shares;
    }
}
