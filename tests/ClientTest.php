<?php

declare(strict_types=1);

namespace InvoiceXml\FacturX\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvoiceXml\FacturX\ApiException;
use InvoiceXml\FacturX\Client;
use InvoiceXml\FacturX\File;
use InvoiceXml\FacturX\TooManyRequestsException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @var list<int> */
    private array $sleeps = [];

    private function client(Response ...$responses): Client
    {
        $this->history = [];
        $this->sleeps = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(
            'test-key',
            new GuzzleClient(['handler' => $stack]),
            sleep: function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /** @return array<string, mixed> */
    private static function invoice(): array
    {
        return [
            'invoiceNumber' => 'FA-2026-0142',
            'issueDate' => '2026-05-17',
            'currency' => 'EUR',
            'seller' => ['name' => 'Atelier Lumière SARL', 'vatIdentifier' => 'FR12345678901'],
            'buyer' => ['name' => 'Globex SAS'],
            'lines' => [['quantity' => 2, 'priceDetails' => ['netPrice' => 450], 'vatInformation' => ['rate' => 20], 'item' => ['name' => 'Conseil']]],
        ];
    }

    private static function pdfResponse(string $name = 'invoice-facturx-FA-1.pdf'): Response
    {
        return new Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}\"; filename*=UTF-8''" . rawurlencode($name),
        ], '%PDF-1.7 test');
    }

    /** @param array<string, mixed> $body */
    private static function jsonResponse(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testCreateFacturxPostsEnvelopeAndReturnsPdf(): void
    {
        $client = $this->client(self::pdfResponse());

        $file = $client->createFacturx(self::invoice(), ['language' => 'fr', 'rules' => ['br-fr']]);

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.invoicexml.com/v1/create/facturx', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            ['invoice' => self::invoice(), 'options' => ['language' => 'fr', 'rules' => ['br-fr']]],
            json_decode((string) $request->getBody(), true),
        );

        self::assertSame('%PDF-1.7 test', $file->contents);
        self::assertSame('invoice-facturx-FA-1.pdf', $file->filename);
        self::assertSame('application/pdf', $file->contentType);
    }

    public function testCreateOmitsOptionsWhenEmpty(): void
    {
        $client = $this->client(new Response(200, ['Content-Type' => 'application/pdf'], '%PDF'));

        $file = $client->createFacturx(self::invoice());

        self::assertSame(['invoice' => self::invoice()], json_decode((string) $this->lastRequest()->getBody(), true));
        self::assertSame('invoice-facturx.pdf', $file->filename);
    }

    public function testRejectedCreateThrowsWithFindings(): void
    {
        $client = $this->client(self::jsonResponse([
            'title' => 'Invoice validation failed',
            'status' => 400,
            'detail' => 'The generated invoice violates 1 rule.',
            'valid' => false,
            'errorCode' => 4001,
            'errors' => [[
                'rule' => 'BR-CO-15',
                'layer' => 'en16931',
                'line' => 40,
                'message' => 'Total with VAT does not match.',
                'fields' => ['totals.invoiceTotalWithVat'],
                'raw' => '[BR-CO-15] ...',
            ]],
            'warnings' => [],
        ], 400));

        try {
            $client->createFacturx(self::invoice());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame(4001, $e->errorCode);
            self::assertCount(1, $e->errors);
            self::assertSame('BR-CO-15', $e->errors[0]->rule);
            self::assertSame('en16931', $e->errors[0]->layer);
            self::assertSame(['totals.invoiceTotalWithVat'], $e->errors[0]->fields);
        }
    }

    public function testValidateFacturxUploadsMultipartAndMapsResult(): void
    {
        $client = $this->client(self::jsonResponse([
            'valid' => true,
            'detail' => 'Valid.',
            'data' => ['hasEmbeddedXml' => true, 'schemaValid' => true, 'schematronValid' => true, 'profile' => 'en16931'],
            'errors' => [],
            'warnings' => [['rule' => 'PROFILE', 'layer' => null, 'line' => null, 'message' => 'Advisory.', 'fields' => [], 'raw' => null]],
            'report' => [['name' => 'Invoice number', 'path' => 'invoiceNumber', 'value' => 'FA-1', 'isValid' => true, 'errors' => [], 'warnings' => []]],
        ]));

        $result = $client->validateFacturx(new File('%PDF-1.7 bytes', 'facture.pdf'));

        $request = $this->lastRequest();
        $body = (string) $request->getBody();
        self::assertSame('https://api.invoicexml.com/v1/validate/facturx', (string) $request->getUri());
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="facture.pdf"', $body);
        self::assertStringContainsString('Content-Type: application/pdf', $body);
        self::assertStringNotContainsString('name="rules"', $body);

        self::assertTrue($result->valid);
        self::assertSame('en16931', $result->data['profile']);
        self::assertSame([], $result->errors);
        self::assertSame('PROFILE', $result->warnings[0]->rule);
        self::assertSame('invoiceNumber', $result->report[0]['path']);
    }

    public function testValidateFacturxSendsEachRuleAsRepeatedField(): void
    {
        $client = $this->client(self::jsonResponse(['valid' => false, 'errors' => [], 'warnings' => []]));

        $client->validateFacturx(new File('%PDF', 'f.pdf'), ['br-fr', 'other']);

        $body = (string) $this->lastRequest()->getBody();
        self::assertStringContainsString("name=\"rules\"\r\n\r\nbr-fr\r\n", $body);
        self::assertStringContainsString("name=\"rules\"\r\n\r\nother\r\n", $body);
    }

    public function testValidateFacturxReadsFileFromPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ixml') . '.pdf';
        file_put_contents($path, '%PDF-1.7');

        try {
            $client = $this->client(self::jsonResponse(['valid' => true, 'errors' => [], 'warnings' => []]));
            $client->validateFacturx($path);
        } finally {
            unlink($path);
        }

        self::assertStringContainsString('filename="' . basename($path) . '"', (string) $this->lastRequest()->getBody());
    }

    public function testEmbedFacturxSendsPartsRulesAndSkipValidation(): void
    {
        $client = $this->client(self::pdfResponse());

        $client->embedFacturx(new File('%PDF-1.7 visual', 'visual.pdf'), new File('<rsm:CrossIndustryInvoice/>', 'factur-x.xml'), true, ['br-fr']);

        $body = (string) $this->lastRequest()->getBody();
        self::assertSame('https://api.invoicexml.com/v1/embed/facturx', (string) $this->lastRequest()->getUri());
        self::assertStringContainsString('name="pdf"; filename="visual.pdf"', $body);
        self::assertStringContainsString('name="xml"; filename="factur-x.xml"', $body);
        self::assertStringContainsString("name=\"skipValidation\"\r\n\r\ntrue", $body);
        self::assertStringContainsString("name=\"rules\"\r\n\r\nbr-fr", $body);
    }

    public function testEmbedFacturxDefaultsSendNoOptionalFields(): void
    {
        $client = $this->client(self::pdfResponse());

        $client->embedFacturx(new File('%PDF', 'a.pdf'), new File('<x/>', 'a.xml'));

        $body = (string) $this->lastRequest()->getBody();
        self::assertStringNotContainsString('skipValidation', $body);
        self::assertStringNotContainsString('name="rules"', $body);
    }

    public function testExtractJsonReturnsInvoiceEnvelope(): void
    {
        $client = $this->client(self::jsonResponse(['invoice' => self::invoice()]));

        $result = $client->extractJson(new File('%PDF', 'in.pdf'));

        self::assertSame('https://api.invoicexml.com/v1/extract/json', (string) $this->lastRequest()->getUri());
        self::assertSame(self::invoice(), $result['invoice']);
    }

    public function testExtractAttachmentsReturnsZip(): void
    {
        $client = $this->client(new Response(200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="in-attachments.zip"',
        ], "PK\x03\x04"));

        $file = $client->extractAttachments(new File('%PDF', 'in.pdf'));

        self::assertSame('https://api.invoicexml.com/v1/extract/attachments', (string) $this->lastRequest()->getUri());
        self::assertSame('in-attachments.zip', $file->filename);
        self::assertSame('application/zip', $file->contentType);
    }

    public function testUnicodeFilenameIsDecoded(): void
    {
        $client = $this->client(new Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"Facture-Lumi_re.pdf\"; filename*=UTF-8''Facture-Lumi%C3%A8re.pdf",
        ], '%PDF'));

        self::assertSame('Facture-Lumière.pdf', $client->createFacturx(self::invoice())->filename);
    }

    public function testRetriesAfter429AndHonoursRetryAfter(): void
    {
        $client = $this->client(
            self::jsonResponse(['status' => 429, 'errorCode' => 4019], 429, ['Retry-After' => '7']),
            self::pdfResponse(),
        );

        $file = $client->createFacturx(self::invoice());

        self::assertSame([7], $this->sleeps);
        self::assertCount(2, $this->history);
        self::assertSame(
            (string) $this->history[0]['request']->getBody(),
            (string) $this->history[1]['request']->getBody(),
        );
        self::assertSame('%PDF-1.7 test', $file->contents);
    }

    public function testThrowsTooManyRequestsWhenRetriesAreUsedUp(): void
    {
        $limited = static fn (): Response => self::jsonResponse(
            ['title' => 'Too Many Requests', 'status' => 429, 'detail' => 'Retry later.', 'errorCode' => 4019],
            429,
            ['Retry-After' => '3'],
        );
        $client = $this->client($limited(), $limited(), $limited(), $limited());

        try {
            $client->validateFacturx(new File('%PDF', 'f.pdf'));
            self::fail('Expected TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(3, $e->retryAfter);
            self::assertSame(4019, $e->errorCode);
        }

        self::assertSame([3, 3, 3], $this->sleeps);
        self::assertCount(4, $this->history);
    }

    public function testRetriesCanBeDisabled(): void
    {
        $stack = HandlerStack::create(new MockHandler([self::jsonResponse(['status' => 429], 429, ['Retry-After' => '5'])]));
        $client = new Client('k', new GuzzleClient(['handler' => $stack]), maxRetries: 0);

        $this->expectException(TooManyRequestsException::class);
        $client->createFacturx(self::invoice());
    }

    public function testUnauthorizedWithNonJsonBody(): void
    {
        $client = $this->client(new Response(401, [], 'Unauthorized'));

        try {
            $client->createFacturx(self::invoice());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('Unauthorized', $e->detail);
            self::assertNull($e->errorCode);
        }
    }

    public function testCustomBaseUrl(): void
    {
        $stack = HandlerStack::create(new MockHandler([self::pdfResponse()]));
        $history = [];
        $stack->push(Middleware::history($history));
        $client = new Client('k', new GuzzleClient(['handler' => $stack]), baseUrl: 'https://staging.example.test/');

        $client->createFacturx(self::invoice());

        self::assertSame('https://staging.example.test/v1/create/facturx', (string) $history[0]['request']->getUri());
    }

    public function testEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testMissingUploadPathIsRejected(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->validateFacturx('/does/not/exist.pdf');
    }
}
