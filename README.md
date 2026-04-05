# StitchDB for Laravel

## Install

```bash
composer require stitchdb/laravel
```

## Configure

Add to `.env`:

```env
DB_CONNECTION=stitchdb
STITCHDB_URL=https://db.stitchdb.com
STITCHDB_API_KEY=sdb_sk_your_key_here
```

No other setup needed. Run `php artisan migrate` and use Laravel as usual.
