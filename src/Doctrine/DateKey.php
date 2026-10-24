<?php

namespace App\Doctrine;

/**
 * Date usable as part of a composite primary key (Doctrine builds identity hashes by string concatenation).
 */
final class DateKey extends \DateTimeImmutable implements \Stringable
{
    public static function fromDate(\DateTimeInterface $date): self
    {
        return new self($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return $this->format('Y-m-d');
    }
}
