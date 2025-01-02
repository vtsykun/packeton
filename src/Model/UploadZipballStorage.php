<?php

declare(strict_types=1);

namespace Packeton\Model;

use Doctrine\Persistence\ManagerRegistry;
use League\Flysystem\FilesystemOperator;
use Packeton\Entity\Zipball;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

class UploadZipballStorage
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected FilesystemOperator $artifactStorage,
        protected Filesystem $fs,
        protected array $supportTypes,
        protected ?string $tmpDir = null,
    ) {
        $this->tmpDir ??= sys_get_temp_dir();
    }

    public function remove(Zipball $zip): void
    {
        $manager = $this->registry->getManager();
        $manager->remove($zip);
        $manager->flush();

        try {
            $this->artifactStorage->delete($zip->getFilename());
        } catch (\Exception $e) {
        }
    }

    public function moveToLocal(Zipball|string $zipOrReference): ?string
    {
        if (is_string($zipOrReference)) {
            $zip = $this->registry->getRepository(Zipball::class)->findOneBy(['reference' => $zipOrReference]);
        } else {
            $zip = $zipOrReference;
        }

        if (empty($zip)) {
            return null;
        }

        $localName = PacketonUtils::buildPath($this->tmpDir, $zip->getFilename());
        if ($this->fs->exists($localName)) {
            return $localName;
        }

        $stream = $this->artifactStorage->readStream($zip->getFilename());

        $dirname = dirname($localName);
        if (!$this->fs->exists($dirname)) {
            $this->fs->mkdir($dirname);
        }

        $local = fopen($localName, 'w+b');
        stream_copy_to_stream($stream, $local);

        return $localName;
    }

    public function save(File $file): array
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
            $file->move($this->tmpDir, $filename);
            $fullname = PacketonUtils::buildPath($this->tmpDir, $filename);
            if (!$this->artifactStorage->fileExists($filename)) {
                $stream = fopen($fullname, 'r');
                $this->artifactStorage->writeStream($filename, $stream);
            }

        } catch (\Exception $e) {
            return ['code' => 400, 'error' => $e->getMessage()];
        }

        $zipball = new Zipball();
        $zipball->setOriginalFilename($file instanceof UploadedFile ? $file->getClientOriginalName() : time() . '.zip')
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

    protected function guessExtension(File $file, ?string $mimeType): ?string
    {
        if ($file instanceof UploadedFile) {
            if (str_ends_with($file->getClientOriginalName(), 'tar.gz')) {
                return 'tgz';
            }
            if ($extension = $file->getClientOriginalExtension()) {
                return $extension;
            }
        }

        return $mimeType ? (MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null) : null;
    }
}
