<?php

declare(strict_types=1);

namespace Packeton\Form\RequestHandler;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

#[Exclude]
class PutRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $requestHandle,
        private readonly string $singleFieldName = 'file',
    ) {
    }

    public function handleRequest(FormInterface $form, mixed $request = null): void
    {
        if ($request instanceof Request && $request->getMethod() === 'PUT') {
            $this->decodeMultiPartFormData($request);
        }
        $this->requestHandle->handleRequest($form, $request);
    }

    public function isFileUpload(mixed $data): bool
    {
        return $data instanceof File;
    }

    private function decodeMultiPartFormData(Request $request): void
    {
        $rawData = $request->getContent();
        if (!$rawData) {
            return;
        }

        $contentType = $request->headers->get('Content-Type');
        if (null === $contentType || !str_contains($contentType, 'multipart/form-data')) {
            $this->createUploadedFile(
                request: $request,
                content: $rawData,
                fileName: (string)time(),
                fieldName: $this->singleFieldName,
                contentType: $contentType
            );
            return;
        }

        $boundary = substr($rawData, 0, strpos($rawData, "\r\n"));
        $parts = array_slice(explode($boundary, $rawData), 1);
        foreach ($parts as $part) {
            if ($part == "--\r\n") {
                break;
            }

            $part = ltrim($part, "\r\n");
            [$rawHeaders, $content] = explode("\r\n\r\n", $part, 2);
            $content = substr($content, 0, strlen($content) - 2);

            $rawHeaders = explode("\r\n", $rawHeaders);
            $headers = [];
            foreach ($rawHeaders as $header) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower($name)] = ltrim($value, ' ');
            }

            if (!isset($headers['content-disposition'])) {
                continue;
            }

            preg_match('/^form-data; *name="([^"]+)"(?:; *filename="([^"]+)")?/', $headers['content-disposition'], $matches);
            $fieldName = $matches[1];
            $fileName = ($matches[2] ?? null);
            if ($fileName === null) {
                $request->request->set($fieldName, $content);
            } else {
                $this->createUploadedFile(
                    request: $request,
                    content: $content,
                    fileName: $fileName,
                    fieldName: $fieldName,
                    contentType: $headers['content-type'] ?? null,
                );
            }
        }
    }

    private function createUploadedFile(Request $request, string $content, string $fileName, string $fieldName, ?string $contentType): void
    {
        $localFileName = tempnam(sys_get_temp_dir(), 'sfy');
        file_put_contents($localFileName, $content);
        $contentType ??= 'binary/octet-stream';

        register_shutdown_function(static function () use ($localFileName) {
            @unlink($localFileName);
        });

        $file = new UploadedFile(
            path: $localFileName,
            originalName: $fileName,
            mimeType: $contentType,
            error: 0,
            test: true
        );

        $request->files->set($fieldName, $file);
    }
}
