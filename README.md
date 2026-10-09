# Factur-X for PHP

[![tests](https://github.com/InvoiceXML/invoicexml-php-facturx/actions/workflows/tests.yml/badge.svg)](https://github.com/InvoiceXML/invoicexml-php-facturx/actions/workflows/tests.yml)

A thin PHP client for the [InvoiceXML API](https://invoicexml.com) that creates, validates, embeds and reads **Factur-X** e-invoices. A Factur-X invoice is a PDF/A-3 with embedded EN 16931 CII XML, the hybrid format of the French e-invoicing mandate.

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
- **Zero data retention.** Documents are processed in memory and discarded when the response is returned. Your data is never used for AI training.
- **Processing in Frankfurt.** All processing runs in Frankfurt, Germany.
- **Integration assistance.** When a rule finding needs a human explanation, a person who knows the rule sets answers your support request.

## Get an API key

Sign up at [invoicexml.com](https://invoicexml.com/account/signup) to start a 30-day free trial with 100 credits. No credit card is needed. Plans are listed on the [pricing page](https://invoicexml.com/pricing).

## Usage

Invoices use the field names of the EN 16931 `InvoiceDocument` model from the [OpenAPI specification](https://api.invoicexml.com/v1/openapi). The [API explorer](https://api.invoicexml.com/v1/scalar/) shows every field with its business term (BT) code. Every method that takes a file accepts a path on disk or a `File` object.

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
- `btCodes` and `fields` name the business terms and invoice fields involved.
- `line` and `raw` give the XML line and the verbatim validator output.

`$result->data` holds the verdict details, such as `schemaValid`, `schematronValid` and `profile`. `$result->report` is the field report: one row per business term with its value and its findings.

### Embed XML into an existing PDF

```php
$facturx = $client->embedFacturx('layout.pdf', 'factur-x.xml');
```

The XML is validated before embedding. Pass `skipValidation: true` to embed it as is.

### Read incoming invoices

```php
$data = $client->extractJson('incoming.pdf');             // Factur-X PDF or CII XML
$invoice = $data['invoice'];                              // same shape createFacturx() accepts

$zip = $client->extractAttachments('incoming.pdf');       // supporting documents as a ZIP
```

Extraction parses the embedded XML deterministically, with no AI involved. The returned invoice can go straight back into `createFacturx()`.

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

`errorCode` is a stable numeric code, documented under [error handling](https://invoicexml.com/docs/error-handling).

When too many requests for one API key run at the same time, the API answers 429 with a `Retry-After` header. The client waits that long and resends the request, up to 3 times. A request answered with 429 was not processed and not billed, so resending never creates duplicates. If the retries are used up, a `TooManyRequestsException` carries `retryAfter` in seconds. Set `maxRetries: 0` to handle 429 yourself.

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

- [Documentation](https://invoicexml.com/docs)
- [API explorer](https://api.invoicexml.com/v1/scalar/)
- [Factur-X in PHP guide](https://invoicexml.com/blog/factur-x-php)
- [ZUGFeRD and XRechnung package for PHP](https://github.com/InvoiceXML/invoicexml-php-zugferd-xrechnung)
- [Contact and support](https://invoicexml.com/contact)

## License

MIT
