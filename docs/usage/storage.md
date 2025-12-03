# S3 Storage Provider

By default, Packeton stores packages archives on the local filesystem. But you can easily configure 
the S3 using `league/flysystem-bundle`.

For docker env, please set env vars. 

```
STORAGE_SOURCE=s3
STORAGE_AWS_BUCKET=packeton-bucket
STORAGE_AWS_PREFIX=packeton
STORAGE_AWS_ARTIFACT_PREFIX=artifact

STORAGE_AWS_ARGS='{"endpoint": "https://s3.waw.io.cloud.ovh.net", "accessKeyId": "xxx", "accessKeySecret": "xxx", "region": "waw"}'
```

## IRSA (IAM Roles for Service Accounts) - EKS/Kubernetes

For Kubernetes/EKS deployments, you can use IRSA to authenticate without static credentials.
The pod's service account assumes an IAM role via web identity token.

### Explicit Configuration

```
STORAGE_SOURCE=s3
STORAGE_AWS_BUCKET=packeton-bucket
STORAGE_AWS_ARGS='{"region": "us-east-1", "roleArn": "arn:aws:iam::123456789:role/packeton-s3-role", "webIdentityTokenFile": "/var/run/secrets/eks.amazonaws.com/serviceaccount/token"}'
```

Configuration keys:
- `roleArn` - ARN of the IAM role to assume
- `webIdentityTokenFile` - Path to the OIDC token file (injected by EKS)
- `roleSessionName` - Optional session identifier (defaults to `async-aws-*`)

### Auto-detection via Environment Variables

AsyncAws automatically detects IRSA when standard AWS environment variables are set by EKS:

- `AWS_WEB_IDENTITY_TOKEN_FILE`
- `AWS_ROLE_ARN`
- `AWS_REGION`

With IRSA properly configured on your EKS cluster, you may only need:

```
STORAGE_SOURCE=s3
STORAGE_AWS_BUCKET=packeton-bucket
STORAGE_AWS_ARGS='{"region": "us-east-1"}'
```

The SDK will automatically use the injected token file and role ARN from environment variables.

See [AsyncAws Authentication](https://async-aws.com/authentication.html) for more details.

## Mirror Storage (Stateless Mode)

When `STORAGE_SOURCE=s3`, mirrored repositories metadata and zipballs are also stored in S3.
This enables running Packeton in stateless mode.

Additional env vars for mirror storage (optional):

```
# S3 prefixes for mirror data (defaults shown)
STORAGE_AWS_MIRROR_META_PREFIX=mirror-meta
STORAGE_AWS_MIRROR_DIST_PREFIX=mirror-dist

# Optional local cache directories. If empty, data is read/written directly to S3 (fully stateless).
# Set to /tmp paths for ephemeral caching in container environments.
MIRROR_METADATA_CACHE_DIR=
MIRROR_DIST_CACHE_DIR=
```

Example for fully stateless deployment:
```
STORAGE_SOURCE=s3
STORAGE_AWS_BUCKET=my-bucket
MIRROR_METADATA_CACHE_DIR=
MIRROR_DIST_CACHE_DIR=
```

Example with ephemeral local cache (recommended for performance):
```
STORAGE_SOURCE=s3
STORAGE_AWS_BUCKET=my-bucket
MIRROR_METADATA_CACHE_DIR=/tmp/mirror-meta
MIRROR_DIST_CACHE_DIR=/tmp/mirror-dist
```

Sometimes for Artifact Repository requires direct access to files from the archive, so to
improve performance and reduces count of S3 API requests, the all archives are cached on the local filesystem too. 

If you need to use the other provider, like Google Cloud, you may add config file to `config/packages` or use `config.yaml` in data docker dir.

```yaml
flysystem:
    storages:
        s3_v2.storage:
            adapter: 'asyncaws'
            options:
                client: 'packeton.s3.storage'
                bucket: '%env(STORAGE_AWS_BUCKET)%'
                prefix: '%env(STORAGE_AWS_PREFIX)%'

        s3_v2.artifact:
            adapter: 'asyncaws'
            options:
                client: 'packeton.s3.storage'
                bucket: '%env(STORAGE_AWS_BUCKET)%'
                prefix: '%env(STORAGE_AWS_ARTIFACT_PREFIX)%'

        gcloud.storage:
            adapter: 'gcloud'
            options:
                client: 'gcloud_client_service' 
                bucket: 'bucket_name'
                prefix: 'optional/path/prefix'

        gcloud.artifact:
            adapter: 'gcloud'
            options:
                client: 'gcloud_client_service' 
                bucket: 'bucket_name'
                prefix: 'optional/path/artifact'

parameters:
    env(STORAGE_AWS_BUCKET): 'packeton-bucket'
    env(STORAGE_AWS_PREFIX): 'packeton'
    env(STORAGE_AWS_ARGS): '[]'
    env(STORAGE_AWS_ARTIFACT_PREFIX): 'artifact'

services:
    packeton.s3.storage:
        class: AsyncAws\S3\S3Client
        arguments:
            $configuration: '%env(json:STORAGE_AWS_ARGS)%'
            $httpClient: '@Symfony\Contracts\HttpClient\HttpClientInterface'
            $logger: '@logger'
    
    gcloud_client_service:
        class: Google\Cloud\Storage\StorageClient
        arguments:
            - { keyFilePath: 'path/to/keyfile.json' }
```


```
STORAGE_SOURCE=s3_v2
STORAGE_SOURCE=gcloud
```
