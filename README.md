# TA Writer

A local tool to write technical analyses as JSON, through a REST API, and publish them in several formats.

The JSON document is the source of truth. Its structure is defined by an OpenAPI 3.1 file
(`config/schema/document.openapi.yaml`): every change to that file is taken into account by the validation and the API
without code changes. The tool contains no AI and does not analyse code: an analysis is written by hand, or injected
by a script through the API.

The full specification is in [`docs/ta-writer-json-ta-tool.md`](docs/ta-writer-json-ta-tool.md).

## Status

| Part | State |
|---|---|
| JSON storage in `data/analyses`, with full version history, diff and restore | done |
| REST API (documents, JSON Patch, versions, schema, validation report) | done |
| Business rules (unique references, dependency cycles, criteria coverage) | done |
| Publication: raw JSON (+ JSON Schema), Markdown | done |
| Publication: command `app:ta:publish` | done (no interactive questions yet, use `--option`) |
| Schema-driven web UI (form generated from the OpenAPI file, versions, restore, publication) | done |
| PDF (mPDF, mermaid rendered to PNG by mermaid-cli) | done |
| Confluence API (page tree, Personal Access Token) | done (live instance not verified, see below) |
| Confluence AGIR (Chrome driven by PHP, Jira tickets, pages, Blocks links) | done (live instance not verified, see below) |
| Jira API (tickets only: epics, technical stories and sub-tasks carry their own content; no page, no browser) | done (live instance not verified, see below) |

## Requirements

- Docker with the Compose plugin. Everything else (PHP 8.1, Composer, extensions) runs in the container.
- Port `8080` free on the host.

## Quick start

```sh
make install            # builds the image, installs the dependencies, creates the first author
make up                 # starts the stack in the background
```

`make install` runs `scripts/install.sh`: it asks for the first author (defaults: `git config user.name` and
`user.email`), or takes `AUTHOR_NAME`, `AUTHOR_EMAIL` and `AUTHOR_USERNAME` from the environment. The author is added
only while the list of authors is empty, so running it again never duplicates it.

The steps it runs are also available on their own:

```sh
make build              # builds the PHP 8.1 image
make composer-install   # installs the PHP dependencies into vendor/
```

The API and the (future) UI answer on <http://127.0.0.1:8080>. The server listens on `127.0.0.1` only: the API has no
authentication yet.

```sh
curl http://127.0.0.1:8080/api/documents
docker compose down     # stops the stack
```

## Make targets

| Command | Purpose |
|---|---|
| `make help` | List the targets |
| `make build` | Build the PHP image |
| `make composer-install` | Install the dependencies (`vendor/`, not committed) |
| `make up` | Start in the background (`docker compose up -d`) |
| `make start` | Start in the foreground, logs in the terminal |
| `make down` | Stop the stack |
| `make login-browser` | Open the browser used for the AGIR login, through noVNC |
| `make console c="about"` | Run any Symfony console command |
| `make publish d=20261123_ta-writer f=markdown` | Publish a stored analysis |
| `make test` | Run the PHPUnit suite |
| `make stan` | Run PHPStan (level 6) |
| `make sh` | Open a shell in the PHP container |

## Configuration

Read from `.env` (defaults, committed) and `.env.local` (your overrides, not committed). Paths are relative to the
project root.

| Variable | Default | Meaning |
|---|---|---|
| `TA_STORAGE_DIR` | `data/analyses` | Where the analyses are stored |
| `TA_EXPORT_DIR` | `data/exports` | Where the publications are written by default |
| `TA_HISTORY_MAX` | `0` | Versions kept per analysis, `0` = all |
| `TA_AUTOSAVE_SECONDS` | `5` | Seconds after the last change before the editor saves a new version, `0` = autosave off |
| `MMDC_BIN` | `mmdc` | mermaid-cli, used for the diagrams of the PDF and of the Confluence pages |
| `CHROME_BIN` | `/usr/bin/chromium` | Chrome driven for the AGIR publication |
| `AGIR_PROFILE_DIR` | `data/chrome-profile` | Browser profile that keeps the AGIR login between runs |
| `CONFLUENCE_BASE_URL` | `https://espace.agir.orange.com` | Confluence instance |
| `JIRA_BASE_URL` | `https://portail.agir.orange.com` | Jira instance |
| `CONFLUENCE_PAT` | empty | Confluence Personal Access Token, in `.env.local` only |
| `JIRA_PAT` | empty | Jira Personal Access Token, in `.env.local` only |
| `APP_SECRET` | placeholder | Symfony secret. Change it outside your local machine |

