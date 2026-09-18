<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Tests\Support;

use DOMElement;

/**
 * Structural checks for the defects that make Word refuse or "repair" a
 * file: schema element order, dangling references (relationships, styles,
 * numbering, bookmarks), inconsistent table grids, duplicate ids, and
 * package-level content type/relationship errors.
 */
final class DocxIntegrity
{
    /** Elements the schema lets appear several times in a row. */
    private const array REPEATABLE = ['headerReference', 'footerReference'];

    private const array ORDER = [
        'pPr' => ['pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr', 'suppressLineNumbers',
            'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE',
            'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing', 'mirrorIndents',
            'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr',
            'sectPr', 'pPrChange'],
        'rPr' => ['rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike', 'outline', 'shadow', 'emboss',
            'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing', 'w', 'kern', 'position', 'sz', 'szCs',
            'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath'],
        'tblPr' => ['tblStyle', 'tblpPr', 'tblOverlap', 'bidiVisual', 'tblStyleRowBandSize', 'tblStyleColBandSize', 'tblW', 'jc',
            'tblCellSpacing', 'tblInd', 'tblBorders', 'shd', 'tblLayout', 'tblCellMar', 'tblLook'],
        'tcPr' => ['cnfStyle', 'tcW', 'gridSpan', 'hMerge', 'vMerge', 'tcBorders', 'shd', 'noWrap', 'tcMar', 'textDirection', 'tcFitText', 'vAlign', 'hideMark'],
        'pBdr' => ['top', 'left', 'bottom', 'right', 'between', 'bar'],
        'tcBorders' => ['top', 'left', 'bottom', 'right', 'insideH', 'insideV', 'tl2br', 'tr2bl'],
        'tblBorders' => ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'],
        'tcMar' => ['top', 'left', 'bottom', 'right'],
        'tblCellMar' => ['top', 'left', 'bottom', 'right'],
        'lvl' => ['start', 'numFmt', 'lvlRestart', 'pStyle', 'isLgl', 'suff', 'lvlText', 'lvlPicBulletId', 'legacy', 'lvlJc', 'pPr', 'rPr'],
        'style' => ['name', 'aliases', 'basedOn', 'next', 'link', 'autoRedefine', 'hidden', 'uiPriority', 'semiHidden', 'unhideWhenUsed',
            'qFormat', 'locked', 'personal', 'personalCompose', 'personalReply', 'rsid', 'pPr', 'rPr', 'tblPr', 'trPr', 'tcPr', 'tblStylePr'],
        'sectPr' => ['headerReference', 'footerReference', 'footnotePr', 'endnotePr', 'type', 'pgSz', 'pgMar', 'paperSrc', 'pgBorders',
            'lnNumType', 'pgNumType', 'cols', 'formProt', 'vAlign', 'noEndnote', 'titlePg', 'textDirection', 'bidi', 'rtlGutter', 'docGrid'],
        'settings' => ['writeProtection', 'view', 'zoom', 'removePersonalInformation', 'removeDateAndTime', 'doNotDisplayPageBoundaries',
            'displayBackgroundShape', 'printPostScriptOverText', 'printFractionalCharacterWidth', 'printFormsData', 'embedTrueTypeFonts',
            'embedSystemFonts', 'saveSubsetFonts', 'saveFormsData', 'mirrorMargins', 'alignBordersAndEdges', 'bordersDoNotSurroundHeader',
            'bordersDoNotSurroundFooter', 'gutterAtTop', 'hideSpellingErrors', 'hideGrammaticalErrors', 'activeWritingStyle', 'proofState',
            'formsDesign', 'attachedTemplate', 'linkStyles', 'stylePaneFormatFilter', 'stylePaneSortMethod', 'documentType', 'mailMerge',
            'revisionView', 'trackRevisions', 'doNotTrackMoves', 'doNotTrackFormatting', 'documentProtection', 'autoFormatOverride',
            'styleLockTheme', 'styleLockQFSet', 'defaultTabStop', 'autoHyphenation', 'consecutiveHyphenLimit', 'hyphenationZone',
            'doNotHyphenateCaps', 'showEnvelope', 'summaryLength', 'clickAndTypeStyle', 'defaultTableStyle', 'evenAndOddHeaders',
            'bookFoldRevPrinting', 'bookFoldPrinting', 'bookFoldPrintingSheets', 'drawingGridHorizontalSpacing', 'drawingGridVerticalSpacing',
            'displayHorizontalDrawingGridEvery', 'displayVerticalDrawingGridEvery', 'doNotUseMarginsForDrawingGridOrigin',
            'drawingGridHorizontalOrigin', 'drawingGridVerticalOrigin', 'doNotShadeFormData', 'noPunctuationKerning',
            'characterSpacingControl', 'footnotePr', 'endnotePr', 'compat'],
    ];

