<?php

declare(strict_types=1);

// Creates a Factur-X invoice that keeps your own PDF layout.
// The API downloads your PDF from a public URL, embeds the XML it generates from the invoice
// data into it, and returns a Factur-X PDF/A-3. Nothing about your PDF's look changes.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const PDF_URL = 'https://example.com/factures/F-2026-001.pdf'; // public http(s) URL of your PDF
const OUTPUT_PATH = 'F-2026-001-facturx.pdf';

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
    // pdfUrl makes your PDF the visual layer. Without it, the API renders its own invoice layout.
    $facturx = $client->createFacturx($invoice, [
        'pdfUrl' => PDF_URL,
        // 'rules' => ['br-fr'], // optional: also check the French BR-FR rules (they need more invoice data, such as the SIREN of both parties)
    ]);

    $facturx->save(OUTPUT_PATH);
    echo 'Saved the Factur-X invoice to ' . OUTPUT_PATH . ".\n";
} catch (ApiException $e) {
    if ($e->errors !== []) {
        // The invoice data would not produce a compliant Factur-X invoice. Each finding names the field to fix.
        echo "The invoice is not compliant:\n";
        foreach ($e->errors as $finding) {
            echo "  [{$finding->rule}] {$finding->message} (" . implode(', ', $finding->fields) . ")\n";
        }
    } else {
        // For example an invalid API key, or a PDF URL the API could not download.
        echo "The API refused the request: {$e->getMessage()}\n";
    }
    exit(1);
}
