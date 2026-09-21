# Security policy

kovami/htmldocx reads documents that usually come from users, so it treats
every `.docx` and every piece of HTML as hostile input. The protections it
relies on are listed under [Safety](README.md#safety) in the README.

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x | Yes |
| 1.x | No |

Fixes are released as a new patch version of the latest minor release.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: open the repository's **Security** tab
and choose **Report a vulnerability**. If that is not possible, email
alexandr.minakov178@gmail.com.

Include what you can of:

- the input that triggers the problem (a `.docx`, HTML, or the code building
  it), or a description precise enough to reproduce it;
- what happens — memory or time used, a file read, a request made, markup
  that ends up in the output;
- the PHP version and the library version.

You will get an answer as soon as the report has been looked at. Once a fix is
released, the advisory is published with credit to you, unless you prefer
otherwise.

## What counts

Anything that lets a document or HTML input do more than produce a converted
document, for example:

- exhausting memory or time out of proportion to the input (zip bombs, entity
  expansion, deeply nested markup);
- reading files outside the image directory you configured, or reaching the
  network without going through your image fetcher;
- package parts or relationships that escape the package;
- script-capable URLs (`javascript:` and the like) or other markup that
  survives into the HTML output.

Wrong or lost formatting is a bug, not a vulnerability: please report it as
an ordinary issue.
