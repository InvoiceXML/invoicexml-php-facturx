<?php

declare(strict_types=1);

// Validates an incoming Factur-X invoice against the rules of the profile it declares, and
// optionally the French BR-FR rules. A failed validation is a normal result, not an exception:
// you get the verdict and every finding with the rule, the message and the field it concerns.
// The example also saves the printable validation report PDF, for example to send back to a supplier.

use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;

require __DIR__ . '/../../vendor/autoload.php';

// Replace these values with your own.
const API_KEY = 'YOUR_API_KEY';
const FACTURX_PDF_PATH = 'factures/facture-fournisseur.pdf';
const REPORT_PATH = 'facture-fournisseur-rapport.pdf';

// Optional: also check the French BR-FR rules, the set the French platforms apply to domestic invoices.
const RULES = []; // for example ['br-fr']

// Read the PDF first, so a wrong path stops the example before any API call.
if (!is_file(FACTURX_PDF_PATH) || !is_readable(FACTURX_PDF_PATH)) {
    echo 'Could not read ' . FACTURX_PDF_PATH . ".\n";
    echo "Set FACTURX_PDF_PATH at the top of example.php to a Factur-X PDF on your machine.\n";
    exit(1);
}
$pdf = File::fromPath(FACTURX_PDF_PATH);

$client = new Client(API_KEY);

try {
    // 1. The verdict and the findings as data.
    $result = $client->validateFacturx($pdf, RULES);
    echo ($result->valid ? 'Valid' : 'Not valid') . ". {$result->detail}\n";

    foreach ($result->errors as $error) {
        echo "  error   [{$error->rule}] {$error->message} (" . implode(', ', $error->fields) . ")\n";
    }

    // Warnings never make an invoice invalid, but are worth reviewing.
    foreach ($result->warnings as $warning) {
        echo "  warning [{$warning->rule}] {$warning->message} (" . implode(', ', $warning->fields) . ")\n";
    }

    // 2. The same check as a printable PDF report, worded in French.
    $report = $client->validateFacturxReport($pdf, RULES, 'fr');
    $report->file->save(REPORT_PATH);
    echo 'Saved the validation report to ' . REPORT_PATH . ' (valid: ' . var_export($report->valid, true) . ").\n";
} catch (ApiException $e) {
    // For example an invalid API key, or a file that is not a PDF.
    echo "The API refused the request: {$e->getMessage()}\n";
    exit(1);
}
