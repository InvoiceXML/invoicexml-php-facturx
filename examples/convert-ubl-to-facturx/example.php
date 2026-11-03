<?php

declare(strict_types=1);

// Converts a UBL invoice XML, for example one received over Peppol, into a Factur-X invoice:
// a readable PDF with the invoice data embedded as CII XML.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const UBL_XML_PATH = 'factures/F-2026-001-ubl.xml';
const OUTPUT_PATH = 'F-2026-001-facturx.pdf';

// Read the XML first, so a wrong path stops the example before any API call.
if (!is_file(UBL_XML_PATH) || !is_readable(UBL_XML_PATH)) {
    echo 'Could not read ' . UBL_XML_PATH . ".\n";
    echo "Set UBL_XML_PATH at the top of example.php to a UBL invoice XML on your machine.\n";
    exit(1);
}
$ubl = File::fromPath(UBL_XML_PATH);

$client = new Client(API_KEY);

try {
    // language sets the PDF layout and the default texts added when the UBL has none.
    $facturx = $client->convertUblToFacturx($ubl, language: 'fr');

    $facturx->save(OUTPUT_PATH);
    echo 'Saved the Factur-X invoice to ' . OUTPUT_PATH . ".\n";
} catch (ApiException $e) {
    if ($e->errors !== []) {
        // The result would not be a compliant Factur-X invoice. Each finding names the field to fix.
        echo "The converted invoice would not be compliant:\n";
        foreach ($e->errors as $finding) {
            echo "  [{$finding->rule}] {$finding->message} (" . implode(', ', $finding->fields) . ")\n";
        }
    } else {
        echo "The API refused the request: {$e->getMessage()}\n";
    }
    exit(1);
}
