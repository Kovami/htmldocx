<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Reader\Format\FormatParser;
use Kovami\HtmlDocx\Model\ListDefinition;
use Kovami\HtmlDocx\Model\ListLevel;
use Kovami\HtmlDocx\Model\NumberingReference;

/**
 * word/numbering.xml plus the running counters Word keeps while laying out
 * the document, so every numbered paragraph gets the number Word shows.
 *
 * Word counts per abstract definition: lists that share one continue each
 * other's numbering, unless a `w:num` overrides a start value, which gives
 * that instance counters of its own.
 */
final class Numbering
{
    /** @var array<string, array{levels: array<int, NumberingLevel>, styleLink: ?string}> */
    private array $abstracts = [];

    /** @var array<string, array{abstractId: string, overrides: array<int, array{start: ?int, level: ?NumberingLevel}>}> */
    private array $instances = [];

    /** @var array<string, array<int, int|null>> counter key => level => current value */
    private array $counters = [];

    /** @var array<string, array<int, NumberingLevel>> */
    private array $resolvedLevels = [];

    public function __construct(
        private readonly FormatParser $parser,
        private readonly StyleSheet $styles,
        ?XMLDocument $document = null,
    ) {
        $root = $document?->documentElement;

        foreach (Xml::children($root, 'abstractNum') as $abstract) {
            $levels = [];

            foreach (Xml::children($abstract, 'lvl') as $lvl) {
                $level = $this->level($lvl);
                $levels[$level->level] ??= $level;
            }

            $this->abstracts[(string) Xml::attr($abstract, 'abstractNumId')] ??= [
                'levels' => $levels,
                'styleLink' => Xml::val($abstract, 'numStyleLink'),
            ];
        }

        foreach (Xml::children($root, 'num') as $num) {
            $overrides = [];

            foreach (Xml::children($num, 'lvlOverride') as $override) {
                $ilvl = Xml::int(Xml::attr($override, 'ilvl')) ?? 0;
                $lvl = Xml::child($override, 'lvl');

                $overrides[$ilvl] = [
                    'start' => Xml::int(Xml::val($override, 'startOverride')),
                    'level' => $lvl === null ? null : $this->level($lvl, $ilvl),
                ];
            }

            $this->instances[(string) Xml::attr($num, 'numId')] ??= [
                'abstractId' => (string) Xml::val($num, 'abstractNumId'),
                'overrides' => $overrides,
            ];
        }
    }

    public function exists(string $numId): bool
    {
        return $this->levels($numId) !== [];
    }

    public function levelDefinition(string $numId, int $level): ?NumberingLevel
    {
        return $this->levels($numId)[$level] ?? null;
    }

    /** The level whose `w:pStyle` names the given paragraph style, as [numId-less level]. */
    public function levelForParagraphStyle(string $numId, string $styleId): ?int
    {
        foreach ($this->levels($numId) as $level) {
            if ($level->paragraphStyleId === $styleId) {
                return $level->level;
            }
        }

        return null;
    }

    /** Advances the counters for a numbered paragraph and returns its numbering. */
    public function next(string $numId, int $level): ?NumberingReference
    {
        $levels = $this->levels($numId);
        $definition = $levels[$level] ?? null;

        if ($definition === null) {
            return null;
        }

        $instance = $this->instances[$numId];
        $key = array_filter($instance['overrides'], static fn(array $override): bool => $override['start'] !== null) === []
            ? 'abstract:' . $this->abstractId($numId)
            : 'num:' . $numId;

        $counters = $this->counters[$key] ?? [];

        foreach ($levels as $deeper => $deeperDefinition) {
            if ($deeper <= $level) {
                continue;
            }

            $restart = $deeperDefinition->restartAfter;

            if ($restart === null || ($restart > 0 && $level <= $restart - 1)) {
                $counters[$deeper] = null;
            }
        }

        $counters[$level] = isset($counters[$level]) ? $counters[$level] + 1 : $this->start($numId, $level);
        $this->counters[$key] = $counters;

        return new NumberingReference(
            numId: (int) $numId,
            level: $level,
            ordinal: $counters[$level],
            label: $this->label($definition, $levels, $counters, $numId),
        );
    }

    /**
     * @return list<ListDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (array_keys($this->instances) as $numId) {
            $levels = $this->levels((string) $numId);

            if ($levels === [] || ! is_numeric($numId)) {
                continue;
            }

            $modelLevels = [];

            for ($i = 0; $i <= 8; $i++) {
                $level = $levels[$i] ?? new NumberingLevel($i);
                $modelLevels[] = new ListLevel(
                    level: $i,
                    format: $level->format,
                    text: $level->text ?? '',
                    start: $this->start((string) $numId, $i),
                    indentLeft: $level->paragraph->indentLeft ?? 0,
                    hanging: max(0, -($level->paragraph->firstLine ?? 0)),
                    suffix: $level->suffix,
                    symbolBullet: strtolower((string) $level->font) === 'symbol',
                    markerTab: $level->markerTab,
                );
            }

            $definitions[] = new ListDefinition((int) $numId, $modelLevels, 0);
        }

        return $definitions;
    }

    /**
     * @return array<int, NumberingLevel>
     */
    private function levels(string $numId): array
    {
        if (isset($this->resolvedLevels[$numId])) {
            return $this->resolvedLevels[$numId];
        }

        $abstractId = $this->abstractId($numId);
        $levels = $abstractId === null ? [] : $this->abstracts[$abstractId]['levels'];

        foreach ($this->instances[$numId]['overrides'] ?? [] as $ilvl => $override) {
            if ($override['level'] !== null) {
                $levels[$ilvl] = $override['level'];
            }
        }

        ksort($levels);

        return $this->resolvedLevels[$numId] = $levels;
    }

