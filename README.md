# catalogue-sync

[![check](https://github.com/typllukas/catalogue-sync/actions/workflows/check.yml/badge.svg)](https://github.com/typllukas/catalogue-sync/actions/workflows/check.yml)

Sync of an e-shop catalogue with a million products into Elasticsearch, and a product search on top
of it. Learning project for Elasticsearch and the transactional outbox, not a product. Plain Symfony
7.4 on PHP 8.5, MariaDB is the source of truth, Elasticsearch 9.1 is the only read path for search,
PHPStan level 10.

A product change never goes to the index directly. It is saved together with an outbox row in one
transaction, and a drain sends the changed products to the index in batches, whole or only the
changed fields. A full rebuild fills a new index and switches the alias without stopping writes.

The search is Czech full text with facets, typos allowed, and SKU or EAN in the same box. The
catalogue and the supplier file are generated. The demo page shows both: a supplier file fills the
queue, batches move it to the index, and the search below reads from it.

![Dashboard during a supplier file: 4 336 products in the queue, the last batch wrote 143 and
skipped 856 as unchanged, the index is catching up, product search with category and brand facets
below](docs/screenshot.png)

## Why a transactional outbox

A product change has to get into the index. What else was possible, and why not used:

- **full reindex only, nightly or hourly.** 70 s for a million products, and the app has it. But each
  run rewrites the whole catalogue to change a few thousand products, and a product sold out after
  a run is shown as in stock until the next one
- **Doctrine listener writing to the index**, as FOSElasticaBundle does. A dual write: when
  Elasticsearch fails next to the commit, the change is lost, and a slow Elasticsearch slows every
  save. Bulk imports through SQL, like the supplier file here, also never fire the listener
- **poll `updated_at`**, with an overlap window. Works, but cannot say what changed, so every
  document goes whole, and deletes need tombstones anyway
- **Symfony Messenger with the Doctrine transport.** The realistic one: dispatched inside the
  product's transaction it is atomic, an outbox table too. But its deduplication keeps the first
  message and drops the later ones, so price and then stock loses the stock, unless every message
  means the whole document. RabbitMQ or Kafka would go behind the outbox, fed by a relay, once more
  systems than one index need the changes

The outbox row commits with the product, needs nothing but MariaDB, and keeps one row per product
with all changes merged. The drain reads the current product when it sends it, so the order of rows
does not matter, and it sends with `update`, which skips a document that did not change.

Planned schedule, no crontab is installed: drain while the outbox has rows, supplier file daily,
rebuild after a mapping change, full drift check weekly.

## Search, and why Elasticsearch

`LIKE` and `MATCH AGAINST` do no stemming and no typos, and facet counts would be one SQL query per
facet. Here it is one request:

- **Czech full text** over name and description, name counts three times more. Diacritics are
  folded and words stemmed, so `vrtacky` finds `vrtačka`. One typo from three letters, two above
  five
- **Prefix while typing.** From two letters of the last word, `makita srou` already finds
  `Makita … šroubovák`
- **SKU and EAN in the same box.** Found exactly, also next to other words. Part of a SKU matches as
  a prefix
- **Facets** on category, brand and availability. With `post_filter`, each facet is counted without
  its own selection, so a selected value can be clicked off again. The category facet shows one
  level below the selected category
- **The rest:** highlight of what matched, a price filter, sort by relevance or price, paging up to
  row 10 000

## What it is not

- Not an importer. Nothing downloads or parses XML or CSV, one command writes the rows a supplier
  file would write
- Not tuned for relevance. Name weighs more than description and that is all, no score explanation,
  no suggester
- Not a cluster setup. One Elasticsearch node, security off, 1 GB heap. Replicas are a setting
  (`ELASTICSEARCH_REPLICAS`), nothing else about running a cluster is here
- Not a front end. The dashboard is one static HTML file without a build step, it only makes the
  pipeline visible
- Not a product. No login, no admin, one shop

## Endpoints

```
GET   /api/products             search, facets, offset paging
PATCH /api/products/{id}        change price and stock, marks the product, never writes the index
GET   /api/sync/status          queue depth, the last drain's counters, drift in a sample
POST  /api/sync/drain           one drain batch, what the dashboard runs while the queue is not empty
POST  /api/dev/supplier-feed    a 20 000 line supplier file, the dashboard button
```

## Run it

Needs only Docker with the Compose plugin, nothing is installed on the host. Make is optional.

```bash
git clone https://github.com/typllukas/catalogue-sync.git
cd catalogue-sync
make setup   # containers, dependencies, schema, 10 000 products, the index
```

- dashboard: `http://127.0.0.1:8083`
- Kibana Dev Tools: `http://127.0.0.1:5603`

`make help` lists the other targets: the checks, a bigger seed, a supplier file, a reindex.

With a million products instead of 10 000, this drops the data and seeds again:

```bash
PRODUCTS=1000000 make reset
```

Without Make, the same setup on a fresh clone:

```bash
docker compose up -d --wait
docker compose exec --user "$(id -u):$(id -g)" php composer install --no-interaction
docker compose exec --user "$(id -u):$(id -g)" php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec --user "$(id -u):$(id -g)" -e APP_DEBUG=0 php php bin/console catalogue-sync:dev:generate-data --products=10000
docker compose exec --user "$(id -u):$(id -g)" -e APP_DEBUG=0 php php bin/console catalogue-sync:index:reindex
```

### Try it

Press **Simulovat dodavatelský soubor** and watch the queue fill, the batches empty it and the prices
in the list change. With the default 10 000 products, 20 000 lines become about 6 400 queued
products, a batch of 1 000 writes around 145 and skips around 850, and the queue is empty in about
three seconds.