    /**
     * @return list<string> human-readable violations; empty when the package is sound
     */
    public static function violations(Docx $docx): array
    {
        $errors = [];

        self::checkPackage($docx, $errors);

        if ($errors !== []) {
            return $errors;
        }

        foreach (array_keys($docx->parts) as $part) {
            if (str_ends_with($part, '.xml') || str_ends_with($part, '.rels')) {
                $docx->xpath($part);
            }
        }

        self::checkElementOrder($docx, $errors);
        self::checkDocumentStructure($docx, $errors);
        self::checkReferences($docx, $errors);
        self::checkTables($docx, $errors);

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkPackage(Docx $docx, array &$errors): void
    {
        $names = array_keys($docx->parts);

        if (($names[0] ?? null) !== '[Content_Types].xml') {
            $errors[] = '[Content_Types].xml must be the first archive entry';
        }

        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/_rels/document.xml.rels', 'word/styles.xml'] as $required) {
            if (! $docx->has($required)) {
                $errors[] = "missing part {$required}";
            }
        }

        if ($errors !== []) {
            return;
        }

        $defaults = [];
        $overrides = [];

        foreach ($docx->query('/ct:Types/ct:Default', null, '[Content_Types].xml') as $default) {
            $defaults[strtolower($default->getAttribute('Extension'))] = true;
        }

        foreach ($docx->query('/ct:Types/ct:Override', null, '[Content_Types].xml') as $override) {
            $overrides[ltrim($override->getAttribute('PartName'), '/')] = true;
        }

        foreach ($names as $name) {
            if ($name !== '[Content_Types].xml' && ! isset($overrides[$name]) && ! isset($defaults[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
                $errors[] = "part {$name} has no content type";
            }
        }

        foreach (array_keys($overrides) as $name) {
            if (! $docx->has($name)) {
                $errors[] = "content type override for missing part {$name}";
            }
        }

        foreach (['_rels/.rels' => '', 'word/_rels/document.xml.rels' => 'word/'] as $relsPart => $baseDir) {
            foreach ($docx->query('/rel:Relationships/rel:Relationship', null, $relsPart) as $relationship) {
                if ($relationship->getAttribute('TargetMode') !== 'External' && ! $docx->has($baseDir . $relationship->getAttribute('Target'))) {
                    $errors[] = "{$relsPart} targets missing part {$relationship->getAttribute('Target')}";
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkElementOrder(Docx $docx, array &$errors): void
    {
        $parts = ['word/document.xml', 'word/styles.xml', 'word/settings.xml'];

        if ($docx->has('word/numbering.xml')) {
            $parts[] = 'word/numbering.xml';
        }

        foreach ($parts as $part) {
            foreach (self::ORDER as $element => $order) {
                foreach ($docx->query("//w:{$element}", null, $part) as $node) {
                    $previous = -1;

                    foreach ($node->childNodes as $child) {
                        if (! $child instanceof DOMElement) {
                            continue;
                        }

                        $position = array_search($child->localName, $order, true);

                        if ($position === false) {
                            $errors[] = "{$part}: unexpected w:{$child->localName} inside w:{$element}";
                        } elseif ($position < $previous || ($position === $previous && ! in_array($child->localName, self::REPEATABLE, true))) {
                            $errors[] = "{$part}: w:{$child->localName} is out of order inside w:{$element}";
                        } else {
                            $previous = $position;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkDocumentStructure(Docx $docx, array &$errors): void
    {
        $body = $docx->first('/w:document/w:body');
        $last = $body?->lastElementChild;

        if ($last?->localName !== 'sectPr') {
            $errors[] = 'w:sectPr must be the last child of w:body';
        }

        foreach ($docx->query('//w:p[w:pPr and not(*[1][self::w:pPr])]') as $ignored) {
            $errors[] = 'w:pPr must be the first child of w:p';
        }

        foreach ($docx->query('//w:tc') as $cell) {
            $blocks = $docx->query('w:p | w:tbl', $cell);

            if ($blocks === [] || end($blocks)->localName !== 'p') {
                $errors[] = 'every w:tc must end with a w:p';
            }
        }

        foreach ($docx->query('//w:tbl[following-sibling::*[1][self::w:tbl]]') as $ignored) {
            $errors[] = 'adjacent w:tbl elements would be merged by Word';
        }

        $bookmarkStarts = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'id'), $docx->query('//w:bookmarkStart'));
        $bookmarkEnds = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'id'), $docx->query('//w:bookmarkEnd'));

        if (count($bookmarkStarts) !== count(array_unique($bookmarkStarts))) {
            $errors[] = 'duplicate bookmark ids';
        }

        sort($bookmarkStarts);
        sort($bookmarkEnds);

        if ($bookmarkStarts !== $bookmarkEnds) {
            $errors[] = 'unbalanced bookmarkStart/bookmarkEnd';
        }

        $drawingIds = array_map(static fn(DOMElement $e): string => $e->getAttribute('id'), $docx->query('//wp:docPr'));

        if (count($drawingIds) !== count(array_unique($drawingIds))) {
            $errors[] = 'duplicate wp:docPr ids';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkReferences(Docx $docx, array &$errors): void
    {
        $relationships = [];

        foreach ($docx->query('/rel:Relationships/rel:Relationship', null, 'word/_rels/document.xml.rels') as $relationship) {
            $relationships[$relationship->getAttribute('Id')] = $relationship;
        }

        foreach ($docx->query('//w:hyperlink[@r:id]') as $hyperlink) {
            $relationship = $relationships[$hyperlink->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;

            if ($relationship === null || ! str_ends_with($relationship->getAttribute('Type'), '/hyperlink') || $relationship->getAttribute('TargetMode') !== 'External') {
                $errors[] = 'hyperlink r:id does not point to an external hyperlink relationship';
            }
        }

        foreach ($docx->query('//a:blip') as $blip) {
            $relationship = $relationships[$blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')] ?? null;

            if ($relationship === null || ! str_ends_with($relationship->getAttribute('Type'), '/image')) {
                $errors[] = 'a:blip r:embed does not point to an image relationship';
            }
        }

        $bookmarkNames = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'name'), $docx->query('//w:bookmarkStart'));

        foreach ($docx->query('//w:hyperlink[@w:anchor]') as $hyperlink) {
            if (! in_array(Docx::attr($hyperlink, 'anchor'), $bookmarkNames, true)) {
                $errors[] = 'hyperlink anchor ' . Docx::attr($hyperlink, 'anchor') . ' has no bookmark';
            }
        }

        $styleIds = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'styleId'), $docx->query('//w:style', null, 'word/styles.xml'));

        foreach ($docx->query('//w:pStyle | //w:basedOn | //w:next', null, 'word/styles.xml') as $reference) {
            if (! in_array(Docx::attr($reference, 'val'), $styleIds, true)) {
                $errors[] = 'styles.xml references unknown style ' . Docx::attr($reference, 'val');
            }
        }

        foreach ($docx->query('//w:pStyle') as $reference) {
            if (! in_array(Docx::attr($reference, 'val'), $styleIds, true)) {
                $errors[] = 'document references unknown style ' . Docx::attr($reference, 'val');
            }
        }

        $usedNumIds = array_unique(array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'val'), $docx->query('//w:numPr/w:numId')));

        if ($usedNumIds === []) {
            return;
        }

        if (! $docx->has('word/numbering.xml')) {
            $errors[] = 'paragraphs use numbering but word/numbering.xml is missing';

            return;
        }

        $abstractIds = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'abstractNumId'), $docx->query('//w:abstractNum', null, 'word/numbering.xml'));
        $numIds = [];

        foreach ($docx->query('//w:num', null, 'word/numbering.xml') as $num) {
            $numIds[] = (string) Docx::attr($num, 'numId');

            if (! in_array($docx->val('w:abstractNumId', $num, 'word/numbering.xml'), $abstractIds, true)) {
                $errors[] = 'w:num references a missing w:abstractNum';
            }
        }

        foreach ($docx->query('//w:abstractNum', null, 'word/numbering.xml') as $abstract) {
            $levels = array_map(static fn(DOMElement $e): string => (string) Docx::attr($e, 'ilvl'), $docx->query('w:lvl', $abstract, 'word/numbering.xml'));

            if ($levels !== ['0', '1', '2', '3', '4', '5', '6', '7', '8']) {
                $errors[] = 'w:abstractNum must define levels 0-8 in order';
            }
        }

        foreach ($usedNumIds as $numId) {
            if (! in_array($numId, $numIds, true)) {
                $errors[] = "numId {$numId} is not defined";
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkTables(Docx $docx, array &$errors): void
    {
        foreach ($docx->query('//w:tbl') as $table) {
            $gridWidths = array_map(static fn(DOMElement $e): int => (int) Docx::attr($e, 'w'), $docx->query('w:tblGrid/w:gridCol', $table));
            $columns = count($gridWidths);

            if ($columns === 0) {
                $errors[] = 'table without grid columns';

                continue;
            }

            $previousRowMerges = [];

            foreach ($docx->query('w:tr', $table) as $rowIndex => $row) {
                $position = 0;
                $rowMerges = [];

                foreach ($docx->query('w:tc', $row) as $cell) {
                    $span = (int) ($docx->val('w:tcPr/w:gridSpan', $cell) ?? 1);
                    $merge = $docx->first('w:tcPr/w:vMerge', $cell);

                    if ($merge !== null) {
                        $kind = Docx::attr($merge, 'val') ?? 'continue';
                        $rowMerges[$position] = $span;

                        if ($kind === 'continue' && ($previousRowMerges[$position] ?? null) !== $span) {
                            $errors[] = "row {$rowIndex}: vMerge continue at column {$position} has no merge above it";
                        }
                    }

                    $expectedWidth = array_sum(array_slice($gridWidths, $position, $span));

                    if ((int) Docx::attr($docx->first('w:tcPr/w:tcW', $cell), 'w') !== $expectedWidth) {
                        $errors[] = "row {$rowIndex}: cell width at column {$position} does not match its grid columns";
                    }

                    $position += $span;
                }

                if ($position !== $columns) {
                    $errors[] = "row {$rowIndex} spans {$position} grid columns instead of {$columns}";
                }

                $previousRowMerges = $rowMerges;
            }
        }
    }
}
