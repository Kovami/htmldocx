<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * Cascade origins, lowest precedence first. Presentational HTML attributes
 * (`align`, `bgcolor`, `width`, ...) sit between defaults and base rules.
 */
enum Origin: int
{
    /** The built-in stylesheet describing how the editor renders content. */
    case Default = 0;

    /** Extra CSS supplied by the application through converter options. */
    case Base = 1;

    /** `<style>` blocks inside the converted HTML. */
    case Author = 2;
}
