<?php

namespace Pinecore\Pinecore\Orm;

/**
 * RFC 9562 UUIDv7: 48-bit Unix-ms timestamp + version + 12-bit counter + variant
 * + 62 random bits. Time-ordered (lexicographically sortable == chronological),
 * which makes it a good PRIMARY KEY for B-tree / index locality.
 *
 * Monotonic within a process: a per-millisecond counter (seeded randomly) is
 * incremented for IDs minted in the same millisecond and overflow bumps the
 * timestamp, so generate() is strictly increasing even under burst and across a
 * backwards clock step. No external dependencies — random_bytes + microtime.
 *
 * Keep a single shared instance (the DI default is a singleton) so the counter
 * state is preserved across calls.
 */
final class UuidV7Generator implements IdGeneratorInterface
{
    private const COUNTER_MAX = 0x0FFF; // 12 bits (rand_a field)

    private int $lastMs = 0;
    private int $counter = 0;

    public function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);

        if ($ms > $this->lastMs) {
            $this->lastMs  = $ms;
            $this->counter = random_int(0, self::COUNTER_MAX);
        } elseif (++$this->counter > self::COUNTER_MAX) {
            // Same millisecond (or clock went backwards) and the counter is
            // exhausted — advance our logical clock to stay monotonic.
            $this->lastMs++;
            $this->counter = random_int(0, self::COUNTER_MAX);
        }

        // 48-bit timestamp, big-endian: low 6 bytes of a 64-bit BE pack.
        $bytes = substr(pack('J', $this->lastMs), 2, 6);
        // version (0b0111) in the top nibble + 12-bit counter.
        $bytes .= pack('n', 0x7000 | ($this->counter & self::COUNTER_MAX));
        // 62 random bits with the variant (0b10) in the top two bits.
        $rand    = random_bytes(8);
        $rand[0] = chr((ord($rand[0]) & 0x3F) | 0x80);
        $bytes  .= $rand;

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
