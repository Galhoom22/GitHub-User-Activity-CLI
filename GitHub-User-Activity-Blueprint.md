# GitHub User Activity CLI — PHP 8.5 Blueprint

> A small, readable, well-designed CLI that fetches a GitHub user's recent activity
> from `https://api.github.com/users/<username>/events` and prints a human-readable
> summary. Built with modern **PHP 8.5** OOP, applying **SOLID** without over-engineering.

## Table of Contents

1. [Goals & Non-Goals](#1-goals--non-goals)
2. [Project Layout](#2-project-layout)
3. [Class & Responsibility Map](#3-class--responsibility-map)
4. [SOLID, Applied Without Ceremony](#4-solid-applied-without-ceremony)
5. [PHP 8.5 Features Used (and Why)](#5-php-85-features-used-and-why)
6. [Key Sketches](#6-key-sketches-illustrative-not-final)
7. [Error Handling Matrix](#7-error-handling-matrix)
8. [Feature Tracker (Epic / Stories / Tasks)](#8-feature-tracker)
9. [Build Order](#9-build-order-small-verifiable-steps)
10. [Definition of Done](#10-definition-of-done)
11. [Pre-flight Checklist](#11-pre-flight-checklist)

---

## 1. Goals & Non-Goals

### Goals
- Single command: `php bin/github-activity <username>`.
- Pure standard library (no Composer dependencies, per the brief).
- Clean OOP with SOLID principles, but the code stays small and obvious.
- Modern PHP 8.5 syntax: readonly classes, constructor promotion, enums,
  asymmetric visibility, first-class callables, `#[\NoDiscard]`, and the new pipe `|>`.
- Graceful errors (404, rate limit, network failure, malformed JSON).

### Non-Goals
- No frameworks, no DI containers, no Composer.
- No persistence layer beyond an optional file cache (stretch goal).
- No web UI — CLI only.

---

## 2. Project Layout

```
GitHub User Activity/
├─ bin/
│  └─ github-activity              # executable entry script (php shebang)
├─ src/
│  ├─ Application.php              # orchestrator (wires pieces together)
│  ├─ Cli/
│  │  ├─ ArgumentParser.php        # parses argv → Username VO
│  │  └─ ConsoleWriter.php         # thin stdout/stderr wrapper
│  ├─ Domain/
│  │  ├─ Username.php              # readonly value object (validates format)
│  │  ├─ Event.php                 # readonly DTO
│  │  └─ EventType.php             # backed enum (PushEvent, IssuesEvent, …)
│  ├─ Github/
│  │  ├─ GitHubApiClient.php       # talks to api.github.com/users/{u}/events
│  │  └─ EventHydrator.php         # raw array → Event[]
│  ├─ Http/
│  │  ├─ HttpClientInterface.php   # tiny contract: get(string $url): HttpResponse
│  │  ├─ HttpResponse.php          # readonly { int status, string body }
│  │  └─ CurlHttpClient.php        # default impl using ext-curl
│  ├─ Formatting/
│  │  ├─ EventFormatterInterface.php
│  │  ├─ EventFormatter.php        # match() over EventType → human line
│  │  └─ ActivityRenderer.php      # Event[] → printable lines
│  └─ Exception/
│     ├─ GitHubActivityException.php   # base
│     ├─ UserNotFoundException.php
│     ├─ RateLimitExceededException.php
│     └─ NetworkException.php
├─ tests/                          # optional, plain PHP assertions
└─ GitHub-User-Activity-Blueprint.md
```

Why this shape: each top-level folder maps to one **reason to change**
(CLI parsing, domain model, transport, GitHub specifics, presentation, errors).
That is SRP at the *package* level — and it keeps every individual class tiny.

---

## 3. Class & Responsibility Map

| Class | Responsibility | SOLID role |
|---|---|---|
| `Application` | Wire dependencies, run one request | Composition root |
| `ArgumentParser` | Validate `argv`, return `Username` | SRP |
| `ConsoleWriter` | Write to STDOUT / STDERR with exit codes | SRP |
| `Username` | Enforce GitHub username rules (`^[A-Za-z0-9-]{1,39}$`) | Value Object |
| `Event` | Immutable record of one GitHub event | DTO |
| `EventType` | Closed set of supported event names | Enum (LSP-safe) |
| `HttpClientInterface` | `get(string $url): HttpResponse` | DIP, ISP |
| `CurlHttpClient` | cURL implementation with timeout + UA header | LSP |
| `GitHubApiClient` | Build URL, set `Accept` header, map HTTP status → exception | SRP |
| `EventHydrator` | `array $json → Event[]` | SRP |
| `EventFormatterInterface` | `format(Event): string` | OCP, DIP |
| `EventFormatter` | One `match(EventType)` arm per type | OCP via enum exhaustiveness |
| `ActivityRenderer` | Render `Event[]` as bullet list | SRP |
| `*Exception` | Typed failures the CLI can report cleanly | SRP |

---

## 4. SOLID, Applied Without Ceremony

- **S — Single Responsibility.** Splitting *transport* (`CurlHttpClient`),
  *protocol* (`GitHubApiClient`), *shape* (`EventHydrator`), and *presentation*
  (`EventFormatter`) means a change in any one layer touches one file.
- **O — Open/Closed.** New event types are added by extending the
  `EventType` enum and adding one `match` arm in `EventFormatter`. The
  enum makes the match exhaustive — PHP will surface missing cases.
- **L — Liskov Substitution.** `HttpClientInterface` has one method with a
  precise contract. A fake `InMemoryHttpClient` for tests is a drop-in.
- **I — Interface Segregation.** Only two interfaces, each with one method
  (`HttpClientInterface::get`, `EventFormatterInterface::format`). No fat contracts.
- **D — Dependency Inversion.** `GitHubApiClient` depends on
  `HttpClientInterface`, not on cURL. `Application` is the only place that
  knows the concrete `CurlHttpClient`.

> Rule of thumb used throughout: **interface only when there is, or will be,
> a second implementation** (real cURL vs. test fake). No interfaces "just in case."

---

## 5. PHP 8.5 Features Used (and Why)

| Feature | Where | Why |
|---|---|---|
| `final readonly class` | `Username`, `Event`, `HttpResponse` | Value objects, immutable by design |
| Constructor property promotion | All services and DTOs | Less boilerplate, one source of truth |
| `final` on promoted properties (8.5) | `Username::$value` | Lock the contract at the property level |
| Asymmetric visibility (`public private(set)`) | `Application::$lastStatus` if needed | Read-only outside, mutable inside |
| Backed enum + `match()` | `EventType` ↔ `EventFormatter` | Exhaustive presentation logic |
| First-class callable syntax `strtolower(...)` | Pipe stages | Cleaner than closures |
| Pipe operator `\|>` | `ArgumentParser`: `$raw \|> trim(...) \|> strtolower(...)` | Linear, top-to-bottom data flow |
| `#[\NoDiscard]` | `GitHubApiClient::fetchEvents()`, `EventHydrator::hydrate()` | Compiler nags if a caller forgets the result |
| `#[\Override]` | Concrete impls of interface methods | Catches signature drift early |
| Named arguments | Constructing `Event` and `HttpResponse` | Self-documenting call sites |
| Typed throws via custom exception hierarchy | All failure paths | Callers catch one base type |

---

## 6. Key Sketches (illustrative, not final)

### 6.1 `Username` value object
```php
final readonly class Username
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,39}$/', $value)) {
            throw new \InvalidArgumentException("Invalid GitHub username: {$value}");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```
> Note: because the class is `final readonly`, the property is already
> immutable and unoverridable — adding `final` to the promoted property
> would be redundant. The `final` property modifier (PHP 8.5) is most useful
> on **non-final** classes that still want to lock a single property.

### 6.2 `EventType` enum
```php
enum EventType: string
{
    case Push         = 'PushEvent';
    case PullRequest  = 'PullRequestEvent';
    case Issues       = 'IssuesEvent';
    case IssueComment = 'IssueCommentEvent';
    case Watch        = 'WatchEvent';      // "starred"
    case Fork         = 'ForkEvent';
    case Create       = 'CreateEvent';
    case Delete       = 'DeleteEvent';
    case Public_      = 'PublicEvent';
    case Release      = 'ReleaseEvent';
    case Other        = '__other__';

    public static function fromApi(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Other;
    }
}
```

### 6.3 `Event` DTO
```php
final readonly class Event
{
    public function __construct(
        public EventType $type,
        public string    $repo,
        public array     $payload,   // raw payload subset, kept generic on purpose
    ) {}
}
```

### 6.4 `HttpClientInterface` + cURL impl
```php
interface HttpClientInterface
{
    #[\NoDiscard]
    public function get(string $url, array $headers = []): HttpResponse;
}

final class CurlHttpClient implements HttpClientInterface
{
    #[\Override]
    public function get(string $url, array $headers = []): HttpResponse
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'github-activity-cli/1.0',
            CURLOPT_HTTPHEADER     => array_merge(
                ['Accept: application/vnd.github+json'],
                $headers,
            ),
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new NetworkException($msg);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new HttpResponse(status: $status, body: (string) $body);
    }
}
```

### 6.5 `GitHubApiClient` — protocol layer
```php
final readonly class GitHubApiClient
{
    public function __construct(private HttpClientInterface $http) {}

    #[\NoDiscard]
    public function fetchEvents(Username $user): array
    {
        $resp = $this->http->get("https://api.github.com/users/{$user->value}/events");

        return match (true) {
            $resp->status === 404 => throw new UserNotFoundException($user->value),
            $resp->status === 403 => throw new RateLimitExceededException(),
            $resp->status >= 400  => throw new GitHubActivityException(
                "GitHub returned HTTP {$resp->status}"
            ),
            default => json_decode($resp->body, true, flags: JSON_THROW_ON_ERROR),
        };
    }
}
```

### 6.6 `EventFormatter` — presentation via exhaustive match
```php
final class EventFormatter implements EventFormatterInterface
{
    #[\Override]
    public function format(Event $e): string
    {
        return match ($e->type) {
            EventType::Push         => sprintf(
                'Pushed %d commit(s) to %s',
                count($e->payload['commits'] ?? []),
                $e->repo,
            ),
            EventType::Issues       => sprintf(
                '%s an issue in %s',
                ucfirst($e->payload['action'] ?? 'updated'),
                $e->repo,
            ),
            EventType::IssueComment => "Commented on an issue in {$e->repo}",
            EventType::PullRequest  => sprintf(
                '%s a pull request in %s',
                ucfirst($e->payload['action'] ?? 'updated'),
                $e->repo,
            ),
            EventType::Watch        => "Starred {$e->repo}",
            EventType::Fork         => "Forked {$e->repo}",
            EventType::Create       => "Created {$e->payload['ref_type']} in {$e->repo}",
            EventType::Delete       => "Deleted {$e->payload['ref_type']} in {$e->repo}",
            EventType::Public_      => "Made {$e->repo} public",
            EventType::Release      => "Published a release in {$e->repo}",
            EventType::Other        => "Activity in {$e->repo}",
        };
    }
}
```

### 6.7 `Application` — composition root
```php
final readonly class Application
{
    public function __construct(
        private GitHubApiClient        $api,
        private EventHydrator          $hydrator,
        private ActivityRenderer       $renderer,
        private ConsoleWriter          $out,
    ) {}

    public function run(array $argv): int
    {
        try {
            $username = (new ArgumentParser())->parse($argv);
            $events   = $this->hydrator->hydrate($this->api->fetchEvents($username));
            $this->out->writeLines($this->renderer->render($events));
            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->out->error($e->getMessage());
            $this->out->error('Usage: github-activity <username>');
            return 64;
        } catch (UserNotFoundException $e) {
            $this->out->error("User '{$e->getMessage()}' not found.");
            return 2;
        } catch (RateLimitExceededException) {
            $this->out->error('GitHub API rate limit exceeded. Try again later.');
            return 3;
        } catch (NetworkException $e) {
            $this->out->error("Network error: {$e->getMessage()}");
            return 4;
        } catch (\Throwable $e) {
            $this->out->error("Unexpected error: {$e->getMessage()}");
            return 1;
        }
    }
}
```

### 6.8 Entry script `bin/github-activity`
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../src/autoload.php'; // tiny PSR-4-ish autoloader, no Composer

use App\Application;
use App\Cli\ConsoleWriter;
use App\Formatting\ActivityRenderer;
use App\Formatting\EventFormatter;
use App\Github\EventHydrator;
use App\Github\GitHubApiClient;
use App\Http\CurlHttpClient;

exit((new Application(
    api:      new GitHubApiClient(new CurlHttpClient()),
    hydrator: new EventHydrator(),
    renderer: new ActivityRenderer(new EventFormatter()),
    out:      new ConsoleWriter(),
))->run($argv));
```

A 12-line `src/autoload.php` (`spl_autoload_register` mapping `App\\` → `src/`)
keeps us free of Composer while still using namespaces.

---

## 7. Error Handling Matrix

| Failure | Source | Exception | Exit code | User-facing message |
|---|---|---|---|---|
| Missing username arg | `ArgumentParser` | `\InvalidArgumentException` | 64 | `Usage: github-activity <username>` |
| Invalid username format | `Username` ctor | `\InvalidArgumentException` | 64 | `Invalid GitHub username` |
| HTTP 404 | `GitHubApiClient` | `UserNotFoundException` | 2 | `User '<u>' not found.` |
| HTTP 403 (rate limit) | `GitHubApiClient` | `RateLimitExceededException` | 3 | `Rate limit exceeded.` |
| Network / cURL error | `CurlHttpClient` | `NetworkException` | 4 | `Network error: …` |
| Bad JSON | `GitHubApiClient` (`JSON_THROW_ON_ERROR`) | `\JsonException` | 1 | `Unexpected error: …` |
| Unknown 4xx/5xx | `GitHubApiClient` | `GitHubActivityException` | 1 | passthrough |

Exit codes follow the `<sysexits.h>` spirit: `0` success, `64` usage error,
specific non-zero codes for known domain failures, `1` for unknown.

---

## 8. Feature Tracker

### 🧩 Epic Overview

**Goal: Why (Business / User Value):**
Build a small, well-designed PHP 8.5 CLI that prints a GitHub user's recent
activity from `api.github.com/users/<username>/events` in a clean, human-readable
list. The project exists to practice **modern PHP OOP, OOD, and SOLID** on a
narrow, real-world problem — without frameworks, without Composer dependencies,
and without over-engineering. End users (developers) get a quick way to peek at
any GitHub account's recent public activity straight from the terminal.

---

## 🚀 Features

### 📦 Feature 1: CLI Entry & Argument Parsing

**Description:**
Provide an executable CLI script (`bin/github-activity`) that accepts a GitHub
username as its only argument, validates it, and refuses to run with a clear
usage message if input is missing or malformed. This is the user's first contact
point — it must feel obvious and forgiving.

### 📖 User Story 1: Run the tool with a username

**As a** developer, **I want to** run `php bin/github-activity <username>` and
get the user's recent activity, **so that** I can inspect any GitHub account
without leaving the terminal.

**Acceptance Criteria:**

- [ ] Running `php bin/github-activity kamranahmedse` exits with code `0`.
- [ ] The script is launched via a single entry point under `bin/`.
- [ ] The username is read from `argv[1]` only — no global state.

**Tasks:**

- [ ] Create `bin/github-activity` with the `#!/usr/bin/env php` shebang.
- [ ] Build a tiny PSR-4-style autoloader at `src/autoload.php` (no Composer).
- [ ] Wire `Application::run($argv)` from the entry script.

---

### 📖 User Story 2: Get a friendly usage message

**As a** new user, **I want to** see a short usage line when I forget the
argument, **so that** I'm not confronted with a stack trace.

**Acceptance Criteria:**

- [ ] Running with no argument prints `Usage: github-activity <username>` to STDERR.
- [ ] Exit code is `64` (usage error) when the argument is missing.
- [ ] An invalid username (`^[A-Za-z0-9-]{1,39}$` violation) also exits `64`
      with a clear message.

**Tasks:**

- [ ] Implement `Cli\ArgumentParser::parse(array $argv): Username`.
- [ ] Implement `Domain\Username` readonly VO with regex validation in the ctor.
- [ ] Catch `\InvalidArgumentException` in `Application::run()` and print usage.

---

### 📦 Feature 2: HTTP Transport Layer

**Description:**
A thin, swappable HTTP client used to talk to GitHub. The interface is
deliberately minimal so we can swap in a fake for tests or a cache decorator
later. This is where **DIP** earns its keep.

### 📖 User Story 1: Fetch a URL over HTTPS

**As a** developer, **I want** the application to fetch a URL and return the
status + body, **so that** higher layers don't know or care about cURL.

**Acceptance Criteria:**

- [ ] `HttpClientInterface::get(string $url, array $headers = []): HttpResponse`.
- [ ] `CurlHttpClient` sets a 10-second timeout and a `User-Agent` header.
- [ ] `Accept: application/vnd.github+json` is sent by default.
- [ ] cURL transport failures throw `NetworkException`, never bubble raw errors.

**Tasks:**

- [ ] Create `Http\HttpResponse` (`final readonly class` with `int $status`, `string $body`).
- [ ] Define `Http\HttpClientInterface` with `#[\NoDiscard]` on `get()`.
- [ ] Implement `Http\CurlHttpClient` with `#[\Override]` on `get()`.
- [ ] Map `curl_exec === false` to `NetworkException`.

---

### 📦 Feature 3: GitHub API Integration

**Description:**
Translate domain calls into HTTP requests against the GitHub Events API and
turn HTTP status codes into typed exceptions the rest of the app can handle.

### 📖 User Story 1: Fetch events for a user

**As a** developer, **I want** `GitHubApiClient::fetchEvents(Username)` to
return a decoded array of events, **so that** higher layers receive plain data,
not raw JSON.

**Acceptance Criteria:**

- [ ] URL `https://api.github.com/users/{username}/events` is built from `Username`.
- [ ] JSON is decoded with `JSON_THROW_ON_ERROR`.
- [ ] HTTP 200 returns the decoded array.
- [ ] HTTP 404 throws `UserNotFoundException` carrying the username.
- [ ] HTTP 403 throws `RateLimitExceededException`.
- [ ] Any other ≥ 400 throws `GitHubActivityException` with the status code.

**Tasks:**

- [ ] Create `Github\GitHubApiClient` with promoted `private HttpClientInterface $http`.
- [ ] Implement `fetchEvents()` with `#[\NoDiscard]` and a status-code `match`.
- [ ] Add `Exception\GitHubActivityException` (base) and the three subclasses.

---

### 📖 User Story 2: Convert raw JSON into domain objects

**As a** developer, **I want** raw event arrays converted into `Event` DTOs,
**so that** presentation code never touches loose array keys.

**Acceptance Criteria:**

- [ ] `Github\EventHydrator::hydrate(array $json): Event[]` returns one `Event` per item.
- [ ] Unknown event names map to `EventType::Other` (no exception thrown).
- [ ] Each `Event` carries `EventType`, repo name, and the original `payload`.

**Tasks:**

- [ ] Create `Domain\EventType` backed enum + `fromApi(string)` fallback method.
- [ ] Create `Domain\Event` (`final readonly class`) with named-arg ctor.
- [ ] Implement `Github\EventHydrator::hydrate()` with `#[\NoDiscard]`.

---

### 📦 Feature 4: Activity Presentation

**Description:**
Turn `Event[]` into readable bullet lines and write them to the terminal.
Adding a new GitHub event type must touch exactly two files.

### 📖 User Story 1: See a clean bullet list of recent activity

**As a** developer, **I want** each event printed as a single line starting
with `- `, **so that** the output is scannable at a glance.

**Acceptance Criteria:**

- [ ] Output matches the brief's example shape (`- Pushed 3 commits to repo`).
- [ ] Every `EventType` variant has a dedicated formatting branch.
- [ ] A run with zero events prints `No recent public activity.` (not empty silence).

**Tasks:**

- [ ] Define `Formatting\EventFormatterInterface::format(Event): string`.
- [ ] Implement `Formatting\EventFormatter` with an exhaustive `match` over `EventType`.
- [ ] Implement `Formatting\ActivityRenderer::render(array $events): array<string>`.
- [ ] Implement `Cli\ConsoleWriter::writeLines()` and `error()` (STDOUT vs STDERR).

---

### 📖 User Story 2: Cover every common GitHub event

**As a** developer, **I want** Push, Issues, IssueComment, PullRequest, Watch,
Fork, Create, Delete, Public, and Release events all formatted, **so that**
real accounts produce useful output, not "Activity in repo" placeholders.

**Acceptance Criteria:**

- [ ] Each event type below produces a distinct, grammatical line.
- [ ] Unknown / future event types fall back to `Activity in {repo}`.

**Tasks:**

- [ ] PushEvent — `Pushed N commit(s) to {repo}`
- [ ] IssuesEvent — `{Action} an issue in {repo}`
- [ ] IssueCommentEvent — `Commented on an issue in {repo}`
- [ ] PullRequestEvent — `{Action} a pull request in {repo}`
- [ ] WatchEvent — `Starred {repo}`
- [ ] ForkEvent — `Forked {repo}`
- [ ] CreateEvent / DeleteEvent — `Created/Deleted {ref_type} in {repo}`
- [ ] PublicEvent — `Made {repo} public`
- [ ] ReleaseEvent — `Published a release in {repo}`
- [ ] Fallback — `Activity in {repo}`

---

### 📦 Feature 5: Error Handling & Exit Codes

**Description:**
Every failure mode the user can hit should produce a one-line message and a
distinct exit code. No raw stack traces leak to the terminal.

### 📖 User Story 1: Handle unknown users and rate limits gracefully

**As a** user, **I want** clear messages when GitHub says "no such user" or
"slow down", **so that** I know what to do next.

**Acceptance Criteria:**

- [ ] `UserNotFoundException` → `User '<u>' not found.` + exit code `2`.
- [ ] `RateLimitExceededException` → `GitHub API rate limit exceeded. Try again later.` + exit `3`.
- [ ] `NetworkException` → `Network error: …` + exit `4`.
- [ ] `\Throwable` catch-all → `Unexpected error: …` + exit `1`.
- [ ] Successful run → exit `0`.

**Tasks:**

- [ ] Build the exception hierarchy under `src/Exception/`.
- [ ] Map status codes inside `GitHubApiClient::fetchEvents()`.
- [ ] Add the `try/catch` ladder in `Application::run()`.
- [ ] Document the exit-code table in the README.

---

### 📦 Feature 6: Modern PHP 8.5 + SOLID Polish

**Description:**
The whole point of this project is to *show* modern, idiomatic PHP 8.5. This
feature is a checklist that rides along with the others rather than a separate
deliverable.

### 📖 User Story 1: Reviewers can spot PHP 8.5 idioms at a glance

**As a** code reviewer, **I want to** open any file and immediately see modern
PHP, **so that** I know the author understands the current language level.

**Acceptance Criteria:**

- [ ] No class exceeds ~60 lines; no method exceeds ~20.
- [ ] Adding a new event type touches exactly 2 files.
- [ ] No interface exists without ≥ 2 actual implementations or a clear test fake.

**Tasks:**

- [ ] `final readonly class` on every VO / DTO that allows it.
- [ ] Constructor property promotion in every service and DTO.
- [ ] `final` on promoted properties (PHP 8.5).
- [ ] `#[\NoDiscard]` on `fetchEvents()`, `hydrate()`, and `HttpClientInterface::get()`.
- [ ] `#[\Override]` on every interface implementation.
- [ ] Named arguments at every multi-arg call site.
- [ ] Pipe operator `|>` in `ArgumentParser` normalization (`trim |> strtolower`).
- [ ] First-class callable syntax `fn(...)` wherever it improves readability.

---

### 📦 Feature 7 (Stretch): UX & Performance Extras

**Description:**
Optional features that make the tool nicer to use day-to-day. Ship only after
Features 1–6 are green.

### 📖 User Story 1: Filter and limit output

**As a** power user, **I want** `--type=<EventType>` and `--limit=<N>` flags,
**so that** I can focus on, say, the last 5 PushEvents only.

**Acceptance Criteria:**

- [ ] `--help` / `-h` prints usage and exits `0`.
- [ ] `--type=push` filters to PushEvents only (case-insensitive).
- [ ] `--limit=5` truncates the output list to 5 entries.
- [ ] Unknown flag → exit `64` with the usage message.

**Tasks:**

- [ ] Extend `ArgumentParser` to accept long options.
- [ ] Pass filter + limit through `Application` to `ActivityRenderer`.
- [ ] Add `--help` text and usage block.

---

### 📖 User Story 2: Cache responses to be a good API citizen

**As a** frequent user, **I want** repeat calls to use a local cache with
`ETag`, **so that** I don't burn through the unauthenticated rate limit.

**Acceptance Criteria:**

- [ ] First call writes JSON + `ETag` to `~/.cache/github-activity/<username>.json`.
- [ ] Subsequent calls send `If-None-Match` and reuse the cached body on `304`.
- [ ] `--no-cache` bypasses the cache entirely.
- [ ] Optional `GITHUB_TOKEN` env var adds an `Authorization: Bearer …` header.

**Tasks:**

- [ ] Implement `Http\CachingHttpClient` decorator implementing `HttpClientInterface`.
- [ ] Add `--no-cache` flag handling in `ArgumentParser`.
- [ ] Read `GITHUB_TOKEN` in the entry script and inject as a default header.
- [ ] (Polish) ANSI color in `ConsoleWriter` when `STDOUT` is a TTY.

---

### 📦 Feature 8 (Stretch): Tests & Docs

**Description:**
A handful of plain-PHP assertion tests prove the design works and the DIP
abstraction pays off. Plus a `README.md` for the project submission.

### 📖 User Story 1: Run tests without a framework

**As a** maintainer, **I want** plain-PHP test scripts under `tests/`, **so
that** the project stays dependency-free and still has a safety net.

**Acceptance Criteria:**

- [ ] `php tests/run.php` exits `0` when all assertions pass.
- [ ] `Username`, `EventHydrator`, and `EventFormatter` are each covered.
- [ ] `InMemoryHttpClient` exists and is used by hydrator/API-client tests.

**Tasks:**

- [ ] Write `Http\InMemoryHttpClient` (returns canned `HttpResponse`s).
- [ ] Add fixture JSON files under `tests/fixtures/`.
- [ ] Write a tiny `tests/run.php` runner (assert + summary).
- [ ] Author `README.md`: install, usage, example output, exit-code table.
- [ ] Capture a screenshot / asciinema cast for the roadmap.sh submission.

---

## 9. Build Order (small, verifiable steps)

1. Skeleton: folders, `src/autoload.php`, empty entry script that prints `argv[1]`.
2. `Username` VO + `ArgumentParser` + tiny CLI test.
3. `HttpResponse`, `HttpClientInterface`, `CurlHttpClient`. Smoke-test against
   `https://api.github.com`.
4. `EventType` enum + `Event` DTO + `EventHydrator`.
5. `GitHubApiClient` with status-code mapping.
6. `EventFormatter` + `ActivityRenderer` + `ConsoleWriter`.
7. Wire `Application`. Run end-to-end against a real username.
8. Exception hierarchy + exit codes.
9. Polish: README, `--help` flag, optional `--type=push` filter.

Each step ends with the program runnable.

---

## 10. Definition of Done

- [ ] **Does it work as the user expects?**
      `php bin/github-activity kamranahmedse` prints a clean bulleted list of recent activity.
- [ ] **Is invalid input handled?**
      Missing arg, bad username, unknown user, rate limit, offline — each
      produces a one-line message and a distinct exit code (`0`, `1`, `2`, `3`, `4`, `64`).
- [ ] **Is this Production-ready?**
      No file outside `src/`, `bin/`, `tests/` needed to run; no class > ~60 lines /
      method > ~20; adding a new GitHub event type touches only `EventType.php` +
      `EventFormatter.php`; no raw stack traces leak; PHP 8.5 features used
      intentionally, not decoratively.

---

## 11. Pre-flight Checklist

Run through this once before writing the first line of code — it answers the
"is this blueprint really ready to implement?" question.

### Environment
- [ ] PHP **8.5+** installed and on `PATH` (`php -v` reports 8.5.x).
- [ ] `php -m` lists **`curl`** and **`json`** (both ship by default; verify anyway).
- [ ] Project root is `f:\Laragon-Program\laragon\www\GitHub User Activity\`.

### Decisions already locked in (don't re-debate during build)
- [ ] **Namespace root:** `App\` → `src/` (PSR-4-style, no Composer).
- [ ] **HTTP transport:** `ext-curl` only — no `file_get_contents` fallback.
- [ ] **Auth:** anonymous by default; `GITHUB_TOKEN` env var is *stretch* only.
- [ ] **Output format:** plain text bullets (`- …`), one event per line.
- [ ] **No emoji, no color in core**; ANSI color is stretch (`Feature 7`).
- [ ] **Event coverage:** the 10 types listed in §6.6, plus `Other` fallback.

### File-creation order (matches §9 Build Order)
1. [ ] `src/autoload.php` + `bin/github-activity` skeleton (prints `argv[1]`).
2. [ ] `src/Domain/Username.php` + `src/Cli/ArgumentParser.php`.
3. [ ] `src/Http/{HttpResponse,HttpClientInterface,CurlHttpClient}.php`.
4. [ ] `src/Domain/{EventType,Event}.php` + `src/Github/EventHydrator.php`.
5. [ ] `src/Exception/*.php` (4 files).
6. [ ] `src/Github/GitHubApiClient.php`.
7. [ ] `src/Formatting/{EventFormatterInterface,EventFormatter,ActivityRenderer}.php`.
8. [ ] `src/Cli/ConsoleWriter.php`.
9. [ ] `src/Application.php` — wire everything.
10. [ ] End-to-end smoke test: `php bin/github-activity kamranahmedse`.

### Smoke tests to run after step 9
- [ ] `php bin/github-activity kamranahmedse` → bulleted list, exit `0`.
- [ ] `php bin/github-activity` → usage line on STDERR, exit `64`.
- [ ] `php bin/github-activity "bad name!"` → invalid username, exit `64`.
- [ ] `php bin/github-activity definitely-not-a-real-user-zzz999` → not found, exit `2`.
- [ ] (Offline) `php bin/github-activity kamranahmedse` → network error, exit `4`.

### Status
**Blueprint state: ✅ Ready for implementation.**
Every class has a name, a responsibility, a file path, and a sketch.
Every failure mode has an exception, an exit code, and a message.
Start at step 1 of §9 and tick boxes as you go.
