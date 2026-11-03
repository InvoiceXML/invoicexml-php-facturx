<?php

declare(strict_types=1);

// Builds a CII XML from your invoice data, then embeds it into a PDF you already have.
// Use this when your own system prints the invoice PDF and you want it to become a
// Factur-X invoice. The CII XML declares the EN 16931 profile, so it embeds as it is.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const PDF_PATH = 'factures/F-2026-001.pdf'; // the PDF your system printed
const OUTPUT_PATH = 'F-2026-001-facturx.pdf';

// Read the PDF first, so a wrong path stops the example before any API call.
if (!is_file(PDF_PATH) || !is_readable(PDF_PATH)) {
    echo 'Could not read ' . PDF_PATH . ".\n";
    echo "Set PDF_PATH at the top of example.php to a PDF on your machine.\n";
    exit(1);
}
$pdf = File::fromPath(PDF_PATH);

$client = new Client(API_KEY);

$invoice = [
    'invoiceNumber' => 'F-2026-001',
    'currency' => 'EUR',
    'seller' => [
        'name' => 'Atelier Dupont SARL',
        'vatIdentifier' => 'FR32123456789',
        'postalAddress' => ['city' => 'Paris', 'postCode' => '75011', 'country' => 'FR'],
    ],
    'buyer' => [
        'name' => 'Client Exemple SA',
        'postalAddress' => ['city' => 'Lyon', 'postCode' => '69002', 'country' => 'FR'],
    ],
    'lines' => [
        [
            'quantity' => 10,
            'unitCode' => 'HUR', // hour
            'item' => ['name' => 'Prestation de conseil'],
            'priceDetails' => ['netPrice' => 120],
            'vatInformation' => ['categoryCode' => 'S', 'rate' => 20],
        ],
    ],
];

try {
    // 1. Build the CII XML from the invoice data. language words the default texts, such as the payment terms.
    $cii = $client->createCii($invoice, [
        'language' => 'fr',
        // 'rules' => ['br-fr'], // optional: also check the French BR-FR rules (they need more invoice data, such as the SIREN of both parties)
    ]);

    // 2. Embed the XML into your PDF. The result is a Factur-X PDF/A-3.
    $facturx = $client->embedFacturx($pdf, $cii);

    $facturx->save(OUTPUT_PATH);
    echo 'Saved the Factur-X invoice to ' . OUTPUT_PATH . ".\n";
} catch (ApiException $e) {
    if ($e->errors !== []) {
        // The invoice data would not produce a compliant invoice. Each finding names the field to fix.
        echo "The invoice is not compliant:\n";
        foreach ($e->errors as $finding) {
            echo "  [{$finding->rule}] {$finding->message} (" . implode(', ', $finding->fields) . ")\n";
        }
    } else {
        echo "The API refused the request: {$e->getMessage()}\n";
    }
    exit(1);
}
