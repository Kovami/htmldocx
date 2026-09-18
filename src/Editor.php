<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use InvalidArgumentException;

/**
 * The rich-text editors the HTML can be tailored to. Without one
 * ({@see HtmlDocx::plain()}) the HTML is plain and editor-neutral.
 */
enum Editor: string
{
    case SunEditor = 'suneditor';
    case CKEditor = 'ckeditor';
    case TinyMce = 'tinymce';
    case TipTap = 'tiptap';

    /** Other spellings accepted by {@see self::fromName()}, e.g. from configuration files. */
    private const array ALIASES = [
        'sun-editor' => 'suneditor',
        'ckeditor5' => 'ckeditor',
        'ckeditor-5' => 'ckeditor',
        'tiny-mce' => 'tinymce',
        'tip-tap' => 'tiptap',
        'prosemirror' => 'tiptap',
    ];

    /** The editor a name refers to, ignoring case: "SunEditor", "tinymce", "CKEditor 5", "prosemirror"… */
    public static function fromName(string $name): self
    {
        $key = strtolower((string) preg_replace('/[\s_]+/', '-', trim($name)));
        $editor = self::tryFrom(self::ALIASES[$key] ?? str_replace('-', '', $key));

        if ($editor === null) {
            $known = implode(', ', array_map(static fn(self $editor): string => $editor->name, self::cases()));

            throw new InvalidArgumentException("Unknown editor \"{$name}\"; use one of {$known}.");
        }

        return $editor;
    }
}
