<?php

declare(strict_types=1);

final class DatanixDashboardBuilder
{
    private static function find(array $schema, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            foreach ($schema as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name !== '' && preg_match($pattern, mb_strtolower($name, 'UTF-8'))) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * Builds up to six data-backed dashboard widgets from the real schema.
     * It never fabricates a KPI: every question must map to a present column.
     */
    public static function suggestions(array $schema, string $prompt, int $maximum = 6): array
    {
        if (mb_strlen(trim($prompt), 'UTF-8') < 3 || mb_strlen($prompt, 'UTF-8') > 400) {
            throw new RuntimeException('Prompt dashboard harus 3–400 karakter.');
        }

        $revenue = self::find($schema, ['/(?:revenue|sales|amount|omzet|pendapatan|penjualan|total[_ ]?price|line[_ ]?total|nilai[_ ]?transaksi)/u']);
        $quantity = self::find($schema, ['/(?:quantity|qty|jumlah|units?|terjual)/u']);
        $price = self::find($schema, ['/(?:unit[_ ]?price|price|harga|tarif)/u']);
        $orders = self::find($schema, ['/(?:invoice|order[_ ]?(?:id|no|number|kode)|transaction[_ ]?id|pesanan)/u']);
        $customers = self::find($schema, ['/(?:customer|pelanggan|buyer|client)[_ ]?(?:id|name)?/u']);
        $conversions = self::find($schema, ['/(?:conversion|conversions|order|orders|purchase)/u']);
        $visits = self::find($schema, ['/(?:session|visit|traffic|pengunjung)/u']);
        $product = self::find($schema, ['/(?:product|produk|item|barang|stockcode|stock[_ ]?code|description)/u']);
        $country = self::find($schema, ['/(?:country|negara|region|state|city|province|wilayah)/u']);
        $date = self::find($schema, ['/(?:date|time|tanggal|waktu|period|month|year)/u']);
        $hasRevenue = $revenue !== null || ($quantity !== null && $price !== null);
        $items = [];

        if ($hasRevenue) {
            $items[] = ['title' => 'Revenue overview', 'question' => 'total revenue', 'type' => 'metric'];
        }
        if ($orders) {
            $items[] = ['title' => 'Orders', 'question' => 'jumlah transaksi', 'type' => 'metric'];
        }
        if ($customers) {
            $items[] = ['title' => 'Unique customers', 'question' => 'berapa total customer', 'type' => 'metric'];
        }
        if ($conversions && $visits) {
            $items[] = ['title' => 'Conversion rate', 'question' => 'conversion rate', 'type' => 'metric'];
        }
        if ($date && $hasRevenue) {
            $items[] = ['title' => 'Revenue trend', 'question' => 'trend revenue per bulan', 'type' => 'line'];
        } elseif ($date && $quantity) {
            $items[] = ['title' => 'Volume trend', 'question' => 'trend total quantity per bulan', 'type' => 'line'];
        }
        if ($product && $hasRevenue) {
            $items[] = ['title' => 'Top products', 'question' => 'top 10 product by revenue', 'type' => 'bar'];
        } elseif ($product && $quantity) {
            $items[] = ['title' => 'Top products', 'question' => 'top 10 product by quantity', 'type' => 'bar'];
        }
        if ($country && $hasRevenue) {
            $items[] = ['title' => 'Revenue by geography', 'question' => 'top 10 country by revenue', 'type' => 'donut'];
        }
        if (!$items) {
            throw new RuntimeException('Schema belum memuat metrik atau kolom dimensi yang dapat dipetakan. Coba dataset dengan kolom transaksi, nilai, tanggal, produk, atau pelanggan.');
        }
        return array_slice($items, 0, max(1, min(12, $maximum)));
    }
}
