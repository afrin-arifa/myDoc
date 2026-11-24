<?php

namespace App\Models\Concerns;

use OutOfRangeException;

/**
 * Shows a record in URLs as a short letters-only code instead of its database id. The code is calculated from the id,
 * so nothing is stored. It only changes how addresses look: it is not a secret, and policies still decide who may
 * open a record. Numbered URLs (older bookmarks and messages already sent) are still understood.
 */
trait HasUrlKey
{
    /**
     * Consonants only, so a code never spells a word or matches a fixed path such as "create".
     */
    private const string URL_KEY_LETTERS = 'bcdfghjklmnpqrstvwxz';

    private const int URL_KEY_LENGTH = 7;

    /**
     * 20^7: how many different seven-letter codes exist.
     */
    private const int URL_KEY_RANGE = 1_280_000_000;

    /**
     * Spreads neighbouring ids apart. The second value reverses the first (their product is 1 modulo the range).
     * Changing either, or the letters, changes every URL.
     */
    private const int URL_KEY_MULTIPLIER = 736_338_717;

    private const int URL_KEY_INVERSE = 616_544_053;

    /**
     * The code for an id, for links built from a foreign key without loading the record.
     */
    public static function urlKey(int $id): string
    {
        if ($id < 0 || $id >= self::URL_KEY_RANGE) {
            throw new OutOfRangeException("Id [{$id}] cannot be shown as a URL code.");
        }

        $number = ($id * self::URL_KEY_MULTIPLIER + self::urlKeyOffset()) % self::URL_KEY_RANGE;
        $key = '';

        for ($position = 0; $position < self::URL_KEY_LENGTH; $position++) {
            $key = self::URL_KEY_LETTERS[$number % 20].$key;
            $number = intdiv($number, 20);
        }

        return $key;
    }

    /**
     * The id a URL value stands for (a code or an older numeric id), or null when it is neither.
     */
    public static function idFromUrlKey(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        if (preg_match('/\A\d{1,18}\z/', $value) === 1) {
            return (int) $value;
        }

        if (strlen($value) !== self::URL_KEY_LENGTH || strspn($value, self::URL_KEY_LETTERS) !== self::URL_KEY_LENGTH) {
            return null;
        }

        $number = 0;

        foreach (str_split($value) as $letter) {
            $number = $number * 20 + (int) strpos(self::URL_KEY_LETTERS, $letter);
        }

        $number = ($number - self::urlKeyOffset() + self::URL_KEY_RANGE) % self::URL_KEY_RANGE;

        return ($number * self::URL_KEY_INVERSE) % self::URL_KEY_RANGE;
    }

    public function getRouteKey(): mixed
    {
        return $this->getKey() === null ? null : static::urlKey((int) $this->getKey());
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $field === null
            ? $query->whereKey(static::idFromUrlKey($value) ?? 0)
            : parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Different per table, so the same id has unrelated codes on different records.
     */
    private static function urlKeyOffset(): int
    {
        return crc32((new static)->getTable()) % self::URL_KEY_RANGE;
    }
}
