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
     * @param int                          $maxRetries     How often a 429 is retried after waiting Retry-After seconds. 0 disables retries.
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
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $options
     */
    private function postJson(string $path, array $invoice, array $options): ResponseInterface
    {
        $body = ['invoice' => $invoice];
        if ($options !== []) {
            $body['options'] = $options;
        }

        return $this->send($path, 'application/json', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, string|File>              $files
     * @param array<string, string|list<string>>      $fields A list value is sent as one repeated field per item.
     */
    private function postMultipart(string $path, array $files, array $fields = []): ResponseInterface
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

        return $this->send($path, 'multipart/form-data; boundary=' . $boundary, $body);
    }

    private function send(string $path, string $contentType, string $body): ResponseInterface
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

            if ($status === 429 && $attempt < $this->maxRetries) {
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
