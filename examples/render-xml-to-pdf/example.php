<?php

declare(strict_types=1);

// Turns any invoice XML into a readable PDF, for approval workflows and archives.
// CII (such as the XML inside a Factur-X invoice), UBL and XRechnung are all accepted;
// the format is detected from the XML.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const XML_PATH = 'factures/F-2026-001.xml';
const OUTPUT_PATH = 'F-2026-001-apercu.pdf';

// Read the XML first, so a wrong path stops the example before any API call.
if (!is_file(XML_PATH) || !is_readable(XML_PATH)) {
    echo 'Could not read ' . XML_PATH . ".\n";
    echo "Set XML_PATH at the top of example.php to an invoice XML on your machine.\n";
    exit(1);
}
$xml = File::fromPath(XML_PATH);

$client = new Client(API_KEY);

try {
    $pdf = $client->renderXmlToPdf(
        $xml,
        language: 'fr',                             // labels and date formats of the PDF
        includeQrCode: true,                        // optional: a payment QR code for banking apps
        // logoUrl: 'https://example.com/logo.png', // optional: public URL of your logo, printed top left
    );

    $pdf->save(OUTPUT_PATH);
    echo 'Saved the PDF to ' . OUTPUT_PATH . ".\n";
} catch (ApiException $e) {
    // For example an invalid API key, or a file that is not an invoice XML.
    echo "The API refused the request: {$e->getMessage()}\n";
    exit(1);
}
