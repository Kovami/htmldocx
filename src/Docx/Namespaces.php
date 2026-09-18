<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx;

/**
 * Transitional OOXML namespace URIs. Strict documents are read by mapping
 * their namespaces onto these ({@see self::STRICT_TO_TRANSITIONAL}).
 */
final class Namespaces
{
    public const string W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public const string R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const string WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    public const string A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public const string PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

    public const string M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    public const string MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    public const string WPS = 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape';

    public const string WPG = 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup';

    public const string W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';

    public const string W15 = 'http://schemas.microsoft.com/office/word/2012/wordml';

    public const string V = 'urn:schemas-microsoft-com:vml';

    public const string O = 'urn:schemas-microsoft-com:office:office';

    public const string PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public const string CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    public const string CORE_PROPERTIES = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

    public const string DC = 'http://purl.org/dc/elements/1.1/';

    public const string DCTERMS = 'http://purl.org/dc/terms/';

    public const array STRICT_TO_TRANSITIONAL = [
        'http://purl.oclc.org/ooxml/wordprocessingml/main' => self::W,
        'http://purl.oclc.org/ooxml/officeDocument/relationships' => self::R,
        'http://purl.oclc.org/ooxml/drawingml/wordprocessingDrawing' => self::WP,
        'http://purl.oclc.org/ooxml/drawingml/main' => self::A,
        'http://purl.oclc.org/ooxml/drawingml/picture' => self::PIC,
        'http://purl.oclc.org/ooxml/officeDocument/math' => self::M,
    ];
}
