<?php

namespace Cijber\Defer\Utils;

class HRTime
{
    const NANOS_PER_SECOND = 1_000_000_000;

    public function __construct(
        public int $seconds,
        public int $nanos = 0,
    )
    {

    }

    public static function now(): static
    {
        $n = hrtime();
        return new static($n[0], $n[1]);
    }

    function toNumber(): int|float
    {
        if (PHP_INT_SIZE === 4) {
            return (((float)$this->seconds * (float)self::NANOS_PER_SECOND) + (float)$this->nanos);
        } else {
            return ($this->seconds * self::NANOS_PER_SECOND) + $this->nanos;
        }
    }

    function sub(HRTime $rhs):HRTime
    {
        $nanos = $this->nanos = $rhs->nanos;
        $secs = $this->seconds - $rhs->seconds;
        if ($nanos < 0) {
            $nanos = self::NANOS_PER_SECOND + $nanos;
            $secs--;
        }

        return new HRTime($secs, $nanos);
    }

    function add(HRTime $rhs):HRTime
    {
        $nanos = $this->nanos + $rhs->nanos;
        $secs = $this->seconds + $rhs->seconds;

        if ($nanos > self::NANOS_PER_SECOND) {
            $nanos -= self::NANOS_PER_SECOND;
            $secs += 1;
        }

        return new HRTime($secs, $nanos);
    }

    function sleep(): void
    {
        if ($this->seconds < 0 || ($this->seconds == 0 && $this->nanos <= 0)) {
            return;
        }

        time_nanosleep($this->seconds, $this->nanos);
    }
}