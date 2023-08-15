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
