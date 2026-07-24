<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

final class OssPeriod
{
    public static function quarterCode(string $date): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $matches)) {
            return null;
        }
        $month = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            return null;
        }
        return sprintf('%sQ%d', $matches[1], intdiv($month - 1, 3) + 1);
    }

    /** @return array{0:string,1:string} */
    public static function range(int $year, int $quarter): array
    {
        $quarter = max(1, min(4, $quarter));
        $start = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
        $end = (new \DateTimeImmutable($start))->modify('+3 months -1 day')->format('Y-m-d');
        return [$start, $end];
    }
}
