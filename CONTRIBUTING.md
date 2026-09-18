# Contributing to kovami/htmldocx

Thanks for taking the time to help. Bug reports with a sample document, fixes and
new conversions are all welcome.

## Reporting a bug

Open an issue and include:

- the smallest input that shows the problem — an HTML snippet, or a `.docx`
  (strip anything private; a document rebuilt from scratch in Word is ideal);
- what you got, and what you expected: the HTML output, or what Word shows;
- the PHP version and the library version.

A conversion that is wrong in Word but looks fine in the XML is still a bug:
Word's interpretation is the one that counts.

Please do not report security problems in public issues — see
[Security](#security).

## Setting up

You need PHP 8.4 or newer with `dom`, `mbstring`, `xmlwriter`, `zlib` and, for
the tests, `zip`.

```bash
git clone https://github.com/kovami/htmldocx.git
cd htmldocx
composer install
composer test
```

## Before you open a pull request

- **Tests pass:** `composer test`.
- **Static analysis is clean:** `composer analyse` (PHPStan, level 7).
- **Code style is clean:** `composer lint` checks [PER Coding Style 3.0](https://www.php-fig.org/per/coding-style/)
  with PHP CS Fixer; `composer format` fixes what it reports.
- **New behaviour has a test**, and a fix comes with a test that failed before it.
- **Both READMEs are updated** (`README.md` and `README.ru.md`) when you change
  what is converted, how it looks in HTML, or an option.

Keep a pull request to one change. If you plan something large — a new part of
the format, a change to the HTML conventions — open an issue first so the design
can be agreed before you write the code.

## Ground rules

These keep the library what it is; a pull request that breaks one will be asked
to change.

- **No runtime dependencies.** `require` holds PHP and its bundled extensions
  only. Development tools belong in `require-dev`.
- **Both directions go through the model.** Readers build `Model\Document`,
  writers consume it. A feature is not done until it survives
  `HTML → DOCX → HTML` and `DOCX → HTML → DOCX`, and HTML → DOCX → HTML is
  stable after one pass.
- **The HTML writer only writes what differs from the editor stylesheet**, and
  the HTML reader reads it back against the same stylesheet. Formatting that
  merely restates the defaults is noise that breaks round trips.
- **Markup the library invents is read back.** Every class or data attribute
  the HTML writer emits (`se-footnotes`, `se-header`, `se-comment`, …) must be
  understood by the HTML reader.
- **Word is the referee.** A generated package must open in Word without a
  repair prompt. When you touch the DOCX writer, open the result in Word if you
  can, and say in the pull request whether you did.
- **Fail soft on input.** Unsupported content is skipped with a message to the
  warning handler; exceptions are for input that cannot be read at all.

## Writing tests

Tests are black-box: they go through the public API and look at the result.

- `tests/Support/DocxBuilder.php` builds a `.docx` from WordprocessingML
  fragments, so a reader test states exactly the XML it is about — including
  markup this library never writes.
- `tests/Support/Docx.php` opens a package and queries its parts with XPath.
- `tests/Support/DocxIntegrity.php` checks what Word is strict about: element
  order, dangling relationships, content types. Run it on every package a test
  writes.
- `html()` and `roundTrip()` in `tests/Pest.php` cover HTML → model → HTML and
  HTML → DOCX → HTML.

Name a test after the behaviour (`it('reads a floating picture and which side
text flows around')`), not after the method it calls.

## Code style

Match the surrounding code. Comments explain *why* — a Word quirk, a CSS rule,
a trade-off — not what the next line does. Readonly value objects for the
model, `declare(strict_types=1)` everywhere, full types on every signature.

## Security

The library parses untrusted documents, so reports about entity expansion, zip
bombs, path traversal or unsafe URLs matter. Report them privately through
GitHub's "Report a vulnerability" on the repository's Security tab rather than in
an issue.

## License

By contributing, you agree that your contributions are licensed under the
[MIT License](LICENSE).
