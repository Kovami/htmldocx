<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\FontMetrics;
use Kovami\HtmlDocx\Model\BreakRun;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TabRun;
use Kovami\HtmlDocx\Model\TextRun;

/**
 * Accumulates the inline content of one paragraph and applies CSS
 * white-space processing across element boundaries: collapsible spaces
 * merge, disappear at line starts/ends, and a trailing `<br>` in a block
 * produces no extra line, exactly like browser rendering. Comment
 * boundaries take no room, so white-space processing looks through them.
 */
final class InlineBuffer
{
    /** @var list<array{run: TextRun|BreakRun|TabRun|ImageRun|Formula|NoteReference|Field, link: LinkTarget|null, collapsible: bool}> */
    private array $items = [];

    /** The paragraph's alignment when its content sets it (a picture between auto margins), else null. */
    public ?string $alignment = null;

    /** @var array{0: float, 1: float} how much the browser grows the paragraph's lines to hold its scripts, in points; see FontMetrics::scriptGrowth() */
    public array $scriptGrowth = [0.0, 0.0];

    /** Twips a picture shown as a block moves right by its own left margin; its paragraph takes them as indent. */
    public int $pictureIndent = 0;

    /** Room a browser leaves under a picture standing on the baseline, in points; see FontMetrics::belowBaseline() */
    public float $pictureGap = 0.0;

    /** @var array<int, list<CommentStart|CommentEnd>> comment boundaries, by the index of the item they come before */
    private array $boundaries = [];

    private bool $atLineStart = true;

    private bool $hasLineBreak = false;

    public function appendText(string $text, RunProperties $properties, ComputedStyle $style, ?LinkTarget $link): void
    {
        if (trim($text) !== '') {
            [$above, $below] = FontMetrics::scriptGrowth($style);
            $this->scriptGrowth = [max($this->scriptGrowth[0], $above), max($this->scriptGrowth[1], $below)];
        }

        $text = self::transform(str_replace(["\r\n", "\r"], "\n", $text), $style->textTransform);

        if ($style->preservesWhitespace()) {
            foreach (explode("\n", $text) as $lineIndex => $line) {
                if ($lineIndex > 0) {
                    $this->appendBreak($properties, $link);
                }

                foreach (explode("\t", $line) as $segmentIndex => $segment) {
                    if ($segmentIndex > 0) {
                        $this->push(new TabRun($properties), $link);
                    }

                    if ($segment !== '') {
                        $this->pushText($segment, $properties, $link, false);
                    }
                }
            }

            return;
        }

        $lines = $style->whiteSpace === 'pre-line' ? explode("\n", $text) : [$text];

        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $this->appendBreak($properties, $link);
            }

