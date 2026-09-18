<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

/** Renders a list counter in a WordprocessingML number format (ST_NumberFormat), as Word displays it. */
final class NumberFormat
{
    private const array RUSSIAN = ['а', 'б', 'в', 'г', 'д', 'е', 'ж', 'з', 'и', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'э', 'ю', 'я'];

    private const array CARDINALS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const array TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const array CHICAGO = ['*', '†', '‡', '§'];

    public static function format(int $value, string $format): string
    {
        return match ($format) {
            'none', 'bullet' => '',
            'decimalZero' => $value >= 0 && $value < 10 ? '0'.$value : (string) $value,
            'upperRoman' => self::roman($value),
            'lowerRoman' => strtolower(self::roman($value)),
            'upperLetter' => strtoupper(self::repeatedLetter($value, range('a', 'z'))),
            'lowerLetter' => self::repeatedLetter($value, range('a', 'z')),
            'russianLower' => self::repeatedLetter($value, self::RUSSIAN),
            'russianUpper' => mb_strtoupper(self::repeatedLetter($value, self::RUSSIAN)),
            'ordinal' => $value.self::ordinalSuffix($value),
            'cardinalText' => ucfirst(self::cardinal($value)),
            'ordinalText' => ucfirst(self::ordinalWord($value)),
            'hex' => strtoupper(dechex(max(0, $value))),
            'chicago' => $value < 1 ? (string) $value : str_repeat(self::CHICAGO[($value - 1) % 4], intdiv($value - 1, 4) + 1),
            'decimalEnclosedCircle', 'decimalEnclosedCircleChinese' => $value >= 1 && $value <= 20 ? mb_chr(0x2460 + $value - 1) : (string) $value,
            'decimalEnclosedParen' => $value >= 1 && $value <= 20 ? mb_chr(0x2474 + $value - 1) : "({$value})",
            'decimalEnclosedFullstop' => $value >= 1 && $value <= 20 ? mb_chr(0x2488 + $value - 1) : "{$value}.",
            'decimalFullWidth', 'decimalFullWidth2' => self::fullWidth($value),
            default => (string) $value,
        };
    }

    private static function roman(int $value): string
    {
        if ($value < 1 || $value > 3999) {
            return (string) $value;
        }

        $result = '';

        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $numeral => $amount) {
            while ($value >= $amount) {
                $result .= $numeral;
                $value -= $amount;
            }
        }

        return $result;
    }

    /**
     * Word's letter numbering repeats the letter rather than counting in
     * base 26: a … z, aa … zz, aaa …
     *
     * @param  list<string>  $alphabet
     */
    private static function repeatedLetter(int $value, array $alphabet): string
    {
        if ($value < 1) {
            return (string) $value;
        }

        $count = count($alphabet);

        return str_repeat($alphabet[($value - 1) % $count], intdiv($value - 1, $count) + 1);
    }

    private static function ordinalSuffix(int $value): string
    {
        $lastTwo = abs($value) % 100;

        return match (true) {
            $lastTwo >= 11 && $lastTwo <= 13 => 'th',
            abs($value) % 10 === 1 => 'st',
            abs($value) % 10 === 2 => 'nd',
            abs($value) % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    private static function cardinal(int $value): string
    {
        if ($value < 0 || $value > 999999) {
            return (string) $value;
        }

        return match (true) {
            $value < 20 => self::CARDINALS[$value],
            $value < 100 => self::TENS[intdiv($value, 10)].($value % 10 === 0 ? '' : '-'.self::CARDINALS[$value % 10]),
            $value < 1000 => self::CARDINALS[intdiv($value, 100)].' hundred'.($value % 100 === 0 ? '' : ' '.self::cardinal($value % 100)),
            default => self::cardinal(intdiv($value, 1000)).' thousand'.($value % 1000 === 0 ? '' : ' '.self::cardinal($value % 1000)),
        };
    }

    private static function ordinalWord(int $value): string
    {
        $cardinal = self::cardinal($value);
        $irregular = ['one' => 'first', 'two' => 'second', 'three' => 'third', 'five' => 'fifth', 'eight' => 'eighth', 'nine' => 'ninth', 'twelve' => 'twelfth'];

        if (preg_match('/([a-z]+)$/', $cardinal, $match) !== 1) {
            return $cardinal;
        }

        $last = $match[1];
        $ordinal = $irregular[$last] ?? (str_ends_with($last, 'y') ? substr($last, 0, -1).'ieth' : $last.'th');

        return substr($cardinal, 0, -strlen($last)).$ordinal;
    }

    private static function fullWidth(int $value): string
    {
        return implode('', array_map(
            static fn (string $digit): string => ctype_digit($digit) ? mb_chr(0xFF10 + (int) $digit) : $digit,
            str_split((string) $value),
        ));
    }
}
