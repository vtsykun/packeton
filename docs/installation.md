Install and Run in Docker
------------------------

You can use [packeton/packeton](https://hub.docker.com/r/packeton/packeton) image

```
docker run -d --name packeton \
    --mount type=volume,src=packeton-data,dst=/data \
    -p 8080:80 \
    packeton/packeton:latest
```

etc.
