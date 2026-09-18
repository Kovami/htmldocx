<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\StyleDefinition;

/**
 * Derives Word's named styles from the same default stylesheet the content
 * is rendered with, so headings are real "Heading N" paragraphs (navigation
 * pane, table of contents) and runs only carry formatting that differs.
 */
final class StyleCatalog
{
    public const array HEADING_STYLE_IDS = [
        'h1' => 'Heading1', 'h2' => 'Heading2', 'h3' => 'Heading3',
        'h4' => 'Heading4', 'h5' => 'Heading5', 'h6' => 'Heading6',
    ];

    public const string CAPTION_STYLE_ID = 'Caption';

    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly PropertyMapper $mapper,
    ) {}

    /**
     * @return list<StyleDefinition>
     */
    public function definitions(ComputedStyle $root): array
    {
        $html = HtmlDocument::fromString('<h1></h1><h2></h2><h3></h3><h4></h4><h5></h5><h6></h6><figcaption></figcaption>');
        $bodyStyle = $this->resolver->resolve($html->body(), $root);

        $definitions = [
            new StyleDefinition('Normal', 'Normal', new ParagraphProperties, $this->mapper->run($root), basedOn: null, isDefault: true),
        ];

        foreach ($html->body()->children as $element) {
            $style = $this->resolver->resolve($element, $bodyStyle);
            $tag = $element->localName;

            if (isset(self::HEADING_STYLE_IDS[$tag])) {
                $level = (int) substr($tag, 1);

                $definitions[] = new StyleDefinition(
                    id: self::HEADING_STYLE_IDS[$tag],
                    name: "heading {$level}",
                    paragraph: new ParagraphProperties(
                        spacingBefore: Length::pointsToTwips(max(0, $style->lengthPt('margin-top') ?? 0)),
                        spacingAfter: Length::pointsToTwips(max(0, $style->lengthPt('margin-bottom') ?? 0)),
                        keepNext: true,
                        keepLines: true,
                        outlineLevel: $level - 1,
                    ),
                    run: $this->mapper->run($style),
                );

                continue;
            }

            $definitions[] = new StyleDefinition(
                id: self::CAPTION_STYLE_ID,
                name: 'caption',
                paragraph: new ParagraphProperties(alignment: $this->mapper->alignment($style->textAlign)),
                run: $this->mapper->run($style),
            );
        }

        return $definitions;
    }
}
