# lib/ — vendored PHP libraries

Third-party code the app needs on shared hosting, where `composer install` cannot run during deploy:

- **mpdf/mpdf**: builds the weekly management report PDF. Marathi text is shaped correctly with the bundled FreeSans font.
- **chillerlan/php-qrcode**: draws the QR code for the 2-step login setup.

`vendor/` was installed with `composer install --no-dev` and then trimmed to keep the upload small. Tests, docs and `.git` folders were removed, and only the FreeSans and DejaVuSans fonts were kept. To update:

    cd lib && composer update --no-dev
    # then repeat the trimming

This folder is not reachable over the web (see `.htaccess`). Code loads it through `includes/pdf.php` and `includes/two-factor.php`.