`data/` is gitignored: analyses and exports are local.

## Data layout

```
data/analyses/20261123/ta-writer.json            current content of the document
data/analyses/20261123/ta-writer.versions/       one snapshot per version + index.json
data/exports/20261123/TA-WRITER_v1.0.2.md        publications (json, md, pdf, schema.json)
```

A document id is `<YYYYMMDD>_<slug>`. The slug comes from the project reference (`TA-WRITER` gives `ta-writer`); a
collision gets a suffix (`ta-writer-2`).

Exports are named after the project reference (case kept) and the document version: `<REFERENCE>_v<version>.<ext>`,
so `TA-WRITER` at version `1.0.2` gives `TA-WRITER_v1.0.2.pdf`. The JSON export also writes `TA-WRITER_v1.0.2.schema.json`.
The `--option output=<path>` option still sets the path explicitly.

Versions are never rewritten. A restore creates a new version whose origin is `restore`.

**Rich text fields.** The long text fields (what, why, where, how, and the content of a note resource) are Quill
editors. Each one has three states: an untouched field keeps its stored value; an edited field is converted from the
editor's HTML to the format of the document; a field edited in its Source view is converted from its stored format.
Changing the format of the document converts every field on save, and the text format asks for a confirmation first.
Quill is served from `public/assets/vendor/quill` (BSD-3, not a CDN).

**Document versions.** Two fields are set by the server, never by a client:
- `structureVersion` is the `info.version` of the OpenAPI file (currently `1.1.0`). The file is read-only on disk
  (`chmod 444`), and the form shows it read-only. A document saved after a change of the structure takes the new
  version; that change alone does not create a new content version.
- `context` (epics, technical stories, sub-tasks) is an optional one-line summary of `what`, typed in a plain field above
  it. It is shown above the What in the exports and the tickets, and it fills the Context cell of the AGIR pages (the first
  sentence of `what` is used when it is empty).
- `version` is the version of the content: `1.0.0` at creation, then the patch number goes up (`1.0.1`, `1.0.2`…) at
  each edit that changes the content, whether it comes from the UI, the API or a restore. A save that changes nothing
  keeps the version. An adapter writing back its ticket keys and page links does not count as an edit.

## REST API

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/documents` | List the analyses |
| `POST` | `/api/documents` | Create an analysis (`201`, `Location`, `ETag`) |
| `GET` | `/api/documents/{id}` | Read an analysis (`ETag` = current version) |
| `PUT` | `/api/documents/{id}` | Replace an analysis. Requires `If-Match` |
| `PATCH` | `/api/documents/{id}` | RFC 6902 patch (`application/json-patch+json`). Requires `If-Match` |
| `DELETE` | `/api/documents/{id}` | Delete the current content; the history is kept |
| `POST` | `/api/documents/{id}/validate` | Schema and business report, nothing is saved |
| `GET` | `/api/documents/{id}/versions` | Version history |
| `GET` | `/api/documents/{id}/versions/{n}` | Content of one version |
| `GET` | `/api/documents/{id}/versions/{a}/diff/{b}` | Structural diff between two versions |
| `POST` | `/api/documents/{id}/versions/{n}/restore` | Restore a version as a new version |
| `GET` | `/api/schema` | The Document JSON Schema, references resolved |
| `GET` | `/api/openapi` | The OpenAPI file itself (YAML) |

**Concurrency.** Every write to an existing analysis must send the version it is based on:

```sh
curl -X PUT -H 'Content-Type: application/json' -H 'If-Match: "3"' --data-binary @analysis.json \
     http://127.0.0.1:8080/api/documents/20261123_ta-writer
```

If the version is no longer current, the server answers `409` and writes nothing.

**Errors** are `application/problem+json` (RFC 7807). A document that breaks the schema gets `422` with the path of
each error; a broken business rule gets `422` with the violations.

Example, creating an analysis:

```sh
curl -X POST -H 'Content-Type: application/json' --data-binary @docs/ta-writer.document.json \
     http://127.0.0.1:8080/api/documents
