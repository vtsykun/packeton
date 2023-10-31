# Pull Request composer lock review

Every time when you create a Pull Request with `composer.lock` changes, the Packeton add a comment with descriptions 
of changing dependencies. It detects the next of changes:

- Added a new dependency
- Remove dependency
- Downgrade dependency
- Upgrades dependency.
- Change dist or source url.

[![PR review](img/pr-review.png)](img/pr-review.png)

### Configure Pull Request Review.

In the first you need add oauth integration. It may also GitHub app bot for GitHub hosting, see [oauth2](oauth2.md).

You must enable `pull_request_review` on configuration level. Or later you may enable per repository individually in the case then you 
don't use the integration synchronization.

```yaml
packeton:
    integrations:
        github:
            pull_request_review: true
            githubapp:
                ...
```

Now the bot will add comments automatically if you use with integration synchronization. 
But for enable it manually only for one selected repository you need add webhook (`pull_request` score) with integration access token.

it may look like. you can found this on integration view page.

```
https://example.com/api/hooks/gitlab/6?token=whk_810d6b279b3f78b758e09fe01f12378d2bd809c4
```
