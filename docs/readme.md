# Introduction

Packeton - Private PHP package repository for vendors.

**Documentation** [docs.packeton.org](https://docs.packeton.org)

## Main Features

- Compatible with Composer API v2, bases on Symfony 6.
- Support update webhook for GitHub, Gitea, Bitbucket and GitLab or custom format.
- Customers user and ACL groups and limit access by vendor and versions.
- Composer Proxies and Mirroring.
- Generic Packeton [webhooks](webhook.md)
- Allow to freeze updates for the new releases after expire a customers license.
- Mirroring for packages zip files and downloads it's from your host.
- Credentials and Authentication http-basic config or ssh keys.
- Support monolithic repositories, like `symfony/symfony`
- Pull Request `composer.lock` change review.
- OAuth2 GitHub, Bitbucket, GitLab/Gitea and Other Integrations.
- Security Monitoring.
- Milty sub repositories.

## Compare Private Packagist with Packeton

| *Feature*                 | *Packeton*                                                                  | *Packagist.com*                                                          |
|---------------------------|-----------------------------------------------------------------------------|--------------------------------------------------------------------------|
| Composer API              | v1, v2                                                                      | v1, v2                                                                   |
| REST API                  | Partial covered. Only main CRUD feature                                     | Full covered. +PHP SDK private-packagist-api-client                      |
| Custom User/Vendors       | Limit access by versions, packages, release date. Customer users and groups | Limit access by versions, packages, stability. Users and Vendors bundles |
| Statistics                | Default by versions, packages                                               | By versions, packages and customer usage                                 |
| Integrations              | GitHub, GitLab, Gitea, Bitbucket                                            | GitHub, GitLab, Bitbucket (cloud/ server), AWS CodeCommit, Azure DevOps  |
| Synchronization           | Only Repositories                                                           | Teams, Permissions and Repositories                                      |
| Pull request review       | GitHub, GitLab, Gitea, Bitbucket                                            | Integrations - GitHub, GitLab, Bitbucket (cloud/ server)                 |
| Fine-grained API Token    | Support                                                                     | -                                                                        |
| Mirroring                 | Full Support. Separate URL path to access the repo                          | Full Support. Automatically setup                                        |
| Patch Mirroring metadata  | Support. UI metadata manager                                                | -                                                                        |
| Incoming webhooks         | Support. Full compatibility with packagist.org and its integrations         | Support. Used unique uuid address                                        |
| Outgoing webhooks         | Full Support. Custom UI request builder with expressions                    | Support. Request payload format is not configurable                      |
| Subrepositories           | Support                                                                     | Support                                                                  |
| LDAP                      | Support. On config level                                                    | Support                                                                  |
| Dependency License Review | -                                                                           | Support                                                                  |
| Security Monitoring       | Support. Webhook notifications                                              | Support. Webhook/email notifications                                     |
| Patch requires/metadata   | Support. UI metadata manager                                                | -                                                                        |
| Repos type                | VCS (auto), Mono-repo, Custom JSON, Artifacts                               | VCS, Githib/GitLab/Bitbucket, Custom JSON, Artifacts, Import Satis       |
| Mono-repo support         | Support                                                                     | Support                                                                  |
| Import                    | VCS Integration / Satis / Packagist.com  / List repos                       | Satis                                                                    |
 | Pricing                   | Open Source. Free                                                           | €5900 or €49/user/month                                                  |