    /** Follows `w:numStyleLink` through a numbering style to the definition that holds the levels. */
    private function abstractId(string $numId, int $depth = 0): ?string
    {
        $abstractId = $this->instances[$numId]['abstractId'] ?? null;
        $link = $abstractId === null ? null : ($this->abstracts[$abstractId]['styleLink'] ?? null);

        if ($link === null || $depth > 8) {
            return $abstractId !== null && isset($this->abstracts[$abstractId]) ? $abstractId : null;
        }

        $linkedNumId = $this->styles->numberingStyleNumId($link);

        return $linkedNumId === null || $linkedNumId === $numId ? null : $this->abstractId($linkedNumId, $depth + 1);
    }

    private function start(string $numId, int $level): int
    {
        return $this->instances[$numId]['overrides'][$level]['start']
            ?? $this->levels($numId)[$level]->start
            ?? 0;
    }

    /**
     * @param  array<int, NumberingLevel>  $levels
     * @param  array<int, int|null>  $counters
     */
    private function label(NumberingLevel $definition, array $levels, array $counters, string $numId): string
    {
        if ($definition->text === null) {
            return '';
        }

        if ($definition->format === 'bullet') {
            return $definition->text;
        }

        return (string) preg_replace_callback('/%([1-9])/', function (array $match) use ($definition, $levels, $counters, $numId): string {
            $referenced = (int) $match[1] - 1;
            $format = $definition->legal ? 'decimal' : ($levels[$referenced]->format ?? 'decimal');

            return NumberFormat::format($counters[$referenced] ?? $this->start($numId, $referenced), $format);
        }, $definition->text);
    }

    private function level(Element $lvl, ?int $ilvl = null): NumberingLevel
    {
        $run = $this->parser->run(Xml::child($lvl, 'rPr'));
        $textElement = Xml::child($lvl, 'lvlText');
        $text = $textElement === null ? null : Xml::attr($textElement, 'val');
        $symbolFont = Xml::attr(Xml::child(Xml::child($lvl, 'rPr'), 'rFonts'), 'ascii')
            ?? Xml::attr(Xml::child(Xml::child($lvl, 'rPr'), 'rFonts'), 'hAnsi');

        $format = Xml::val($lvl, 'numFmt') ?? 'decimal';

        if ($text !== null && $format === 'bullet') {
            $text = self::mapSymbols($text, $symbolFont);
        }

        return new NumberingLevel(
            level: $ilvl ?? Xml::int(Xml::attr($lvl, 'ilvl')) ?? 0,
            start: Xml::int(Xml::val($lvl, 'start')) ?? 0,
            format: $format,
            text: $text,
            restartAfter: Xml::int(Xml::val($lvl, 'lvlRestart')),
            legal: Xml::onOff($lvl, 'isLgl') ?? false,
            paragraphStyleId: Xml::val($lvl, 'pStyle'),
            font: $symbolFont,
            suffix: Xml::val($lvl, 'suff') ?? 'tab',
            markerTab: self::markerTab(Xml::child(Xml::child($lvl, 'pPr'), 'tabs')),
            paragraph: $this->parser->paragraph(Xml::child($lvl, 'pPr')),
            run: $run,
        );
    }

    /** The level's own tab stop for the text after its marker (`w:tab w:val="num"`). */
    private static function markerTab(?Element $tabs): ?int
    {
        foreach (Xml::children($tabs, 'tab') as $tab) {
            if (Xml::attr($tab, 'val') === 'num') {
                return Xml::int(Xml::attr($tab, 'pos'));
            }
        }

        return null;
    }

    /** Bullets drawn from symbol fonts are private-use or Latin-1 code points; map them to real characters. */
    private static function mapSymbols(string $text, ?string $font): string
    {
        return (string) preg_replace_callback('/[\x{F020}-\x{F0FF}\x{20}-\x{FF}]/u', static function (array $match) use ($font): string {
            $code = mb_ord($match[0]);

            if ($font !== null && SymbolFonts::isSymbolFont($font)) {
                return SymbolFonts::toUnicode($font, $code) ?? $match[0];
            }

            return $code >= 0xF020 ? (SymbolFonts::toUnicode('Symbol', $code) ?? $match[0]) : $match[0];
        }, $text);
    }
}
