<?php

declare(strict_types=1);

namespace InvoiceXml\FacturX;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin client for the Factur-X endpoints of the InvoiceXML API.
 *
 * Invoices are plain PHP arrays in the EN 16931 shape of the API's InvoiceDocument
 * schema (https://api.invoicexml.com/v1/openapi). The API computes totals and the VAT
 * breakdown, builds the XML, and validates every document before delivering it.
 *
 * Create options (all optional, see the InvoiceOptions schema):
 *  - language:   "en", "de" or "fr". Labels of the rendered PDF and default texts.
 *  - brandColor: "#RRGGBB" accent colour of the rendered PDF.
 *  - logoUrl:    public PNG or JPEG URL printed on the rendered PDF.
 *  - pdfUrl:     public URL of your own PDF to use as the visual layer.
 *  - includeAdvancedProperties: true adds an electronic invoice details section to the PDF.
 *  - rules:      ["br-fr"] also validates against the BR-FR Flux 2 rules of the French mandate.
 *
 * Email fields of the email methods (from and to are required):
 *  - from:     sender address, on a domain verified in your dashboard.
 *  - fromName: display name of the sender.
 *  - to:       one recipient address.
 *  - cc, bcc:  one address or a list of addresses, at most 2 addresses together.
 *  - replyTo:  address replies go to.
 *  - subject:  subject line. Defaults to a text with the invoice number.
 *  - message:  plain-text message. Defaults to a short text with the invoice number.
 * A list in any other field, or text that is not valid UTF-8, throws InvalidArgumentException before sending.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.invoicexml.com';
    private const MAX_RETRY_AFTER_SECONDS = 120;
    private const USER_AGENT = 'invoicexml-php-facturx/1.0';

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly string $baseUrl;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param string                       $apiKey         API key from https://www.invoicexml.com/account
     * @param ClientInterface|null         $httpClient     Any PSR-18 client. Defaults to Guzzle.
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory. Defaults to Guzzle's.
     * @param StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory. Defaults to Guzzle's.
     * @param int                          $maxRetries     How often a 429 is retried after waiting Retry-After seconds, on every call except transform. 0 disables retries.
     * @param (callable(int): void)|null   $sleep          Wait function used between retries, in seconds. Defaults to sleep().
     */
    public function __construct(
        private readonly string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $maxRetries = 3,
        ?callable $sleep = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('An InvoiceXML API key is required.');
        }

        $factory = new HttpFactory();
        $this->http = $httpClient ?? new GuzzleClient(['timeout' => 120, 'http_errors' => false]);
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->sleep = \Closure::fromCallable($sleep ?? static function (int $seconds): void {
            sleep($seconds);
        });
    }

    /**
     * Creates a Factur-X hybrid invoice: a PDF/A-3 with the EN 16931 CII XML embedded.
     *
     * @param array<string, mixed> $invoice InvoiceDocument fields, for example invoiceNumber, issueDate, currency, seller, buyer, lines.
     * @param array<string, mixed> $options InvoiceOptions, see the class description.
     *
     * @throws ApiException when the invoice would not be compliant ($errors lists the violated rules)
     */
    public function createFacturx(array $invoice, array $options = []): File
    {
        return $this->file($this->postJson('/v1/create/facturx', $invoice, $options), 'invoice-facturx.pdf');
    }

    /**
     * Creates a Factur-X invoice like createFacturx() and sends it as a real email from your
     * own verified domain. Returns the message id, not the file.
     *
     * @param array<string, mixed> $invoice InvoiceDocument fields.
     * @param array<string, mixed> $email   Email fields, see the class description.
     * @param array<string, mixed> $options InvoiceOptions, see the class description.
     *
     * @throws ApiException when the invoice would not be compliant or the email cannot be sent
     */
    public function emailFacturx(array $invoice, array $email, array $options = []): EmailResult
    {
        return $this->emailResult($this->postJson('/v1/create/facturx/email', $invoice, $options, $email));
    }

    /**
     * Validates a Factur-X PDF against the rule set of the profile its embedded XML declares.
     *
     * @param string|File  $pdf   Path to the PDF, or a File.
     * @param list<string> $rules Extra national rule sets, for example ["br-fr"] for the French mandate.
     */
    public function validateFacturx(string|File $pdf, array $rules = []): ValidationResult
    {
        return $this->validation($this->postMultipart('/v1/validate/facturx', ['file' => $pdf], ['rules' => $rules]));
    }

    /**
     * Validates a Factur-X PDF like validateFacturx() and returns a printable PDF report plus
     * the verdict. A failed verdict is a normal result, not an exception. Problems with the
     * upload itself, such as a missing or malformed file, throw ApiException.
     *
     * @param string|File  $pdf                Path to the PDF, or a File.
     * @param list<string> $rules              Extra national rule sets, for example ["br-fr"] for the French mandate.
     * @param string|null  $validationLanguage "en", "de" or "fr". Words the whole report.
     */
    public function validateFacturxReport(string|File $pdf, array $rules = [], ?string $validationLanguage = null): ValidationReport
    {
        $fields = ['rules' => $rules] + self::formFields(['validationLanguage' => $validationLanguage]);

        return $this->report($this->postMultipart('/v1/validate/facturx/report', ['file' => $pdf], $fields));
    }

    /**
     * Embeds an existing CII XML into an existing PDF and returns a Factur-X PDF/A-3.
     * The XML is validated before embedding unless $skipValidation is true.
     *
     * @param string|File  $pdf   Path to the visual PDF, or a File.
     * @param string|File  $xml   Path to the CII XML, or a File.
     * @param list<string> $rules Extra national rule sets checked before embedding, for example ["br-fr"].
     */
    public function embedFacturx(string|File $pdf, string|File $xml, bool $skipValidation = false, array $rules = []): File
    {
        $fields = ['rules' => $rules];
        if ($skipValidation) {
            $fields['skipValidation'] = 'true';
        }

        return $this->file($this->postMultipart('/v1/embed/facturx', ['pdf' => $pdf, 'xml' => $xml], $fields), 'invoice-facturx.pdf');
    }

    /**
     * Embeds an existing CII XML into an existing PDF like embedFacturx() and sends the
     * Factur-X PDF/A-3 as a real email from your own verified domain. Returns the message
     * id, not the file. The XML is always validated.
     *
     * @param string|File          $pdf   Path to the visual PDF, or a File.
     * @param string|File          $xml   Path to the CII XML, or a File.
     * @param array<string, mixed> $email Email fields, see the class description.
     * @param list<string>         $rules Extra national rule sets checked before embedding, for example ["br-fr"].
     *
     * @throws ApiException when the invoice would not be compliant or the email cannot be sent
     */
    public function emailEmbeddedFacturx(string|File $pdf, string|File $xml, array $email, array $rules = []): EmailResult
    {
        $fields = ['rules' => $rules] + self::emailFormFields($email);

        return $this->emailResult($this->postMultipart('/v1/embed/facturx/email', ['pdf' => $pdf, 'xml' => $xml], $fields));
    }

    /**
     * Renders a CII XML, such as the XML of a Factur-X invoice, as a human-readable PDF.
     *
     * @param string|File $xml           Path to the XML, or a File.
     * @param string|null $language      "en", "de" or "fr". Defaults to "en".
     * @param string|null $logoUrl       Public PNG or JPEG URL printed on the PDF.
     * @param bool|null   $includeQrCode True prints the payment QR code on the PDF.
     */
    public function renderCiiToPdf(string|File $xml, ?string $language = null, ?string $logoUrl = null, ?bool $includeQrCode = null): File
    {
        $fields = self::formFields(['language' => $language, 'logoUrl' => $logoUrl, 'includeQrCode' => $includeQrCode]);

        return $this->file($this->postMultipart('/v1/render/cii/to/pdf', ['file' => $xml], $fields), 'invoice.pdf');
    }

    /**
     * Converts the XML embedded in a Factur-X PDF to UBL.
     *
     * @param string|File $pdf                Path to the PDF, or a File.
     * @param string|null $validationLanguage "en", "de" or "fr". Words the findings of a failed conversion.
     *
     * @throws ApiException when the result would not be compliant ($errors lists the violated rules)
     */
    public function convertFacturxToUbl(string|File $pdf, ?string $validationLanguage = null): File
    {
        return $this->convert('/v1/convert/facturx/to/ubl', $pdf, 'invoice-ubl.xml', ['validationLanguage' => $validationLanguage]);
    }

    /**
     * Converts the XML embedded in a Factur-X PDF to plain CII.
     *
     * @param string|File $pdf                Path to the PDF, or a File.
     * @param string|null $validationLanguage "en", "de" or "fr". Words the findings of a failed conversion.
     *
     * @throws ApiException when the result would not be compliant ($errors lists the violated rules)
     */
    public function convertFacturxToCii(string|File $pdf, ?string $validationLanguage = null): File
    {
        return $this->convert('/v1/convert/facturx/to/cii', $pdf, 'invoice-cii.xml', ['validationLanguage' => $validationLanguage]);
    }

    /**
     * Converts a CII XML to a Factur-X PDF/A-3.
     *
     * @param string|File $xml                Path to the XML, or a File.
     * @param string|null $language           "en", "de" or "fr". Labels of the rendered PDF and default texts. Defaults to "en".
     * @param string|null $validationLanguage "en", "de" or "fr". Words the findings of a failed conversion.
     *
     * @throws ApiException when the result would not be compliant ($errors lists the violated rules)
     */
    public function convertCiiToFacturx(string|File $xml, ?string $language = null, ?string $validationLanguage = null): File
    {
        return $this->convert('/v1/convert/cii/to/facturx', $xml, 'invoice-facturx.pdf', ['language' => $language, 'validationLanguage' => $validationLanguage]);
    }

    /**
     * Converts a UBL XML to a Factur-X PDF/A-3.
     *
     * @param string|File $xml                Path to the XML, or a File.
     * @param string|null $language           "en", "de" or "fr". Labels of the rendered PDF and default texts. Defaults to "en".
     * @param string|null $validationLanguage "en", "de" or "fr". Words the findings of a failed conversion.
     *
     * @throws ApiException when the result would not be compliant ($errors lists the violated rules)
     */
    public function convertUblToFacturx(string|File $xml, ?string $language = null, ?string $validationLanguage = null): File
    {
        return $this->convert('/v1/convert/ubl/to/facturx', $xml, 'invoice-facturx.pdf', ['language' => $language, 'validationLanguage' => $validationLanguage]);
    }

    /**
     * Converts an XRechnung XML (UBL or CII) to a Factur-X PDF/A-3.
     *
     * @param string|File $xml                Path to the XML, or a File.
     * @param string|null $language           "en", "de" or "fr". Labels of the rendered PDF and default texts. Defaults to "en".
     * @param string|null $validationLanguage "en", "de" or "fr". Words the findings of a failed conversion.
     *
     * @throws ApiException when the result would not be compliant ($errors lists the violated rules)
     */
    public function convertXrechnungToFacturx(string|File $xml, ?string $language = null, ?string $validationLanguage = null): File
    {
        return $this->convert('/v1/convert/xrechnung/to/facturx', $xml, 'invoice-facturx.pdf', ['language' => $language, 'validationLanguage' => $validationLanguage]);
    }

    /**
     * Reads a PDF invoice and returns a Factur-X PDF/A-3. AI reads the PDF, so a person must
     * check the result before it is used. A 429 on this method is not retried automatically,
     * so wait for Retry-After before calling again if you want to retry.
     *
     * @param string|File $pdf                       Path to the PDF, or a File.
     * @param string|null $language                  "en", "de", "fr" or "auto". Labels of the rendered PDF and default texts. Defaults to "auto", the language the invoice is written in.
     * @param bool|null   $preserveOriginalPdf       True keeps the uploaded PDF as the visual layer and only embeds the XML. Earlier attachments and form fields are not kept.
     * @param bool|null   $includeAdvancedProperties False leaves the electronic invoice details section out of the rendered PDF. On by default, ignored with $preserveOriginalPdf.
     * @param string|null $validationLanguage        "en", "de" or "fr". Words the findings of a refused invoice.
     *
     * @throws ApiException when the invoice would not be compliant ($errors lists the violated rules)
     */
    public function transformToFacturx(
        string|File $pdf,
        ?string $language = null,
        ?bool $preserveOriginalPdf = null,
        ?bool $includeAdvancedProperties = null,
        ?string $validationLanguage = null,
    ): File {
        $fields = self::formFields([
            'language' => $language,
            'preserveOriginalPdf' => $preserveOriginalPdf,
            'includeAdvancedProperties' => $includeAdvancedProperties,
            'validationLanguage' => $validationLanguage,
        ]);

        return $this->file($this->postMultipart('/v1/transform/to/facturx', ['file' => $pdf], $fields, retryRateLimit: false), 'invoice-facturx.pdf');
    }

    /**
     * Reads a Factur-X PDF or a CII XML into invoice data. Deterministic: the XML is
     * parsed, no AI is involved. The returned ['invoice' => ...] array has the same
     * shape createFacturx() accepts.
     *
     * @param string|File $file Path to the PDF or XML, or a File.
     * @return array<string, mixed>
     */
    public function extractJson(string|File $file): array
    {
        return $this->json($this->postMultipart('/v1/extract/json', ['file' => $file]));
    }

    /**
     * Extracts the supporting documents attached to an invoice as a ZIP archive.
     *
     * @param string|File $file Path to the PDF or XML, or a File.
     */
    public function extractAttachments(string|File $file): File
    {
        return $this->file($this->postMultipart('/v1/extract/attachments', ['file' => $file]), 'attachments.zip');
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>      $options
     * @param array<string, mixed>|null $email   Email fields of the email routes.
     */
    private function postJson(string $path, array $invoice, array $options, ?array $email = null): ResponseInterface
    {
        $body = ['invoice' => $invoice];
        if ($options !== []) {
            $body['options'] = $options;
        }
        if ($email !== null) {
            $body['email'] = self::emailFields($email);
        }

        return $this->send($path, 'application/json', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The email as sent, by the same rules on the JSON and the form routes. Null, empty strings
     * and empty lists are never sent, also not as cc or bcc entries. Only cc and bcc take a list,
     * because a form route would lose all but the first item of any other field.
     *
     * @param array<string, mixed> $email
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when a single-value field holds a list or a text is not valid UTF-8
     */
    private static function emailFields(array $email): array
    {
        $fields = [];
        foreach ($email as $name => $value) {
            if ($name === 'cc' || $name === 'bcc') {
                $value = self::copyAddresses($value);
            } elseif (is_array($value)) {
                throw new \InvalidArgumentException("email.{$name} takes a single value. Only cc and bcc take a list.");
            }

            foreach ((array) $value as $text) {
                if (is_string($text) && preg_match('//u', $text) !== 1) {
                    throw new \InvalidArgumentException("email.{$name} is not valid UTF-8.");
                }
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    /** @return list<string> One address or a list, trimmed and without blank entries. */
    private static function copyAddresses(mixed $addresses): array
    {
        $kept = [];
        foreach ((array) $addresses as $address) {
            $address = trim((string) $address);
            if ($address !== '') {
                $kept[] = $address;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $email
     * @return array<string, string|list<string>> The email.* form fields of the multipart email routes.
     */
    private static function emailFormFields(array $email): array
    {
        $fields = [];
        foreach (self::emailFields($email) as $name => $value) {
            $fields['email.' . $name] = $value;
        }

        return $fields;
    }

    /**
     * The optional form fields as sent. Null means not set and is never sent, so the API's
     * default stays in charge. A bool goes out as the text true or false.
     *
     * @param array<string, string|bool|null> $fields
     * @return array<string, string>
     */
    private static function formFields(array $fields): array
    {
        $sent = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $sent[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        return $sent;
    }

    /** @param array<string, string|null> $fields */
    private function convert(string $path, string|File $file, string $fallbackName, array $fields): File
    {
        return $this->file($this->postMultipart($path, ['file' => $file], self::formFields($fields)), $fallbackName);
    }

    /**
     * @param array<string, string|File>              $files
     * @param array<string, string|list<string>>      $fields A list value is sent as one repeated field per item.
     */
    private function postMultipart(string $path, array $files, array $fields = [], bool $retryRateLimit = true): ResponseInterface
    {
        $boundary = 'invoicexml-' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($files as $name => $file) {
            $file = $file instanceof File ? $file : File::fromPath($file);
            $filename = str_replace(['"', "\r", "\n"], '', $file->filename);
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: {$file->contentType}\r\n\r\n"
                . $file->contents . "\r\n";
        }

        foreach ($fields as $name => $values) {
            foreach ((array) $values as $value) {
                $body .= "--{$boundary}\r\n"
                    . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                    . $value . "\r\n";
            }
        }

        $body .= "--{$boundary}--\r\n";

        return $this->send($path, 'multipart/form-data; boundary=' . $boundary, $body, $retryRateLimit);
    }

    private function send(string $path, string $contentType, string $body, bool $retryRateLimit = true): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $request = $this->requestFactory->createRequest('POST', $this->baseUrl . $path)
                ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
                ->withHeader('Content-Type', $contentType)
                ->withHeader('Accept', '*/*')
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();

            if ($status === 429 && $retryRateLimit && $attempt < $this->maxRetries) {
                $attempt++;
                ($this->sleep)($this->retryAfter($response));
                continue;
            }

            if ($status >= 400) {
                throw $this->exception($response);
            }

            return $response;
        }
    }

    private function retryAfter(ResponseInterface $response): int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header !== '' && ctype_digit($header)) {
            $seconds = (int) $header;
        } elseif ($header !== '' && ($date = strtotime($header)) !== false) {
            $seconds = $date - time();
        } else {
            $seconds = 1;
        }

        return max(0, min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }

    private function exception(ResponseInterface $response): ApiException
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $raw === '' ? [] : ['detail' => $raw];
        }

        $title = isset($body['title']) ? (string) $body['title'] : $response->getReasonPhrase();
        $detail = isset($body['detail']) ? (string) $body['detail'] : null;
        $errorCode = isset($body['errorCode']) ? (int) $body['errorCode'] : null;

        if ($status === 429) {
            return new TooManyRequestsException($this->retryAfter($response), $title, $detail, $errorCode, $body);
        }

        return new ApiException(
            $status,
            $title,
            $detail,
            $errorCode,
            Finding::listFrom($body['errors'] ?? []),
            Finding::listFrom($body['warnings'] ?? []),
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('The InvoiceXML API returned an unexpected response body.');
        }

        return $decoded;
    }

    private function validation(ResponseInterface $response): ValidationResult
    {
        return ValidationResult::fromArray($this->json($response));
    }

    private function emailResult(ResponseInterface $response): EmailResult
    {
        return EmailResult::fromArray($this->json($response));
    }

    private function report(ResponseInterface $response): ValidationReport
    {
        $valid = match (strtolower(trim($response->getHeaderLine('X-Invoice-Valid')))) {
            'true' => true,
            'false' => false,
            default => null,
        };

        return new ValidationReport($this->file($response, 'validation-report.pdf'), $valid);
    }

    private function file(ResponseInterface $response, string $fallbackName): File
    {
        $contentType = $response->getHeaderLine('Content-Type');

        return new File(
            (string) $response->getBody(),
            self::filename($response->getHeaderLine('Content-Disposition')) ?? $fallbackName,
            $contentType !== '' ? trim(explode(';', $contentType)[0]) : null,
        );
    }

    private static function filename(string $disposition): ?string
    {
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $m) === 1) {
            return basename(rawurldecode(trim($m[1])));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return basename(trim($m[1]));
        }

        return null;
    }
}
