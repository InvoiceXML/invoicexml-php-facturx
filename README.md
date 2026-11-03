# Factur-X for PHP

[![tests](https://github.com/InvoiceXML/invoicexml-php-facturx/actions/workflows/tests.yml/badge.svg)](https://github.com/InvoiceXML/invoicexml-php-facturx/actions/workflows/tests.yml)

A thin PHP client for the [InvoiceXML API](https://www.invoicexml.com) that creates, validates, embeds, renders, converts, emails and reads **Factur-X** e-invoices. A Factur-X invoice is a PDF/A-3 with embedded EN 16931 CII XML, the hybrid format of the French e-invoicing mandate.

You send an invoice as a PHP array, and the API computes totals and the VAT breakdown, builds the XML and the PDF/A-3, and validates the result before returning it. Your project needs no PDF library, no XML builder and no Schematron tooling.

## Installation

```bash
composer require invoicexml/facturx
```

Requires PHP 8.1 or newer. Guzzle is used by default, and any PSR-18 HTTP client works.

## Quick start

```php
use InvoiceXml\FacturX\Client;

$client = new Client(getenv('INVOICEXML_API_KEY'));

$pdf = $client->createFacturx([
    'invoiceNumber'  => 'FA-2026-0142',
    'issueDate'      => '2026-05-17',
    'currency'       => 'EUR',
    'seller'         => ['name' => 'Atelier Lumière SARL', 'vatIdentifier' => 'FR12345678901', 'postalAddress' => ['line1' => '8 rue des Arts', 'city' => 'Lyon', 'postCode' => '69002', 'country' => 'FR']],
    'buyer'          => ['name' => 'Globex SAS', 'postalAddress' => ['line1' => '15 rue de Rivoli', 'city' => 'Paris', 'postCode' => '75001', 'country' => 'FR']],
    'paymentDetails' => ['paymentAccountIdentifier' => 'FR7630006000011234567890189'],
    'lines'          => [['quantity' => 2, 'priceDetails' => ['netPrice' => 450], 'vatInformation' => ['rate' => 20], 'item' => ['name' => 'Conseil en design']]],
], ['language' => 'fr']);

$pdf->save('facture.pdf');
```

The result is a Factur-X PDF/A-3 in the EN 16931 profile, already validated against the official rules.

## Why InvoiceXML

- **Rule sets updated on their legal effective dates.** New Factur-X and BR-FR releases go live server-side on the day they take effect. You never update a package or redeploy to stay compliant.
- **Validated before delivery.** Every created or embedded invoice passes the full rule set before it is returned. Data that cannot produce a compliant invoice is refused with the violated rules, so nothing broken reaches a customer.
- **Zero data retention.** Documents are processed in memory and discarded when the response is returned. Your data is never used for AI training. When you email an invoice, it goes to our email service for delivery, and we keep no copy.
- **Processing in Frankfurt.** All processing runs in Frankfurt, Germany.
- **Integration assistance.** When a rule finding needs a human explanation, a person who knows the rule sets answers your support request.

## Get an API key

Sign up at [www.invoicexml.com](https://www.invoicexml.com/account/signup) to start a 30-day free trial with 100 credits. No credit card is needed. Plans are listed on the [pricing page](https://www.invoicexml.com/pricing).

## Usage

Invoices use the field names of the EN 16931 `InvoiceDocument` model from the [OpenAPI specification](https://api.invoicexml.com/v1/openapi). The [API explorer](https://api.invoicexml.com/v1/scalar/) documents every field. Every method that takes a file accepts a path on disk or a `File` object.

### Create a Factur-X PDF

```php
$pdf = $client->createFacturx($invoice, [
    'language'   => 'fr',          // labels of the rendered PDF: en, de or fr
    'brandColor' => '#0F766E',
    'logoUrl'    => 'https://example.com/logo.png',
]);

$pdf->save($pdf->filename);       // for example invoice-facturx-FA-2026-0142.pdf
```

To keep your own invoice layout, pass `'pdfUrl' => 'https://...'` and the API embeds the XML into that PDF instead of rendering one.

### French mandate rules (BR-FR)

For invoices bound for the French exchange network, add `br-fr`. The invoice is then also checked against the BR-FR Flux 2 rules, on top of the rules of its declared profile.

```php
$pdf    = $client->createFacturx($invoice, ['rules' => ['br-fr']]);
$result = $client->validateFacturx('facture.pdf', rules: ['br-fr']);
$report = $client->validateFacturxReport('facture.pdf', rules: ['br-fr']);
$pdf    = $client->embedFacturx('layout.pdf', 'factur-x.xml', rules: ['br-fr']);
```

### Validate

The API answers valid and invalid documents alike with a result, so check `valid`.

```php
$result = $client->validateFacturx('incoming.pdf');

if (!$result->valid) {
    foreach ($result->errors as $error) {
        echo "{$error->rule} ({$error->layer}): {$error->message}\n";
    }
}
```

Each finding carries the following fields:

- `rule` is the rule identifier, for example `BR-CO-15`.
- `layer` is `xsd`, `en16931` or `cius`.
- `message` is a plain-language explanation.
- `fields` names the invoice fields involved, for example `seller.name`.
- `line` and `raw` give the XML line and the verbatim validator output.

`$result->data` holds the verdict details, such as `schemaValid`, `schematronValid` and `profile`. `$result->report` is the field report: one row per invoice field with its value and its findings.

### Get a validation report as PDF

```php
$report = $client->validateFacturxReport('incoming.pdf', validationLanguage: 'fr');

$report->file->save('report.pdf');

if ($report->valid === false) {
    echo "The invoice failed validation, see the report.\n";
}
```

`$report->file` is the printable report. `$report->valid` is `true` or `false`, or `null` if the response carried no verdict. A failed verdict is a normal result, not an exception. A missing or malformed file still throws `ApiException`. `validationLanguage` (`en`, `de` or `fr`) words the whole report, and `rules` works as in `validateFacturx()`.

The reference page is [Factur-X report](https://www.invoicexml.com/docs/api/validate/facturx/report).

### Embed XML into an existing PDF

```php
$facturx = $client->embedFacturx('layout.pdf', 'factur-x.xml');
```

The XML is validated before embedding. Pass `skipValidation: true` to embed it as is.

### Create a CII XML for your own PDF

To keep your own invoice layout, create the CII XML from the invoice, then embed it into your PDF:

```php
$xml     = $client->createCii($invoice, ['language' => 'fr', 'rules' => ['br-fr']]);
$facturx = $client->embedFacturx('layout.pdf', $xml, rules: ['br-fr']);
```

The XML declares the EN 16931 profile, so `embedFacturx()` takes it as is. `language` (`en`, `de` or `fr`) words default texts such as payment terms. The reference page is [create CII](https://www.invoicexml.com/docs/api/create/cii).

### Create a UBL invoice

`createUbl()` takes the same invoice array, and `profile` picks the UBL profile and its rules (`peppol-bis-3` when omitted). The reference page is [create UBL](https://www.invoicexml.com/docs/api/create/ubl).

```php
$ubl = $client->createUbl($invoice, ['profile' => 'peppol-bis-3']);
```

### Render an invoice XML as PDF

```php
$preview = $client->renderCiiToPdf('factur-x.xml', language: 'fr', includeQrCode: true);
```

`language` is `en`, `de` or `fr`. `logoUrl` prints your logo and `includeQrCode: true` prints the payment QR code. Rendering shows what the XML carries and does not validate it.

The reference page is [render CII](https://www.invoicexml.com/docs/api/render/cii/to/pdf).

### Convert between formats

```php
$ubl = $client->convertFacturxToUbl('incoming.pdf');
$pdf = $client->convertCiiToFacturx('invoice.xml', language: 'fr');
```

Each route has its own method:

- Factur-X PDF to XML: [`convertFacturxToUbl()`](https://www.invoicexml.com/docs/api/convert/facturx/to/ubl), [`convertFacturxToCii()`](https://www.invoicexml.com/docs/api/convert/facturx/to/cii)
- XML to Factur-X PDF: [`convertCiiToFacturx()`](https://www.invoicexml.com/docs/api/convert/cii/to/facturx), [`convertUblToFacturx()`](https://www.invoicexml.com/docs/api/convert/ubl/to/facturx), [`convertXrechnungToFacturx()`](https://www.invoicexml.com/docs/api/convert/xrechnung/to/facturx)

The methods that return a Factur-X PDF take `language` (`en`, `de` or `fr`) for the labels of the PDF. Every method takes `validationLanguage` (`en`, `de` or `fr`), which words the findings of a failed conversion. A failed conversion throws `ApiException`, and `$e->errors` lists the violated rules.

### Send an invoice by email

The API creates and validates the invoice like `createFacturx()`, then sends the file as a real email from your own address. The sender must be on a domain added and verified on the Verified email domains page of the dashboard.

```php
$result = $client->emailFacturx($invoice, [
    'from'     => 'facturation@atelier-lumiere.example', // required
    'to'       => 'compta@globex.example',               // required, one address
    'fromName' => 'Atelier Lumière',
    'cc'       => ['anna@globex.example'],               // cc and bcc hold at most 2 addresses together
    'bcc'      => ['archive@atelier-lumiere.example'],
    'replyTo'  => 'contact@atelier-lumiere.example',
    'subject'  => 'Votre facture FA-2026-0142',
    'message'  => "Bonjour Anna,\n\nvotre facture est jointe.",
], ['language' => 'fr']);

echo $result->status, ' ', $result->messageId;
```

To send a PDF and XML you already have, use `emailEmbeddedFacturx()`, which always validates the XML. Pass `rules: ['br-fr']` to add the French mandate rules:

```php
$result = $client->emailEmbeddedFacturx('layout.pdf', 'factur-x.xml', [
    'from' => 'facturation@atelier-lumiere.example',
    'to'   => 'compta@globex.example',
], rules: ['br-fr']);
```

No file comes back. `accepted` means the email was handed over for delivery, not yet that the recipient's mail server received it. Keep `messageId`: it identifies the email when you ask about a delivery later.

A refused email throws `ApiException` with one of these `errorCode` values, and 4020, 4021 and 4022 are not billed:

- `4020`: your plan does not include email delivery, see the [pricing page](https://www.invoicexml.com/pricing).
- `4021`: email sending is disabled or paused for your account.
- `4022`: the sender domain has not finished verification.
- `4002`: a field was refused, for example a bad address, a sender domain that is not on your account, or too many copies. `$e->errors` names the fields.

Only a 429 is retried, because nothing was sent. A timeout or connection failure throws the HTTP client's own exception rather than `ApiException`, and the email may already have gone out, so check before you send it again.

The full guide is at [Send an invoice by email](https://www.invoicexml.com/docs/api/create/facturx/email).

### Read incoming invoices

```php
$data = $client->extractJson('incoming.pdf');             // Factur-X PDF or CII XML
$invoice = $data['invoice'];                              // same shape createFacturx() accepts

$zip = $client->extractAttachments('incoming.pdf');       // supporting documents as a ZIP
```

Extraction parses the embedded XML deterministically, with no AI involved. The returned invoice can go straight back into `createFacturx()`.

### Transform a PDF invoice

This method takes a PDF without embedded XML. AI reads the PDF, so a person must check the result before it is used.

```php
$facturx = $client->transformToFacturx('invoice.pdf', language: 'fr');
```

`language` is `en`, `de`, `fr` or `auto`, the default, which follows the language of the invoice. By default the PDF is rendered from the data that was read and ends with an electronic invoice details section, so that data can be checked on the pages. Pass `includeAdvancedProperties: false` to leave the section out, or `preserveOriginalPdf: true` to keep the uploaded PDF as the visual layer. `validationLanguage` (`en`, `de` or `fr`) words the findings of a refused invoice. A 429 on this method is not retried automatically, so wait for `Retry-After` before calling again if you want to retry.

The reference page is [transform to Factur-X](https://www.invoicexml.com/docs/api/transform/to/facturx).

## Errors and rate limits

Any error status throws `InvoiceXml\FacturX\ApiException`. When a create or embed request is refused because the invoice would not be compliant, `$e->errors` lists the violated rules as findings.

```php
use InvoiceXml\FacturX\ApiException;

try {
    $client->createFacturx($invoice);
} catch (ApiException $e) {
    echo $e->statusCode, ' ', $e->errorCode, ': ', $e->detail, "\n";
    foreach ($e->errors as $error) {
        echo "{$error->rule}: {$error->message}\n";
    }
}
```

`errorCode` is a stable numeric code, documented under [error handling](https://www.invoicexml.com/docs/error-handling).

When too many requests for one API key run at the same time, the API answers 429 with a `Retry-After` header. Every call except transform waits that long and resends the request, up to 3 times. A 429 is not billed. If the retries are used up, a `TooManyRequestsException` carries `retryAfter` in seconds. Set `maxRetries: 0` to handle 429 yourself.

## Configuration

```php
$client = new Client(
    apiKey: getenv('INVOICEXML_API_KEY'),
    httpClient: $psr18Client,          // any PSR-18 client, Guzzle by default
    requestFactory: $psr17Factory,     // PSR-17 factories, Guzzle's by default
    streamFactory: $psr17Factory,
    maxRetries: 3,
);
```

## Links

- [Documentation](https://www.invoicexml.com/docs)
- [API explorer](https://api.invoicexml.com/v1/scalar/)
- [Factur-X in PHP guide](https://www.invoicexml.com/blog/factur-x-php)
- [ZUGFeRD and XRechnung package for PHP](https://github.com/InvoiceXML/invoicexml-php-zugferd-xrechnung)
- [Contact and support](https://www.invoicexml.com/contact)

## License

MIT
