<?php

namespace Packeton\Command;

use Firebase\JWT\JWT;
use Packeton\Security\JWSTokenProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class GenerateJWTPairsCommand extends Command
{
    protected static $defaultName = 'packagist:jwt:generate-keypair';
    protected static $defaultDescription = 'Generate JWT public/private keys for packeton jwt_authentication';

    public function __construct(
        protected Filesystem $filesystem,
        protected array $jwtTokenConfig,
        protected string $jwtSignAlgorithm
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite key files if they already exist.');
        $this->addOption('algo', null, InputOption::VALUE_OPTIONAL, 'Key sign algo, default config value.', $this->jwtSignAlgorithm);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $algo = $input->getOption('algo') ?: $this->jwtSignAlgorithm;

        if (!isset(JWT::$supported_algs[$algo])) {
            throw new LogicException("Algo $algo is not support. Use:" . implode(",", array_keys(JWT::$supported_algs)));
        }

        [$privateKey, $publicKey] = isset(JWSTokenProvider::$supportedSodiumAlgos[$algo]) ?
            $this->generateSodiumKeyPair($algo) : $this->generateOpenSSLKeyPair($algo, $this->jwtTokenConfig['passphrase'] ?? null);

        $filePrivate = $this->jwtTokenConfig['private_key'] ?? null;
        $filePublic = $this->jwtTokenConfig['public_key'] ?? null;

        if (empty($filePrivate) || empty($filePublic)) {
            $io->warning('JWT authorization is not setup.');
            $io->warning('Config options packeton->jwt_authentication is empty, to use JWT API authorization, please update your config!');
            $this->showGeneratedResult($privateKey, $publicKey, $io);
            return 0;
        }

        if ($this->isNotFilename($filePrivate) || $this->isNotFilename($filePublic)) {
            $io->info("The keys have been generated. But $filePrivate is not a filename");
            $this->showGeneratedResult($privateKey, $publicKey, $io);
            return 0;
        }

        $alreadyExists = $this->filesystem->exists($filePrivate) || $this->filesystem->exists($filePublic);

        if (!$alreadyExists || $input->getOption('overwrite')) {
            if ($alreadyExists) {
                $io->info("Overwrite existing keys");
            }

            $this->filesystem->dumpFile($filePrivate, $privateKey);
            $this->filesystem->dumpFile($filePublic, $publicKey);
            $this->showGeneratedResult($privateKey, $publicKey, $io);
            $io->success('Done!');
        } else {
            $io->warning("The keys already exists, to overwrite use --overwrite options");
            $this->showGeneratedResult($privateKey, $publicKey, $io);
        }

        return 0;
    }

    private function isNotFilename($pem)
    {
        return str_contains($pem, PHP_EOL) || str_contains($pem, '=');
    }

    private function showGeneratedResult($privateKey, $publicKey, SymfonyStyle $io)
    {
        $io->newLine();
        $io->writeln('Generated private key:');
        $io->writeln($privateKey);
        $io->newLine();
        $io->writeln('Generated public key:');
        $io->writeln($publicKey);
    }

    private function generateSodiumKeyPair(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));

        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        return [$privateKey, $publicKey];
    }

    private function generateOpenSSLKeyPair(string $algo, $passphrase = null): array
    {
        $config = $this->buildOpenSSLConfiguration($algo);

        $resource = \openssl_pkey_new($config);
        if (false === $resource) {
            throw new \RuntimeException(\openssl_error_string());
        }

        $success = \openssl_pkey_export($resource, $privateKey, $passphrase);

        if (false === $success) {
            throw new \RuntimeException(\openssl_error_string());
        }

        $publicKeyData = \openssl_pkey_get_details($resource);

        if (false === $publicKeyData) {
            throw new \RuntimeException(\openssl_error_string());
        }

        $publicKey = $publicKeyData['key'];

        return [$privateKey, $publicKey];
    }

    private function buildOpenSSLConfiguration(string $algo): array
    {
        $digestAlgorithms = [
            'RS256' => 'sha256',
            'RS384' => 'sha384',
            'RS512' => 'sha512',
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            'ES256' => 'sha256',
            'ES384' => 'sha384',
        ];

        $privateKeyBits = [
            'RS256' => 2048,
            'RS384' => 2048,
            'RS512' => 4096,
            'HS256' => 384,
            'HS384' => 384,
            'HS512' => 512,
            'ES256' => 384,
            'ES384' => 512,
        ];
        $privateKeyTypes = [
            'RS256' => \OPENSSL_KEYTYPE_RSA,
            'RS384' => \OPENSSL_KEYTYPE_RSA,
            'RS512' => \OPENSSL_KEYTYPE_RSA,
            'HS256' => \OPENSSL_KEYTYPE_DH,
            'HS384' => \OPENSSL_KEYTYPE_DH,
            'HS512' => \OPENSSL_KEYTYPE_DH,
            'ES256' => \OPENSSL_KEYTYPE_EC,
            'ES384' => \OPENSSL_KEYTYPE_EC,
        ];

        $curves = [
            'ES256' => 'secp256k1',
            'ES384' => 'secp384r1',
        ];

        $config = [
            'digest_alg' => $digestAlgorithms[$algo],
            'private_key_type' => $privateKeyTypes[$algo],
            'private_key_bits' => $privateKeyBits[$algo],
        ];

        if (isset($curves[$algo])) {
            $config['curve_name'] = $curves[$algo];
        }

        return $config;
    }
}
