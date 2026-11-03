<?php

declare(strict_types=1);

// Reads a Factur-X invoice you received: the invoice data as an array, and the supporting
// documents embedded in it (timesheets, delivery notes) as one ZIP archive.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const FACTURX_PDF_PATH = 'factures/facture-fournisseur.pdf'; // a CII invoice XML works too
const ATTACHMENTS_ZIP_PATH = 'facture-fournisseur-pieces-jointes.zip';

const NO_ATTACHMENTS = 4013;

// Read the file first, so a wrong path stops the example before any API call.
if (!is_file(FACTURX_PDF_PATH) || !is_readable(FACTURX_PDF_PATH)) {
    echo 'Could not read ' . FACTURX_PDF_PATH . ".\n";
    echo "Set FACTURX_PDF_PATH at the top of example.php to a Factur-X PDF on your machine.\n";
    exit(1);
}
$file = File::fromPath(FACTURX_PDF_PATH);

$client = new Client(API_KEY);

try {
    // 1. The invoice data, read from the embedded XML. It can be passed straight back to createFacturx().
    $invoice = $client->extractJson($file)['invoice'];

    echo 'Invoice ' . ($invoice['invoiceNumber'] ?? '?')
        . ' from ' . ($invoice['seller']['name'] ?? '?')
        . ' to ' . ($invoice['buyer']['name'] ?? '?') . "\n";
    echo 'Total ' . ($invoice['totals']['grandTotalAmount'] ?? '?') . ' ' . ($invoice['currency'] ?? '')
        . ', due ' . ($invoice['totals']['duePayableAmount'] ?? '?')
        . ', ' . count($invoice['lines'] ?? []) . " line(s)\n";
    foreach ($invoice['lines'] ?? [] as $line) {
        echo '  ' . ($line['quantity'] ?? '?') . ' x ' . ($line['item']['name'] ?? '?')
            . ' at ' . ($line['priceDetails']['netPrice'] ?? '?') . "\n";
    }

    // 2. The embedded supporting documents, as one ZIP archive.
    try {
        $attachments = $client->extractAttachments($file);
        $attachments->save(ATTACHMENTS_ZIP_PATH);
        echo 'Saved the attachments to ' . ATTACHMENTS_ZIP_PATH . ".\n";
    } catch (ApiException $e) {
        if ($e->errorCode !== NO_ATTACHMENTS) {
            throw $e;
        }
        echo "The invoice has no embedded attachments.\n";
    }
} catch (ApiException $e) {
    // For example an invalid API key, or a PDF without an embedded invoice.
    echo "The API refused the request: {$e->getMessage()}\n";
    exit(1);
}