```

## Publishing

```sh
make publish d=20261123_ta-writer f=markdown
docker compose run --rm php php bin/console app:ta:publish 20261123_ta-writer --format=json --option withSchema=no
docker compose run --rm php php bin/console app:ta:publish 20261123_ta-writer --format=markdown --dry-run
```

| Option | Meaning |
|---|---|
| `--format` | `markdown` (default) or `json` |
| `--option name=value` | Answer to a format question. JSON: `output`, `withSchema` (`yes`/`no`). Markdown: `output` |
| `--dry-run` | Show the files that would be written, write nothing |

Every publication validates the document against the schema and the business rules first. An invalid analysis is
never exported.

The Markdown export has one section per epic, technical story and subtask, with the reference in every title, and a
**Dependencies** section at the end. Each dependency links to the `pageLink` stored in the JSON when there is one.

## Project layout

```
config/schema/document.openapi.yaml   the structure of a document (source of truth)
src/Api/                              REST controllers, JSON Patch, problem+json subscriber
src/Schema/                           OpenAPI loading, JSON Schema validation
src/Storage/                          JSON repository, atomic writes, versions
src/Validation/                       business rules
src/Reference/                        display references (E1, TS1, Subtask#1...)
src/Render/                           Markdown rendering
src/Publication/                      publishers, registry, service, console command
tests/                                PHPUnit tests (unit and kernel-level API tests)
scripts/ta_md_to_document.py          one-off converter: the TA Markdown to a Document JSON
```

## Adding a format or a rule

- **A publication format**: create a class implementing `App\Publication\PublisherInterface` under
  `src/Publication/Adapter/`. It is tagged `app.publisher` automatically and appears in `--format`.
- **A business rule**: create a class implementing `App\Validation\RuleInterface`. It is tagged
  `app.validation_rule` automatically and runs on every write and publication.
- **A field**: add it to `config/schema/document.openapi.yaml`. The validation and `/api/schema` take it into account
  straight away; the publishers render only the fields they know.

## Tests

```sh
make test
make stan
```

Tests run in the container with `var/test-data` as storage, so they never touch `data/`.

## Troubleshooting

- **`vendor/autoload.php` not found**: run `make composer-install`.
- **Port 8080 already in use**: stop the other process, or change the port in `docker-compose.yml`.
- **Permission errors on `data/` or `var/`**: the containers run with your user id (`USERID`/`GROUPID` in the Makefile).
  Remove the directory from a container running as root, then restart.

## Jira API adapter (`jira`)

Publishes the structure as Jira tickets only, through the REST API v2 with a Personal Access Token:

- an **epic** per epic, a **Technical Story** per technical story, a **Sub-task** per subtask; the summary is the
  reference-prefixed title (`TAW E1 TS1 Subtask#1 - Title`), and the description carries the content (context, what, why,
  where, how, criteria, resources, feature toggles, SQL impact);
- **user stories have no ticket**: they and their acceptance criteria are listed in the description of their epic;
- diagrams are rendered to PNG, attached to the ticket and shown in the description (a re-run replaces the attachment);
- the Blocks links between technical stories are created, when they do not exist yet;
- a re-run **rewrites** the summary and the description of the tickets it knows (manual edits made in Jira on these two
  fields are overwritten), and creates nothing twice;
- only the keys are written back into the JSON, in one version at the end of the run.

Set `JIRA_PAT` in `.env.local`. The options (projects, fix version, issue types, the Epic Link field and the optional
"Epic Name" field) are asked by the publication page, or given with `--option`. Run a dry run first:
`php bin/console app:ta:publish <id> --format=jira --dry-run`.

## Authors

The list of authors is kept in `data/authors.json` (`TA_AUTHORS_FILE`, relative to the project). The first author is the
default of a new analysis. An author is identified by its email, which is unique in the list.

- **Page**: *Authors* in the top bar. Add, change and remove authors there.
- **Commands**: `make console c="app:author:add \"Name\" name@example.com --username=name"`, `app:author:list`,
  `app:author:remove <email>`.
- **In an analysis**, the *Author* section is a dropdown of the list. The analysis keeps a copy of the author it chose
  (`author: {name, username, email}`): changing or removing an author in the list does not change the analyses that already
  chose it. An author no longer in the list stays selected, marked "(not in the list)".

