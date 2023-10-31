# API documentation

About API authorization methods see [here](../authentication.md#composer-api-authentication)

#### Submit package 

```
POST https://example.com/api/create-package?token=<api_token>
Content-Type: application/json

{
    "repository": {
        "url": "git@github.com:symfony/mime.git"
    }
}
```

#### Listing package names

```
GET https://example.com/packages/list.json?token=<api_token>

# Result
{
  "packageNames": [
    "[vendor]/[package]",
    ...
  ]
}
```

#### List packages by vendor

```

GET https://example.com/packages/list.json?vendor=[vendor]&token=<api_token>

{
  "packageNames": [
    "[vendor]/[package]",
    ...
  ]
}
```

#### List packages by type

```
GET https://example.com/packages/list.json?type=[type]&token=<api_token>

{
  "packageNames": [
    "[vendor]/[package]",
    ...
  ]
}
```


#### Get the package git changelog

Get git diff between two commits or tags. **WARNING** Working only if repository was cloned by git. 
If you want to use this feature for GitHub you need set composer config flag no-api see here

```
GET https://example.com/packages/{name}/changelog?token=<api_token>&from=3.1.14&to=3.1.15

{
  "result": [
    "BAP-18660: ElasticSearch 6",
    "BB-17293: Back-office >Wrong height"
  ],
  "error": null,
  "metadata": {
    "from": "3.1.14",
    "to": "3.1.15",
    "package": "okvpn/platform"
  }
}
```

#### Getting package data

This is the preferred way to access the data as it is always up-to-date, and dumped to static files so it is very efficient on our end.

You can also send If-Modified-Since headers to limit your bandwidth usage 
and cache the files on your end with the proper filemtime set according to our Last-Modified header.

There are a few gotchas though with using this method:
* It only provides you with the package metadata but not information about the maintainers, download stats or github info.
* It contains providers information which must be ignored but can appear confusing at first. This will disappear in the future though.

```
GET https://example.com/p/[vendor]/[package].json?token=<api_token>

{
  "packages": {
    "[vendor]/[package]": {
      "[version1]": {
        "name": "[vendor]/[package],
        "description": [description],
        // ...
      },
      "[version2]": {
        // ...
      }
      // ...
    }
  }
}
```

**Composer v2** 

```
GET https://example.com/p2/firebase/php-jwt.json


{
  "minified": "composer/2.0",
  "packages": {
    "[vendor]/[package]": [... list versions ]
  }
}
```

**Using the API**

The JSON API for packages gives you all the infos we have including downloads, 
dependents count, github info, etc. However, it is generated dynamically so for performance reason we cache the responses 
for twelve hours. As such if the static file endpoint described above is enough please use it instead.

```
GET https://example.com/packages/[vendor]/[package].json?token=<api_token>

{
  "package": {
    "name": "[vendor]/[package],
    "description": [description],
    "time": [time of the last release],
    "maintainers": [list of maintainers],
    "versions": [list of versions and their dependencies, the same data of composer.json]
    "type": [package type],
    "repository": [repository url],
    "downloads": {
      "total": [numbers of download],
      "monthly": [numbers of download per month],
      "daily": [numbers of download per day]
    },
    "favers": [number of favers]
  }
}
```
