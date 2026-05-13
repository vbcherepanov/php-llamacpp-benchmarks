<?php

declare(strict_types=1);

namespace PhpLlamaBench;

final class Stats
{
    /**
     * Linear-interpolation percentile on a sorted sample.
     *
     * @param list<int|float> $sortedNs
     */
    public static function percentile(array $sortedNs, float $p): float
    {
        $n = count($sortedNs);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sortedNs[0];
        }
        $rank = ($p / 100.0) * ($n - 1);
        $low  = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sortedNs[$low];
        }
        $frac = $rank - $low;
        return ((float) $sortedNs[$low]) * (1.0 - $frac)
             + ((float) $sortedNs[$high]) * $frac;
    }

    /**
     * @param list<int|float> $samples
     */
    public static function mean(array $samples): float
    {
        $n = count($samples);
        if ($n === 0) {
            return 0.0;
        }
        return array_sum($samples) / $n;
    }

    /**
     * Sample standard deviation (Bessel-corrected).
     *
     * @param list<int|float> $samples
     */
    public static function stddev(array $samples): float
    {
        $n = count($samples);
        if ($n < 2) {
            return 0.0;
        }
        $mean = self::mean($samples);
        $sumSq = 0.0;
        foreach ($samples as $v) {
            $d = ((float) $v) - $mean;
            $sumSq += $d * $d;
        }
        return sqrt($sumSq / ($n - 1));
    }

    public static function formatNs(int|float $ns): string
    {
        $ns = (float) $ns;
        if ($ns < 1_000.0) {
            return sprintf('%.0f ns', $ns);
        }
        if ($ns < 1_000_000.0) {
            return sprintf('%.2f µs', $ns / 1_000.0);
        }
        if ($ns < 1_000_000_000.0) {
            return sprintf('%.2f ms', $ns / 1_000_000.0);
        }
        return sprintf('%.3f s', $ns / 1_000_000_000.0);
    }

    public static function formatBytes(int|float $bytes): string
    {
        $b = (float) $bytes;
        if ($b < 1024.0) {
            return sprintf('%d B', (int) $b);
        }
        if ($b < 1024.0 ** 2) {
            return sprintf('%.2f KB', $b / 1024.0);
        }
        if ($b < 1024.0 ** 3) {
            return sprintf('%.2f MB', $b / (1024.0 ** 2));
        }
        return sprintf('%.2f GB', $b / (1024.0 ** 3));
    }

    public static function formatRatio(float $naive, float $optimized): string
    {
        if ($naive <= 0.0 && $optimized <= 0.0) {
            return 'n/a';
        }
        if ($optimized <= 0.0) {
            return '∞';
        }
        $ratio = $naive / $optimized;
        if ($ratio >= 1.0) {
            return sprintf('%.2f×', $ratio);
        }
        return sprintf('%.2f× (worse)', $ratio);
    }

    /**
     * Read process resident-set size on Linux (bytes). Returns 0 on other OS.
     */
    public static function processRss(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0;
        }
        $status = @file_get_contents('/proc/self/status');
        if ($status === false) {
            return 0;
        }
        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m) === 1) {
            return ((int) $m[1]) * 1024;
        }
        return 0;
    }
}
