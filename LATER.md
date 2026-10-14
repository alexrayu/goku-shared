# Later

## Shared hosting

- Removal of old `var/cache/prod-<release>` folders after an upgrade
- PHP 8.2/8.3 support (Symfony 7.4 allows it; dev tools and the container pin 8.4)
- S3-compatible Flysystem adapter (optional offsite storage)
- CI on MySQL 8 and MariaDB
- License choice

## Reader

- Reading direction: `Chapter.direction` (ltr, rtl, vertical) is stored but hidden in admin, because the reader only scrolls vertically. Show the field again once the paged/RTL reader exists.
