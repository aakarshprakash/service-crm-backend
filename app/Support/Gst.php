<?php

namespace App\Support;

/**
 * GST breakup in integer paise only (no floats in any total). Prices include GST unless the caller
 * says they are before GST, in which case tax = round(taxable × rate / 100) is added to each line.
 *
 * For GST-inclusive prices:
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
    public static function breakup(array $lines, ?int $discount = 0, bool $interState = false, bool $pricesIncludeGst = true): array
    {
        $discount ??= 0;
        $shares = self::allocate(array_map(fn ($l) => max(0, (int) $l['gross']), $lines), $discount);

        $out = [];
        foreach ($lines as $i => $l) {
            $gross = (int) $l['gross'];
            $rate = round((float) $l['gst_rate'], 2);
            // Prices before GST: the line (after discount) is the taxable value and GST is added on top.
            $taxable = $pricesIncludeGst ? self::taxable($gross - $shares[$i], $rate) : $gross - $shares[$i];
            $tax = $pricesIncludeGst ? $gross - $shares[$i] - $taxable : self::taxOn($taxable, $rate);
            $net = $taxable + $tax;
            $cgst = $interState ? 0 : intdiv($tax, 2);
            $sgst = $interState ? 0 : $tax - $cgst;
            $qty = (float) $l['qty'];
            $out[] = [
                'type' => $l['type'],
                'description' => $l['description'],
                'details' => $l['details'] ?? null,
                'hsn' => $l['hsn'] ?: null,
                'uom' => $l['uom'] ?? null,
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

    /** GST added on top of a taxable value, rounded half away from zero to the paisa. */
    public static function taxOn(int $taxable, float $rate): int
    {
        if ($rate <= 0 || $taxable === 0) {
            return 0;
        }
        $bp = (int) round($rate * 100); // basis points: 18% → 1800
        $value = intdiv(abs($taxable) * $bp * 2 + 10000, 20000);

        return $taxable < 0 ? -$value : $value;
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