            $this->appendCollapsed($line, $properties, $link);
        }
    }

    public function appendBreak(RunProperties $properties, ?LinkTarget $link): void
    {
        $this->trimTrailingSpace();
        $this->items[] = ['run' => new BreakRun($properties), 'link' => $link, 'collapsible' => false];
        $this->atLineStart = true;
        $this->hasLineBreak = true;
    }

    public function appendImage(ImageRun $image, ?LinkTarget $link): void
    {
        $this->push($image, $link);
    }

    public function appendFormula(Formula $formula, ?LinkTarget $link): void
    {
        $this->push($formula, $link);
    }

    public function appendNoteReference(NoteReference $reference, ?LinkTarget $link): void
    {
        $this->push($reference, $link);
    }

    public function appendField(Field $field, ?LinkTarget $link): void
    {
        $this->push($field, $link);
    }

    public function appendCommentBoundary(CommentStart|CommentEnd $boundary): void
    {
        $this->boundaries[count($this->items)][] = $boundary;
    }

    /**
     * @return list<Inline>|null the paragraph content, or null when the
     *                           buffer produced nothing that would render a line
     */
    public function finish(): ?array
    {
        $this->trimTrailingSpace();

        $last = $this->items[array_key_last($this->items) ?? 0]['run'] ?? null;

        if ($last instanceof BreakRun) {
            $this->popItem();
            $this->trimTrailingSpace();
        }

        if ($this->items === []) {
            return $this->hasLineBreak ? ($this->boundaries[0] ?? []) : null;
        }

        return $this->group();
    }

    private function appendCollapsed(string $text, RunProperties $properties, ?LinkTarget $link): void
    {
        $text = (string) preg_replace('/[ \t\n\f]+/', ' ', $text);

        if (str_starts_with($text, ' ') && ($this->atLineStart || $this->endsWithCollapsibleSpace())) {
            $text = substr($text, 1);
        }

        if ($text !== '') {
            $this->pushText($text, $properties, $link, true);
        }
    }

    private function pushText(string $text, RunProperties $properties, ?LinkTarget $link, bool $collapsible): void
    {
        $lastKey = array_key_last($this->items);

        if ($lastKey !== null) {
            $last = $this->items[$lastKey];

            if ($last['run'] instanceof TextRun
                && $last['collapsible'] === $collapsible
                && $last['run']->properties->equals($properties)
                && ($link === null ? $last['link'] === null : $link->equals($last['link']))
                && ! isset($this->boundaries[count($this->items)])) {
                $this->items[$lastKey]['run'] = new TextRun($last['run']->text . $text, $properties);
                $this->atLineStart = false;

                return;
            }
        }

        $this->push(new TextRun($text, $properties), $link, $collapsible);
    }

    private function push(TextRun|BreakRun|TabRun|ImageRun|Formula|NoteReference|Field $run, ?LinkTarget $link, bool $collapsible = false): void
    {
        $this->items[] = ['run' => $run, 'link' => $link, 'collapsible' => $collapsible];
        $this->atLineStart = false;
    }

    private function endsWithCollapsibleSpace(): bool
    {
        $lastKey = array_key_last($this->items);

        if ($lastKey === null) {
            return false;
        }

        $last = $this->items[$lastKey];

        return $last['collapsible'] && $last['run'] instanceof TextRun && str_ends_with($last['run']->text, ' ');
    }

    private function trimTrailingSpace(): void
    {
        while (($lastKey = array_key_last($this->items)) !== null) {
            $last = $this->items[$lastKey];

            if (! $last['collapsible'] || ! $last['run'] instanceof TextRun) {
                return;
            }

            $trimmed = rtrim($last['run']->text, ' ');

            if ($trimmed !== '') {
                $this->items[$lastKey]['run'] = new TextRun($trimmed, $last['run']->properties);

                return;
            }

            $this->popItem();
        }
    }

    /** Drops the last item; boundaries that followed it now follow its predecessor. */
    private function popItem(): void
    {
        array_pop($this->items);
        $index = count($this->items);
        $following = $this->boundaries[$index + 1] ?? [];
        unset($this->boundaries[$index + 1]);

        if ($following !== []) {
            $this->boundaries[$index] = [...($this->boundaries[$index] ?? []), ...$following];
        }
    }

    /**
     * @return list<Inline>
     */
    private function group(): array
    {
        $sequence = [];

        foreach ($this->items as $index => $item) {
            foreach ($this->boundaries[$index] ?? [] as $boundary) {
                $sequence[] = ['run' => $boundary, 'link' => null];
            }

            $sequence[] = $item;
        }

        foreach ($this->boundaries[count($this->items)] ?? [] as $boundary) {
            $sequence[] = ['run' => $boundary, 'link' => null];
        }

        $result = [];
        $group = [];
        $groupLink = null;

        foreach ($sequence as $item) {
            $link = $item['link'];

            if ($groupLink !== null && ($link === null || ! $link->equals($groupLink))) {
                $result[] = new Hyperlink($groupLink->url, $groupLink->anchor, $group);
                $group = [];
                $groupLink = null;
            }

            if ($link === null) {
                $result[] = $item['run'];

                continue;
            }

            $groupLink = $link;
            $group[] = $item['run'];
        }

        if ($groupLink !== null) {
            $result[] = new Hyperlink($groupLink->url, $groupLink->anchor, $group);
        }

        return $result;
    }

    private static function transform(string $text, string $textTransform): string
    {
        return match ($textTransform) {
            'lowercase' => mb_strtolower($text),
            'capitalize' => (string) preg_replace_callback(
                '/(^|[\s\p{Ps}\p{Pi}"\'-])(\p{Ll})/u',
                static fn(array $m): string => $m[1] . mb_strtoupper($m[2]),
                $text,
            ),
            default => $text,
        };
    }
}
