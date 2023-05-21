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
    public function __construct(
        protected ManagerRegistry $registry,
        protected ?string $artifactStorage,
        protected array $supportTypes
    ) {
    }

    public function remove(Zipball $zip): void
    {
        $manager = $this->registry->getManager();
        $manager->remove($zip);
        $manager->flush();

        @unlink($this->getPath($zip));
    }

    public function getPath(Zipball|string $zipOrReference): ?string
    {
        if (is_string($zipOrReference)) {
            $zip = $this->registry->getRepository(Zipball::class)->findOneBy(['reference' => $zipOrReference]);
        } else {
            $zip = $zipOrReference;
        }

        if (empty($zip)) {
            return null;
        }

        return PacketonUtils::buildPath($this->artifactStorage, $zip->getFilename());
    }

    public function save(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $extension = $this->guessExtension($file, $mime);
        $size = $file->getSize();

        // Limited by ArtifactRepository, mimetype will check later, not necessary a strict validation.
        if (!in_array($extension, $this->supportTypes, true)) {
            $supportTypes = json_encode($this->supportTypes);
            return ['code' => 400, 'error' => "Allowed only $supportTypes archives, but given *.$extension"];
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
            ->setReference($hash)
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
