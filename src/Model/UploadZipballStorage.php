<?php

declare(strict_types=1);

namespace Packeton\Model;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Zipball;
use Packeton\Util\PacketonUtils;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

class UploadZipballStorage
{
    protected static $supportTypes = ['gz', 'tar', 'tgz', 'zip'];

    public function __construct(
        protected ManagerRegistry $registry,
        protected ?string $artifactStorage,
    ) {
    }

    public function remove(Zipball $zip): void
    {
        $manager = $this->registry->getManager();
        $manager->remove($zip);
        $manager->flush();

        @unlink($this->getPath($zip));
    }

    public function getPath(Zipball|string $zip): string
    {
        return PacketonUtils::buildPath($this->artifactStorage, $zip instanceof Zipball ? $zip->getFilename() : $zip);
    }

    public function save(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $extension = $this->guessExtension($file, $mime);
        $size = $file->getSize();

        // Limited by ArtifactRepository, mimetype will check later, not necessary a strict validation
        if (!in_array($extension, self::$supportTypes, true)) {
            return ['code' => 400, 'error' => "Allowed only *.tgz *.zip archives, but given .$extension"];
        }

        $hash = sha1(random_bytes(30));
        $filename = $hash.'.'.$extension;

        try {
            $file->move($this->artifactStorage, $filename);
        } catch (\RuntimeException $e) {
            return ['code' => 400, 'error' => $e];
        }

        $zipball = new Zipball();
        $zipball->setOriginalFilename($file->getClientOriginalName())
            ->setExtension($extension)
            ->setFileSize($size)
            ->setMimeType($mime)
            ->setFilename($filename);

        $manager = $this->registry->getManager();
        $manager->persist($zipball);
        $manager->flush();

        return [
            'id' => $zipball->getId(),
            'filename' => $zipball->getOriginalFilename(),
            'size' => $zipball->getFileSize(),
        ];
    }

    protected function guessExtension(UploadedFile $file, ?string $mimeType): ?string
    {
        if (str_ends_with($file->getClientOriginalName(), 'tar.gz')) {
            return 'tgz';
        }
        if ($extension = $file->getClientOriginalExtension()) {
            return $extension;
        }

        return $mimeType ? (MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null) : null;
    }
}
