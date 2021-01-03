# v2-data-migration-script

Unoptimized [Laravel Zero](https://laravel-zero.com/) application to migrate data from [4sucres](https://github.com/sucresware/4sucres) v1 to the future v2.

**Breaking changes can happen at any time as long as v2 is not finalized.**

## Usage

This application assumes that v1 and v2 shares the same filesystem.

1. Copy `.env.example` to `.env`
2. Fill the environment file with your database credentials
3. Start the migration process: `php v2-data-migration-script migrate:data`

## Credits

* [Simon Rubuano](https://github.com/mgkprod)

## License

Copyright (c) 2021 Simon Rubuano (@mgkprod) and contributors

Licensed under the MIT license, see [LICENSE.md](LICENSE.md) for details.
